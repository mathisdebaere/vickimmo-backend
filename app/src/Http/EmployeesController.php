<?php

namespace Http;
use \Services\ApiResponse;
use \Services\DatabaseConnector;

class EmployeesController
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

    public function overview($agencyID)
    {
       
    }

    public function post($agencyID)
    {

    }

    public function deleteByID($agencyID, $employeeID)
    {

    }

}