<?php

require_once ('../vendor/autoload.php');

// load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ALLOW_ORIGIN']);

// create router
$router = new \Bramus\Router\Router();
$router->setNamespace('\Http');

// Properties routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);

    // Properties
    $router->get('/properties', 'PropertiesController@overview');
    $router->post('/properties', 'PropertiesController@post');
    $router->get('/properties/(\d+)', 'PropertiesController@getByID');
    $router->put('/properties/(\d+)', 'PropertiesController@putByID');
    $router->delete('/properties/(\d+)', 'PropertiesController@deleteByID');

    // Images
    $router->get('/properties/(\d+)/images', 'PropertiesController@getAllImg');
    $router->post('/properties/(\d+)/images', 'PropertiesController@postImg');
    $router->delete('/properties/(\d+)/images/(\d+)', 'PropertiesController@deleteImg');
    $router->delete('/properties/(\d+)/images', 'PropertiesController@deleteAllImg');

    // Features
    $router->get('/properties/features', 'PropertiesController@featuresOverview');
    $router->post('/properties/features', 'PropertiesController@postFeature');
    $router->delete('/properties/features/(\d+)', 'PropertiesController@deleteFeature');
    $router->get('/properties/(\d+)/features', 'PropertiesController@propertyFeaturesOverview');
    $router->post('/properties/(\d+)/features/(\d+)', 'PropertiesController@postFeatureToProperty');
    $router->delete('/properties/(\d+)/features/(\d+)', 'PropertiesController@deleteFeatureFromProperty');
    $router->delete('/properties/(\d+)/features', 'PropertiesController@deleteAllFeaturesFromProperty');
});

// Authentication routes
$router->mount('/auth', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);

    $router->post('/register', 'AuthController@register');
    $router->post('/login', 'AuthController@login');
    $router->post('/logout', 'AuthController@logout');
});

// Users routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);

    $router->get('/users', 'UsersController@overview');
    $router->post('/users', 'UsersController@post');
    $router->get('/users/(\d+)', 'UsersController@getByID');
    $router->put('/users/(\d+)', 'UsersController@putByID');
    $router->delete('/users/(\d+)', 'UsersController@deleteByID');
});

// Agencies routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);

    $router->get('/agencies', 'AgenciesController@overview');
    $router->post('/agencies', 'AgenciesController@post');
    $router->get('/agencies/(\d+)', 'AgenciesController@getByID');
    $router->put('/agencies/(\d+)', 'AgenciesController@putByID');
    $router->delete('/agencies/(\d+)', 'AgenciesController@deleteByID');

    $router->post('/agencies/(\d+)/logo', 'AgenciesController@postLogo');
    $router->delete('/agencies/(\d+)/logo', 'AgenciesController@deleteLogo');

    $router->get('/agencies/(\d+)/reviews', 'AgenciesController@reviewsOverview');
    $router->post('/agencies/(\d+)/reviews', 'AgenciesController@postReview');
    $router->put('/agencies/(\d+)/reviews/(\d+)', 'AgenciesController@putReview');
    $router->delete('/agencies/(\d+)/reviews/(\d+)', 'AgenciesController@deleteReview');
});

// Employees routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);

    $router->get('/employees/(\d+)', 'EmployeesController@overview');
    $router->post('/employees/(\d+)', 'EmployeesController@post');
    $router->delete('/employees/(\d+)', 'EmployeesController@deleteByID');
});

// CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
$router->before('GET|POST|PUT|DELETE', '/api/properties(/\d+)?', function() {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
});

// CORS: support for preflight requests (just an example)
$router->options('/api/products(/\d+)?', function() {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
});

$router->run();