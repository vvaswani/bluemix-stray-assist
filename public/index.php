<?php
// use Composer autoloader
require '../vendor/autoload.php';

// load configuration
require '../config.php';

// load classes
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7;
use Silex\Application;

// initialize Silex application
$app = new Application();

// turn on application debugging
// set to false for production environments
$app['debug'] = true;

// load configuration from file
$app->config = $config;

// register Twig template provider
$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/../views',
));

// register validator service provider
$app->register(new Silex\Provider\ValidatorServiceProvider());

// register session service provider
$app->register(new Silex\Provider\SessionServiceProvider());

// if BlueMix VCAP_SERVICES environment available
// overwrite local credentials with BlueMix credentials
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $app->config['settings']['db']['uri'] = $services_json['cloudantNoSQLDB'][0]['credentials']['url'];
  $app->config['settings']['object-storage']['url'] = $services_json["Object-Storage"][0]["credentials"]["auth_url"] . '/v3';
  $app->config['settings']['object-storage']['region'] = $services_json["Object-Storage"][0]["credentials"]["region"];
  $app->config['settings']['object-storage']['user'] = $services_json["Object-Storage"][0]["credentials"]["userId"];
  $app->config['settings']['object-storage']['pass'] = $services_json["Object-Storage"][0]["credentials"]["password"];  
} 

// initialize HTTP client
$guzzle = new GuzzleHttp\Client([
  //'verify' => false,
  'base_uri' => $app->config['settings']['db']['uri'] . '/',
]);

// initialize OpenStack client
$openstack = new OpenStack\OpenStack(array(
  'authUrl' => $app->config['settings']['object-storage']['url'],
  'region'  => $app->config['settings']['object-storage']['region'],
  'user'    => array(
    'id'       => $app->config['settings']['object-storage']['user'],
    'password' => $app->config['settings']['object-storage']['pass']
)));
$objectstore = $openstack->objectStoreV1();

// index page handlers
$app->get('/', function () use ($app) {
  return $app->redirect($app["url_generator"]->generate('index'));
});

$app->get('/index', function () use ($app) {
  return $app['twig']->render('index.twig', array());
})
->bind('index');

// report submission form
// get lat/long from browser and set as hidden form fields
$app->get('/report', function (Request $request) use ($app) {
  $latitude = $request->get('latitude');
  $longitude = $request->get('longitude');
  return $app['twig']->render('report.twig', array('latitude' => $latitude, 'longitude' => $longitude));
})
->bind('report');

