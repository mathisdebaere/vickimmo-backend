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
    $router->delete('/properties/features/(\d+)', 'PropertiesController@deleteFeature');
    $router->get('/properties/(\d+)/features', 'PropertiesController@propertyFeaturesOverview');
    $router->post('/properties/(\d+)/features/(\d+)', 'PropertiesController@postFeatureToProperty');
    $router->delete('/properties/(\d+)/features/(\d+)', 'PropertiesController@deleteFeatureFromProperty');
    $router->delete('/properties/(\d+)/features', 'PropertiesController@deleteAllFeaturesFromProperty');
});

// Auctions routes
$router->mount('/api', function() use ($router) {
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');

    // Auctions
    $router->get('/auctions', 'AuctionsController@overview');
    $router->post('/auctions', 'AuctionsController@post');
    $router->get('/auctions/(\d+)', 'AuctionsController@getByID');
    $router->put('/auctions/(\d+)', 'AuctionsController@putByID');
    $router->delete('/auctions/(\d+)', 'AuctionsController@deleteByID');

    // Auction status filters
    $router->get('/auctions/status/active', 'AuctionsController@getActiveAuctions');
    $router->get('/auctions/status/upcoming', 'AuctionsController@getUpcomingAuctions');
    $router->get('/auctions/status/ended', 'AuctionsController@getEndedAuctions');

    // Property auctions
    $router->get('/properties/(\d+)/auctions', 'AuctionsController@getAuctionsByProperty');

    // Bids
    $router->get('/auctions/(\d+)/bids', 'AuctionsController@getAllBids');
    $router->post('/auctions/(\d+)/bids', 'AuctionsController@postBid');
    $router->delete('/auctions/(\d+)/bids/(\d+)', 'AuctionsController@deleteBid');
    $router->delete('/auctions/(\d+)/bids', 'AuctionsController@deleteAllBids');
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
    $router->put('/account/password', 'AuthController@changePassword');
    $router->post('/refresh', 'AuthController@refreshToken');
});

// Users routes
$router->mount('/api', function() use ($router) {
    // CORS: API response can be shared with javascript code from origin ALLOW_ORIGIN
    header('Access-Control-Allow-Origin: ' . $_ENV['ALLOW_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');

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
    $router->put('/agencies/(\d+)/reviews/(\d+)', 'AgenciesController@putReview');
    $router->delete('/agencies/(\d+)/reviews/(\d+)', 'AgenciesController@deleteReview');
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
    $router->get('/agencies/(\d+)/employees/count', 'EmployeesController@getEmployeeCount');
    $router->get('/agencies/(\d+)/employees/(\d+)/check', 'EmployeesController@checkEmployeeInAgency');
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