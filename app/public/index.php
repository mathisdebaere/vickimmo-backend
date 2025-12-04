<?php

require_once ('../vendor/autoload.php');

// load .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'BASE_PATH']);

// create router
$router = new \Bramus\Router\Router();
$router->setNamespace('\Http');

// add your routes and run!
$router->get('/', 'MyController@overview');

$router->run();