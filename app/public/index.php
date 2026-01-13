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
    header('Access-Control-Allow-Credentials: true');

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
    $router->get('/properties/(\d+)/features', 'PropertiesController@propertyFeaturesOverview');
    $router->post('/properties/(\d+)/features/(\d+)', 'PropertiesController@postFeatureToProperty');
    $router->delete('/properties/(\d+)/features/(\d+)', 'PropertiesController@deleteFeatureFromProperty');
});

// Auctions routes
$router->mount('/api', function() use ($router) {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');

    // Auctions
    $router->get('/auctions', 'AuctionController@overview');
    $router->post('/auctions', 'AuctionController@post');
    $router->get('/auctions/(\d+)', 'AuctionController@getByID');
    $router->put('/auctions/(\d+)', 'AuctionController@putByID');
    $router->delete('/auctions/(\d+)', 'AuctionController@deleteByID');

    // Auction status filters
    $router->get('/auctions/status/active', 'AuctionController@getActiveAuctions');
    $router->get('/auctions/status/upcoming', 'AuctionController@getUpcomingAuctions');
    $router->get('/auctions/status/ended', 'AuctionController@getEndedAuctions');

    // Property auctions
    $router->get('/properties/(\d+)/auctions', 'AuctionController@getAuctionsByProperty');

    // Bids
    $router->get('/auctions/(\d+)/bids', 'AuctionController@getAllBids');
    $router->post('/auctions/(\d+)/bids', 'AuctionController@postBid');
    $router->delete('/auctions/(\d+)/bids/(\d+)', 'AuctionController@deleteBid');
    $router->delete('/auctions/(\d+)/bids', 'AuctionController@deleteAllBids');
});

// Authentication routes
$router->mount('/auth', function() use ($router) {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
    
    // Public authentication routes
    $router->post('/register', 'AuthController@register');
    $router->post('/login', 'AuthController@login');
    
    // Protected account management routes
    $router->post('/logout', 'AuthController@logout');
    $router->get('/account', 'AuthController@account');
    $router->put('/account', 'AuthController@updateProfile');
    $router->post('/refresh', 'AuthController@refreshToken');
});

// Users routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');

    $router->get('/users', 'UserController@overview');
    $router->post('/users', 'UserController@post');
    $router->get('/users/(\d+)', 'UserController@getByID');
    $router->put('/users/(\d+)', 'UserController@putByID');
    $router->delete('/users/(\d+)', 'UserController@deleteByID');
});

// Agencies routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');

    $router->get('/agencies', 'AgenciesController@overview');
    $router->post('/agencies', 'AgenciesController@post');
    $router->get('/agencies/(\d+)', 'AgenciesController@getByID');
    $router->put('/agencies/(\d+)', 'AgenciesController@putByID');
    $router->delete('/agencies/(\d+)', 'AgenciesController@deleteByID');

    $router->post('/agencies/(\d+)/logo', 'AgenciesController@postLogo');
    $router->delete('/agencies/(\d+)/logo', 'AgenciesController@deleteLogo');

    $router->get('/agencies/(\d+)/reviews', 'AgenciesController@reviewsOverview');
    $router->post('/agencies/(\d+)/reviews', 'AgenciesController@postReview');
    $router->delete('/agencies/(\d+)/reviews/(\d+)', 'AgenciesController@deleteReview');

    // Agency properties
    $router->get('/agencies/(\d+)/properties', 'PropertiesController@getPropertiesByAgency');
});

// Employees routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');

    $router->get('/employees/(\d+)', 'EmployeesController@overview');
    $router->post('/employees/(\d+)', 'EmployeesController@post');
    $router->delete('/employees/(\d+)', 'EmployeesController@deleteByID');
});

// Agency Employees routes
$router->mount('/api', function() use ($router) {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');

    // Agency Employees
    $router->get('/agencies/(\d+)/employees', 'EmployeesController@overview');
    $router->post('/agencies/(\d+)/employees', 'EmployeesController@post');
    $router->get('/agencies/(\d+)/employees/(\d+)', 'EmployeesController@getByID');
    $router->delete('/agencies/(\d+)/employees/(\d+)', 'EmployeesController@deleteByID');
    $router->delete('/agencies/(\d+)/employees', 'EmployeesController@deleteAll');

    // Employee utilities
    $router->get('/employees/(\d+)/agencies', 'EmployeesController@getAgenciesByEmployee');
});

// CORS: Preflight request handler for authentication routes
$router->options('/auth/.*', function() {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Methods: POST, GET, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
});

// CORS: Preflight request handler for API routes
$router->options('/api/.*', function() {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
});

$router->run();