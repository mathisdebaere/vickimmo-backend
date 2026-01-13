<?php

namespace Http;

use \Services\ApiResponse;

class UsersController extends BaseController
{
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