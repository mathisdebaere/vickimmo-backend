<?php

require_once ('../vendor/autoload.php');
$router = new \Bramus\Router\Router();
$router->setNamespace('\Http');

// add your routes and run!
$router->get('/', 'MyController@welcome');

$router->run();