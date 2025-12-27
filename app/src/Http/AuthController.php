<?php

namespace Http;
use \Services\ApiResponse;
use \Services\DatabaseConnector;
use \Services\JWTService;

class AuthController
{
    protected \Doctrine\DBAL\Connection $db;

    public function __construct()
    {
        // initiate DB connection
        $this->db = DatabaseConnector::getConnection();

    }

    public function methodNotAllowed() {
        ApiResponse::error('HTTP request method ' .  $_SERVER['REQUEST_METHOD']. ' not allowed.', 405);
    }

    public function register()
    {
        
    }

    public function login() : void
    {
        
    }

    public function logout() : void
    {
        
    }
}
