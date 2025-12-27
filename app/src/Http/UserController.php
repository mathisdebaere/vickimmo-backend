<?php

namespace Http;
use \Services\ApiResponse;
use \Services\DatabaseConnector;

class UsersController
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

    public function overview()
    {
        $userRows = $this->db->fetchAllAssociative('SELECT * FROM users', []);
        ApiResponse::success($userRows);
    }

    public function post()
    {

    }

    public function getByID($id)
    {
        
    }

    public function putByID($id)
    {

    }

    public function deleteByID($id)
    {

    }
}