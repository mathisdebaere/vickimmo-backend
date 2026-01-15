<?php

namespace Http;

use \Services\ApiResponse;
use \Services\DatabaseConnector;

class FavouritesController
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

    // Get all favourites for a user
    public function overview(int $userID): void
    {
        if (!$this->userExists($userID)) {
            ApiResponse::error('User not found.', 404);
            return;
        }

        $favourites = $this->db->fetchAllAssociative(
            'SELECT f.id, f.created_at,
                    p.id AS property_id, 
                    p.title, 
                    p.price, 
                    p.city, 
                    p.type, 
                    p.status,
                    p.living_area,
                    p.bedrooms,
                    a.name AS agency_name,
                    a.logo AS agency_logo,
                    (SELECT pi.image_path 
                     FROM property_images pi 
                     WHERE pi.property_id = p.id AND pi.is_main = 1 
                     LIMIT 1) AS main_image_path
            FROM favourites f
            INNER JOIN properties p ON p.id = f.property_id
            LEFT JOIN agencies a ON a.id = p.agency_id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC',
            [$userID]
        );

        // Add full image URLs
        foreach ($favourites as &$favourite) {
            if (!empty($favourite['main_image_path'])) {
                $favourite['main_image_url'] = 
                    '/uploads/properties/' . $favourite['main_image_path'];
            } else {
                $favourite['main_image_url'] = null;
            }
            unset($favourite['main_image_path']);
            
            if (!empty($favourite['agency_logo'])) {
                $favourite['agency_logo'] = 
                    '/uploads/agencies/' . $favourite['agency_logo'];
            } else {
                $favourite['agency_logo'] = null;
            }
        }

        ApiResponse::success([
            'user_id' => $userID,
            'count' => count($favourites),
            'favourites' => $favourites
        ]);
    }

    // Add property to favourites
    public function post(int $userID, int $propertyID): void
    {
        if (!$this->userExists($userID)) {
            ApiResponse::error('User not found.', 404);
            return;
        }

        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        if ($this->favouriteExists($userID, $propertyID)) {
            ApiResponse::error(
                'Property is already in favourites.', 
                409
            );
            return;
        }

        $this->db->executeStatement(
            'INSERT INTO favourites (user_id, property_id) VALUES (?, ?)',
            [$userID, $propertyID]
        );

        $id = $this->getLastInsertId();

        ApiResponse::success([
            'message' => 'Property added to favourites successfully.',
            'favourite_id' => $id,
            'user_id' => $userID,
            'property_id' => $propertyID
        ], status: 201);
    }

    // Check if property is in user's favourites
    public function check(int $userID, int $propertyID): void
    {
        $exists = $this->favouriteExists($userID, $propertyID);

        ApiResponse::success([
            'user_id' => $userID,
            'property_id' => $propertyID,
            'is_favourite' => $exists
        ]);
    }

    // Remove property from favourites
    public function delete(int $userID, int $propertyID): void
    {
        if (!$this->favouriteExists($userID, $propertyID)) {
            ApiResponse::error(
                'Property not found in favourites.', 
                404
            );
            return;
        }

        $this->db->executeStatement(
            'DELETE FROM favourites WHERE user_id = ? AND property_id = ?',
            [$userID, $propertyID]
        );

        ApiResponse::success([
            'message' => 'Property removed from favourites successfully.',
            'user_id' => $userID,
            'property_id' => $propertyID
        ]);
    }

    // Delete all favourites for a user
    public function deleteAll(int $userID): void
    {
        if (!$this->userExists($userID)) {
            ApiResponse::error('User not found.', 404);
            return;
        }

        $count = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM favourites WHERE user_id = ?',
            [$userID]
        );

        $this->db->executeStatement(
            'DELETE FROM favourites WHERE user_id = ?',
            [$userID]
        );

        ApiResponse::success([
            'message' => "Deleted {$count} favourite(s) successfully.",
            'user_id' => $userID,
            'deleted_count' => $count
        ]);
    }

    // PRIVATE HELPER METHODS

    private function entityExists(string $table, int $id): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT id FROM ' . $table . ' WHERE id = ?',
            [$id]
        );
        
        return $result !== false;
    }

    private function userExists(int $userID): bool
    {
        return $this->entityExists('users', $userID);
    }

    private function propertyExists(int $propertyID): bool
    {
        return $this->entityExists('properties', $propertyID);
    }

    private function favouriteExists(
        int $userID, 
        int $propertyID
    ): bool {
        $result = $this->db->fetchAssociative(
            'SELECT id FROM favourites 
            WHERE user_id = ? AND property_id = ?',
            [$userID, $propertyID]
        );
        
        return $result !== false;
    }

    private function getLastInsertId(): int
    {
        return (int)$this->db->lastInsertId();
    }
}