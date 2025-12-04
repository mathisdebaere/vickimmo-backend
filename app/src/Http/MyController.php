<?php

namespace Http;
use \Services\ApiResponse;
use \Services\DatabaseConnector;

class MyController
{
    protected \Doctrine\DBAL\Connection $db;

    public function __construct()
    {
        // initiate DB connection
        $this->db = DatabaseConnector::getConnection();

    }

    public function overview()
    {
        $productRows = $this->db->fetchAllAssociative('SELECT * FROM products', []);
        ApiResponse::success($productRows);
    }
}