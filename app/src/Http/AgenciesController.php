<?php

namespace Http;
use \Services\ApiResponse;
use \Services\DatabaseConnector;

class AgenciesController
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
        $propertyRows = $this->db->fetchAllAssociative('SELECT * FROM properties', []);
        ApiResponse::success($propertyRows);
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

    public function reviewsOverview($agencyID)
    {
        
    }
    
    public function postReview($agencyID)
    {

    }

    public function deleteReviewByID($agencyID, $reviewID)
    {

    }
}