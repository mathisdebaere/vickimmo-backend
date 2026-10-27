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

    protected function getBearerToken(): ?string
    {
        $headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
        $authHeader = $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
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

    protected function userExists(int $id): bool
    {
        return $this->entityExists('users', $id);
    }

    protected function agencyExists(int $id): bool
    {
        return $this->entityExists('agencies', $id);
    }

    protected function propertyExists(int $id): bool
    {
        return $this->entityExists('properties', $id);
    }

    protected function getLastInsertId(): int
    {
        return (int)$this->db->lastInsertId();
    }

    protected function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function validatePhone(string $phone): bool
    {
        return preg_match('/^\d{10,15}$/', $phone) === 1;
    }

    protected function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    protected function emailExists(string $email): bool
    {
        return (bool)$this->db->fetchOne(
            "SELECT COUNT(*) FROM users WHERE email = ?",
            [$email]
        );
    }
}
