<?php

namespace Http;

use \Services\ApiResponse;
use \Services\DatabaseConnector;

abstract class BaseController
{
    protected \Doctrine\DBAL\Connection $db;

    public function __construct()
    {
        $this->db = DatabaseConnector::getConnection();
    }

    public function methodNotAllowed(): void
    {
        ApiResponse::error('HTTP request method ' . $_SERVER['REQUEST_METHOD'] . ' not allowed.', 405);
    }

    protected function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error('Invalid JSON payload.', 422);
            return null;
        }

        return $data;
    }

    protected function entityExists(string $table, int $id): bool
    {
        return (bool)$this->db->fetchOne("SELECT COUNT(*) FROM $table WHERE id = ?", [$id]);
    }
}
