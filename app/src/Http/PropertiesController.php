<?php

namespace Http;
use \Services\ApiResponse;
use \Services\DatabaseConnector;

class PropertiesController
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

    public function post(){
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error(['Invalid JSON payload.'], 422);
            return;
        }

        $errors = [];

        // Required fields
        $title = isset($data['title']) ? trim((string)$data['title']) : '';
        if ($title === '' || mb_strlen($title) < 3) {
            $errors[] = 'Title must be at least 3 characters.';
        }

        $description = isset($data['description']) ? trim((string)$data['description']) : '';
        if ($description === '') {
            $errors[] = 'Description is required.';
        }

        $agency_id = isset($data['agency_id']) ? (int)$data['agency_id'] : 0;
        if ($agency_id <= 0) {
            $errors[] = 'Valid agency_id is required.';
        }

        $address = isset($data['address']) ? trim((string)$data['address']) : '';
        if ($address === '') {
            $errors[] = 'Address is required.';
        }

        $postal = isset($data['postal']) ? trim((string)$data['postal']) : '';
        if ($postal === '') {
            $errors[] = 'Postal code is required.';
        }

        $city = isset($data['city']) ? trim((string)$data['city']) : '';
        if ($city === '') {
            $errors[] = 'City is required.';
        }

        $price = isset($data['price']) ? (float)$data['price'] : null;
        if ($price === null || $price < 0) {
            $errors[] = 'Valid price is required.';
        }

        $type = isset($data['type']) ? strtolower(trim((string)$data['type'])) : '';
        $validTypes = ['house', 'apartment', 'land', 'garage', 'business'];
        if (!in_array($type, $validTypes, true)) {
            $errors[] = 'Type must be one of: ' . implode(', ', $validTypes);
        }

        $status = isset($data['status']) ? strtolower(trim((string)$data['status'])) : '';
        $validStatuses = ['for sale', 'for rent', 'sold', 'rented'];
        if (!in_array($status, $validStatuses, true)) {
            $errors[] = 'Status must be one of: ' . implode(', ', $validStatuses);
        }

        $visibility = isset($data['visibility']) ? strtolower(trim((string)$data['visibility'])) : 'public';
        $validVisibilities = ['public', 'private', 'archived'];
        if (!in_array($visibility, $validVisibilities, true)) {
            $visibility = 'public';
        }

        if (!empty($errors)) {
            ApiResponse::error($errors, 422);
            return;
        }

        // Optional fields
        $build_year = isset($data['build_year']) ? (int)$data['build_year'] : null;
        $bedrooms = isset($data['bedrooms']) ? (int)$data['bedrooms'] : null;
        $bathrooms = isset($data['bathrooms']) ? (int)$data['bathrooms'] : null;
        $living_area = isset($data['living_area']) ? (float)$data['living_area'] : null;
        $surface_area = isset($data['surface_area']) ? (float)$data['surface_area'] : null;
        $sub_type = isset($data['sub_type']) ? trim((string)$data['sub_type']) : null;
        $auction = isset($data['auction']) ? (bool)$data['auction'] : 0;
        $epc_label = isset($data['epc_label']) ? strtoupper(trim((string)$data['epc_label'])) : null;
        $epc_value = isset($data['epc_value']) ? (int)$data['epc_value'] : null;
        $floors = isset($data['floors']) ? (int)$data['floors'] : null;
        $availability = isset($data['availability']) ? (bool)$data['availability'] : 1;

        $result = $this->db->executeStatement(
            'INSERT INTO properties 
            (title, description, agency_id, address, postal, city, price, build_year, bedrooms, bathrooms, living_area, surface_area, type, sub_type, status, visibility, auction, epc_label, epc_value, floors, availability) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$title, $description, $agency_id, $address, $postal, $city, $price, $build_year, $bedrooms, $bathrooms, $living_area, $surface_area, $type, $sub_type, $status, $visibility, $auction, $epc_label, $epc_value, $floors, $availability]
        );

        if ($result === 1) {
            try {
                $id = $this->db->lastInsertId();
            } catch (\Throwable $e) {
                $id = null;
            }
            $message = 'Property created successfully.' . ($id ? " ID: $id" : '');
            ApiResponse::success($message, status: 201);
        } else {
            ApiResponse::error('Could not create property.', 500);
        }
    }


    public function getByID($propertyID)
    {
        $propertyRow = $this->db->fetchAssociative('SELECT * FROM properties WHERE id = ?', [$propertyID]);
        if ($propertyRow === false) {
            ApiResponse::error('Property not found.', 404);
            return;
        }
        ApiResponse::success($propertyRow);
    }

    public function putByID($propertyID){
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error(['Invalid JSON payload.'], 422);
            return;
        }

        $existingProperty = $this->db->fetchAssociative('SELECT * FROM properties WHERE id = ?', [$propertyID]);
        if ($existingProperty === false) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $fieldsToUpdate = [];
        $params = [];

        $updatableFields = [
            'title', 'description', 'agency_id', 'address', 'postal', 'city', 'price',
            'build_year', 'bedrooms', 'bathrooms', 'living_area', 'surface_area', 'type',
            'sub_type', 'status', 'visibility', 'auction', 'epc_label', 'epc_value', 'floors', 'availability'
        ];

        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];

                // Example of casting / validation for enums
                if ($field === 'type') {
                    $validTypes = ['house', 'apartment', 'land', 'garage', 'business'];
                    if (!in_array(strtolower($value), $validTypes, true)) continue;
                }
                if ($field === 'status') {
                    $validStatuses = ['for sale', 'for rent', 'sold', 'rented'];
                    if (!in_array(strtolower($value), $validStatuses, true)) continue;
                }
                if ($field === 'visibility') {
                    $validVisibilities = ['public', 'private', 'archived'];
                    if (!in_array(strtolower($value), $validVisibilities, true)) continue;
                }

                // Cast boolean / numeric fields
                if (in_array($field, ['auction', 'availability'])) {
                    $value = (int)$value;
                }
                if (in_array($field, ['price', 'living_area', 'surface_area'])) {
                    $value = (float)$value;
                }
                if (in_array($field, ['agency_id', 'build_year', 'bedrooms', 'bathrooms', 'epc_value', 'floors'])) {
                    $value = (int)$value;
                }

                $fieldsToUpdate[] = "$field = ?";
                $params[] = $value;
            }
        }

        if (empty($fieldsToUpdate)) {
            ApiResponse::error('No valid fields provided for update.', 422);
            return;
        }
        $params[] = $propertyID;
        $sql = 'UPDATE properties SET ' . implode(', ', $fieldsToUpdate) . ' WHERE id = ?';
        $result = $this->db->executeStatement($sql, $params);
        if ($result >= 0) {
            ApiResponse::success('Property updated successfully.');
        } else {
            ApiResponse::error('Could not update property.', 500);
        }
    }

    public function deleteByID($propertyID)
    {
        $existingProperty = $this->db->fetchAssociative('SELECT * FROM properties WHERE id = ?', [$propertyID]);
        if ($existingProperty === false) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $result = $this->db->executeStatement('DELETE FROM properties WHERE id = ?', [$propertyID]);
        if ($result === 1) {
            ApiResponse::success('Property deleted successfully.');
        } else {
            ApiResponse::error('Could not delete property.', 500);
        }
    }

    public function getAllImg($propertyID)
    {
        
    }

    public function postImg($propertyID)
    {

    }

    public function deleteImg($propertyID, $imgID)
    {

    }

    public function featuresOverview()
    {

    }

    public function postFeature()
    {

    }

    public function deleteFeature($featureID)
    {

    }

    public function propertyFeaturesOverview($propertyID)
    {

    }

    public function postFeatureToProperty($propertyID, $featureID)
    {

    }

    public function deleteFeatureFromProperty($propertyID, $featureID)
    {

    }

    public function deleteAllFeaturesFromProperty($propertyID)
    {

    }
}