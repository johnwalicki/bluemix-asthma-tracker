<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

// autoload files
require '../vendor/autoload.php';
require '../config.php';

// if VCAP_SERVICES environment available
// overwrite local credentials with environment credentials
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $config['settings']['db']['uri'] = $services_json['cloudantNoSQLDB'][0]['credentials']['url'];
} 

// configure Slim application instance
// initialize application
$app = new \Slim\App($config);

// initialize dependency injection container
$container = $app->getContainer();

// add view renderer to DI container
$container['view'] = function ($container) {
  $view = new \Slim\Views\Twig("../views/");
  $router = $container->get('router');
  $uri = \Slim\Http\Uri::createFromEnvironment(new \Slim\Http\Environment($_SERVER));
  $view->addExtension(new \Slim\Views\TwigExtension($router, $uri));  
  return $view;
};

// configure and add Cloudant client to DI container
$container['cloudant'] = function ($container) use ($config) {
  return new Client([
    'base_uri' => $config['settings']['db']['uri'] . '/',
    'timeout'  => 6000,
    'verify' => false,    // set to true in production
  ]);
};

// configure and add Weather Company API client to DI container
$container['twc'] = function ($container) use ($config) {
  return new Client([
    'base_uri' => $config['settings']['twc']['uri'] . '/api/weather/v1/',
    'timeout'  => 6000,
    'verify' => false,    // set to true in production
  ]);
};

// welcome page controller
$app->get('/', function (Request $request, Response $response) {
  return $response->withHeader('Location', $this->router->pathFor('home'));
});

$app->get('/home', function (Request $request, Response $response) {
  $response = $this->view->render($response, 'home.twig', [
    'router' => $this->router
  ]);
  return $response;
})->setName('home');

// list page controller
$app->get('/list', function (Request $request, Response $response) {
  $config = $this->get('settings');
  
  // get all docs in database
  // include all content
  $dbResponse = $this->cloudant->get($config['db']['name'] . '/_all_docs', [
    'query' => ['include_docs' => 'true', 'descending' => 'true'] 
  ]);
  if($response->getStatusCode() == 200) {
    $json = (string)$dbResponse->getBody();
    $body = json_decode($json);
  }
  // provide results to template
  $response = $this->view->render($response, 'list.twig', [
    'router' => $this->router, 'results' => $body->rows
  ]);
  return $response;
})->setName('list');

// scatter chart controller
$app->get('/scatter/{type:temperature|humidity}', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  
  // get all docs in database
  // include all content
  $dbResponse = $this->cloudant->get($config['db']['name'] . '/_all_docs', [
    'query' => ['include_docs' => 'true'] 
  ]);
  if($response->getStatusCode() == 200) {
    $json = (string)$dbResponse->getBody();
    $body = json_decode($json);
  }
  
  // provide results to template
  // specify metric to use depending on type of chart requested
  $response = $this->view->render($response, 'scatter.twig', [
    'router' => $this->router, 'results' => $body->rows, 
    'metric' => ($args['type'] == 'temperature') ? ['temp', 'Temperature (C)'] : ['rh', 'Humidity (%)']
  ]);
  return $response;
})->setName('scatter');

// bar chart controller
$app->get('/bar', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  
  // get all docs in database
  // include all content
  $dbResponse = $this->cloudant->get($config['db']['name'] . '/_all_docs', [
    'query' => ['include_docs' => 'true'] 
  ]);
  if($response->getStatusCode() == 200) {
    $json = (string)$dbResponse->getBody();
    $body = json_decode($json);
  }
  
  // iterate over observations
  // build a monthly histogram
  foreach ($body->rows as $row) {
    $month = date('m', $row->doc->ts);
    $value = $row->doc->value;
    $observations[$month][] = $value;
  }
  
  // iterate over collected observations
  // calculate monthly average
  foreach ($observations as $key => $value) {
    $sum = array_sum($value);
    $average = round($sum / count($value));
    $results[$key] = $average;
  }
    
  // provide results to template
  $response = $this->view->render($response, 'bar.twig', [
    'router' => $this->router, 'results' => $results
  ]);
  return $response;
})->setName('bar');

// deletion handler
$app->get('/delete/{id}/{rev}', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  
  // get document ID and revision ID in Cloudant
  $id = $args['id'];
  $rev = $args['rev'];
  
  // delete document from database using Cloudant API
  $this->cloudant->delete($config['db']['name'] . '/' . $id, [ 
    "query" => ["rev" => $rev] 
  ]);
  
  return $response->withHeader('Location', $this->router->pathFor('list'));
})->setName('delete');

// addition handlers
// display form
$app->get('/save', function (Request $request, Response $response) {
  $response = $this->view->render($response, 'save.twig', [
    'router' => $this->router, 'method' => $request->getMethod()
  ]);
  return $response;
})->setName('save');

// process form
$app->post('/save', function (Request $request, Response $response, $args) {
  $config = $this->get('settings');
  $params = $request->getParams();
  
  // validate input
  $value = filter_var($params['value'], FILTER_SANITIZE_NUMBER_INT);
  if (!(filter_var($value, FILTER_VALIDATE_INT))) {
    throw new Exception('ERROR: Value is not valid');
  }
  $note = filter_var($params['note'], FILTER_SANITIZE_STRING);
  
  // request weather data from TWC API
  // extract temperature and humidity from response
  $twcResponse = $this->twc->get('geocode/' . $config['twc']['latitude'] . '/' . $config['twc']['longitude'] . '/observations.json?units=m');
  $twcData = json_decode($twcResponse->getBody()); 
  $temp = $twcData->observation->temp;
  $rh = $twcData->observation->rh;

  // create document with reading, time and weather data
  $doc = [
    'value' => $value,
    'note' => $note,
    'temp' => $temp,
    'rh' => $rh,
    'ts' => mktime()
  ];
  
  // save document to Cloudant
  $cloudantResponse = $this->cloudant->post($config['db']['name'], [
    'json' => $doc
  ]);
  if ($cloudantResponse->getStatusCode() == 201) {
    return $response->withHeader('Location', $this->router->pathFor('list'));
  } else {
    throw new Exception('ERROR: Document could not be created');
  }
})->setName('save');

// legal page handler
$app->get('/legal', function (Request $request, Response $response) {
  $response = $this->view->render($response, 'legal.twig', [
    'router' => $this->router
  ]);
  return $response;
})->setName('legal');

$app->run();