// report submission handler
$app->post('/report', function (Request $request) use ($app, $guzzle, $objectstore) {
  // collect input parameters
  $params = array(
    'color' => strip_tags(trim(strtolower($request->get('color')))),
    'gender' => strip_tags(trim($request->get('gender'))),
    'age' => strip_tags(trim($request->get('age'))),
    'identifiers' => strip_tags(trim($request->get('identifiers'))),
    'description' => strip_tags(trim($request->get('description'))),
    'email' => strip_tags(trim($request->get('email'))),
    'name' => strip_tags(trim($request->get('name'))),
    'phone' => (int)strip_tags(trim($request->get('phone'))),
    'latitude' => (float)strip_tags(trim($request->get('latitude'))),
    'longitude' => (float)strip_tags(trim($request->get('longitude'))),
    'upload' => $request->files->get('upload')
  );
  
  // define validation constraints
  $constraints = new Assert\Collection(array(
    'color' => new Assert\NotBlank(array('groups' => 'report')),
    'gender' => new Assert\Choice(array('choices' => array('male', 'female', 'unknown'), 'groups' => 'report')),
    'age' => new Assert\Choice(array('choices' => array('pup', 'adult', 'unknown'), 'groups' => 'report')),
    'description' => new Assert\NotBlank(array('groups' => 'report')),
    'email' =>  new Assert\Email(array('groups' => 'report')),
    'name' => new Assert\NotBlank(array('groups' => 'report')),
    'phone' => new Assert\Type(array('type' => 'numeric', 'groups' => 'report')),
    'latitude' => new Assert\Type(array('type' => 'float', 'groups' => 'report')),
    'longitude' => new Assert\Type(array('type' => 'float', 'groups' => 'report')),
    'identifiers' => new Assert\Type(array('type' => 'string', 'groups' => 'report')),
    'upload' => new Assert\Image(array('groups' => 'report'))
  ));
  
  // validate input and set errors if any as flash messages
  // if errors, redirect to input form
  $errors = $app['validator']->validate($params, $constraints, array('report'));
  if (count($errors) > 0) {
    foreach ($errors as $error) {
      $app['session']->getFlashBag()->add('error', 'Invalid input in field ' . $error->getPropertyPath());
    }
    return $app->redirect($app["url_generator"]->generate('report'));
  }  
  
  // if input passes validation
  // produce JSON document with input values
  $doc = [
    'type' => 'report',
    'latitude' => $params['latitude'],
    'longitude' => $params['longitude'],
    'color' => $params['color'],
    'gender' => $params['gender'],
    'age' => $params['age'],
    'identifiers' => $params['identifiers'],
    'description' => $params['description'],
    'name' => $params['name'],
    'phone' => $params['phone'],
    'email' => $params['email'],
    'file' => !is_null($params['upload']) ? trim($params['upload']->getClientOriginalName()) : '',
    'datetime' => time()
  ];
  
  // save document to database
  // retrieve unique document identifier
  $response = $guzzle->post($app->config['settings']['db']['name'], [ 'json' => $doc ]);
  $result = json_decode($response->getBody()); 
  $id = $result->id;  
  
  // if report includes photo
  // create container in object storage service 
  // with name = document identifier
  // and upload photo to it
  if (!is_null($params['upload'])) {
    $container = $objectstore->createContainer(array(
      'name' => $id
    )); 
    $stream = new Stream(fopen($params['upload']->getRealPath(), 'r'));
    $options = array(
      'name'   => trim($params['upload']->getClientOriginalName()),
      'stream' => $stream,
    );
    $container->createObject($options);  
  }
  $app['session']->getFlashBag()->add('success', 'Report added.');
  return $app->redirect($app["url_generator"]->generate('index'));
});

// search form
$app->get('/search', function (Request $request) use ($app) {
  return $app['twig']->render('search.twig', array('results' => array()));
})
->bind('search');

// search submission handler
$app->post('/search', function (Request $request) use ($app, $guzzle) {
  // collect and sanitize inputs
  $color = strip_tags(trim($request->get('color')));
  $gender = strip_tags(trim($request->get('gender')));
  $age = strip_tags(trim($request->get('age')));
  $keywords = strip_tags(trim($request->get('keywords')));
  if (!empty($keywords)) {
    $keywords = explode(',', strip_tags($request->get('keywords')));
  }
  
  // generate query string based on inputs
  $criteria = array("(type:report)");
  if (!empty($color)) {
    $color = strtolower($color);
    $criteria[] = "(color:$color)";
  }
  if (!empty($gender)) {
    $criteria[] = "(gender:$gender)";
  }
  if (!empty($age)) {
    $criteria[] = "(age:$age)";
  }  
  if (is_array($keywords)) {
    foreach ($keywords as $keyword) {
      $keyword = trim($keyword);
      $criteria[] = "(identifiers:$keyword OR description:$keyword)";    
    }
  }
  $queryStr = implode(' AND ', $criteria);
  
  // execute query and decode JSON response
  // transfer result set to view
  $response = $guzzle->get($app->config['settings']['db']['name'] . '/_design/reports/_search/search?include_docs=true&sort="-datetime"&q='.urlencode($queryStr));
  $results = json_decode((string)$response->getBody());
  return $app['twig']->render('search.twig', array('results' => $results));
});

// report display
$app->get('/detail/{id}', function ($id) use ($app, $guzzle, $objectstore) {
  // retrieve selected report from database
  // using unique document identifier
  $response = $guzzle->get($app->config['settings']['db']['name'] . '/_all_docs?include_docs=true&key="'. $id . '"');
  $result = json_decode((string)$response->getBody());
  if (count($result->rows)) {
    $result = $result->rows[0];
    return $app['twig']->render('detail.twig', array('result' => $result));
  } else {
    $app['session']->getFlashBag()->add('error', 'Report could not be found.');
    return $app->redirect($app["url_generator"]->generate('index'));  
  }
})
->bind('detail');

// photo display
$app->get('/photo/{id}/{filename}', function ($id, $filename) use ($app, $guzzle, $objectstore) {
  // retrieve image file content from object store
  // using document identifier and filename
  // guess image MIME type from extension
  $file = $objectstore->getContainer($id)
                      ->getObject($filename)
                      ->download();
  $ext = pathinfo($filename, PATHINFO_EXTENSION);  
  $mimetype = GuzzleHttp\Psr7\mimetype_from_extension($ext);             
  
  // set response headers and body
  // send to client
  $response = new Response();
  $response->headers->set('Content-Type', $mimetype);
  $response->headers->set('Content-Length', $file->getSize());
  $response->headers->set('Expires', '@0');
  $response->headers->set('Cache-Control', 'must-revalidate');
  $response->headers->set('Pragma', 'public');
  $response->setContent($file);
  return $response;
})
->bind('photo');

// map display
$app->get('/map/{id}', function ($id) use ($app, $guzzle) {
  // retrieve selected report from database
  // using unique document identifier
  $response = $guzzle->get($app->config['settings']['db']['name'] . '/_all_docs?include_docs=true&key="'. $id . '"');
  $result = json_decode((string)$response->getBody());
  
  // obtain coordinates of report location
  // request map from Static Maps API using coordinates
  if (count($result->rows)) {
    $row = $result->rows[0];
    $latitude = $row->doc->latitude;
    $longitude = $row->doc->longitude;
    $mapUrl = 'https://maps.googleapis.com/maps/api/staticmap?key=' . $app->config['settings']['maps']['key'] . '&size=640x480&maptype=roadmap&scale=2&markers=color:green|' . sprintf('%f,%f', $latitude, $longitude);
    return $app['twig']->render('map.twig', array('mapUrl' => $mapUrl, 'result' => $row));
  } else {
    $app['session']->getFlashBag()->add('error', 'Map could not be generated.');
    return $app->redirect($app["url_generator"]->generate('index'));  
  }
})
->bind('map');

// legal page handler
$app->get('/legal', function (Request $request) use ($app) {
  return $app['twig']->render('legal.twig');
})
->bind('legal');

// reset handler
$app->get('/reset-system', function (Request $request) use ($app, $guzzle, $objectstore) {
  // retrieve all documents from database
  // delete all except design documents
  $response = $guzzle->get($app->config['settings']['db']['name'] . '/_all_docs?include_docs=true');
  $results = json_decode((string)$response->getBody());
  foreach ($results->rows as $row) {
    $id = $row->doc->_id;
    $doc[] = $id;
    $rev = $row->doc->_rev;
    if(substr($id, 0, 7) != '_design') {
      $guzzle->delete($app->config['settings']['db']['name'] . '/' . $id . '?rev=' . $rev);
    } 
  }
  
  // retrieve all containers from object store
  // iterate over list and delete all objects in each container
  // once empty, delete containers
  $containers = $objectstore->listContainers(); 
  foreach($containers as $c) {
    $container = $objectstore->getContainer($c->name);
    foreach ($container->listObjects() as $object) {
      $object->containerName = $c->name;
      $object->delete();
    }
    $c->delete();
  }
  return $app->redirect($app["url_generator"]->generate('index'));  
})
->bind('reset-system');

$app->run();
