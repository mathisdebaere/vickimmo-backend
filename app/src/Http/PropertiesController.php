<?php

namespace Http;

use \Services\ApiResponse;

class PropertiesController extends BaseController
{
    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/properties/';
    private const MAX_UPLOAD_SIZE = 20971520; // 20MB
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];
    
    private const VALID_TYPES = ['house', 'apartment', 'land', 'garage', 'business'];
    private const VALID_STATUSES = ['for sale', 'for rent', 'for auction', 'sold', 'rented'];
    private const VALID_VISIBILITIES = ['public', 'private', 'archived'];

    public function overview(): void
    {
        $conditions = [];
        $params = [];

        /* ─────────────────────────────
        TEXT / EXACT MATCH FILTERS
        ───────────────────────────── */

        if (!empty($_GET['title'])) {
            $conditions[] = 'p.title LIKE ?';
            $params[] = '%' . trim($_GET['title']) . '%';
        }

        if (!empty($_GET['city'])) {
            $conditions[] = 'p.city = ?';
            $params[] = trim($_GET['city']);
        }

        if (!empty($_GET['postal'])) {
            $conditions[] = 'p.postal = ?';
            $params[] = trim($_GET['postal']);
        }

        if (!empty($_GET['address'])) {
            $conditions[] = 'p.address LIKE ?';
            $params[] = '%' . trim($_GET['address']) . '%';
        }

        /* ─────────────────────────────
        ENUM FILTERS (SAFE)
        ───────────────────────────── */

        if (!empty($_GET['type'])) {
            $types = array_filter(
                array_map('trim', explode(',', $_GET['type'])),
                fn ($t) => in_array($t, self::VALID_TYPES, true)
            );

            if (!empty($types)) {
                $conditions[] = 'p.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
                array_push($params, ...$types);
            }
        }

        if (!empty($_GET['status'])) {
            $statuses = array_filter(
                array_map('trim', explode(',', $_GET['status'])),
                fn ($s) => in_array($s, self::VALID_STATUSES, true)
            );

            if (!empty($statuses)) {
                $conditions[] = 'p.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
                array_push($params, ...$statuses);
            }
        }

        if (!empty($_GET['visibility']) && in_array($_GET['visibility'], self::VALID_VISIBILITIES, true)) {
            $conditions[] = 'p.visibility = ?';
            $params[] = $_GET['visibility'];
        }


        /* ─────────────────────────────
        NUMERIC EXACT MATCH
        ───────────────────────────── */

        if (isset($_GET['bedrooms']) && is_numeric($_GET['bedrooms'])) {
            $conditions[] = 'p.bedrooms = ?';
            $params[] = (int)$_GET['bedrooms'];
        }

        if (isset($_GET['bathrooms']) && is_numeric($_GET['bathrooms'])) {
            $conditions[] = 'p.bathrooms = ?';
            $params[] = (int)$_GET['bathrooms'];
        }

        if (isset($_GET['floors']) && is_numeric($_GET['floors'])) {
            $conditions[] = 'p.floors = ?';
            $params[] = (int)$_GET['floors'];
        }

        if (isset($_GET['agency_id']) && is_numeric($_GET['agency_id'])) {
            $conditions[] = 'p.agency_id = ?';
            $params[] = (int)$_GET['agency_id'];
        }

        /* ─────────────────────────────
        RANGE FILTERS
        ───────────────────────────── */

        if (isset($_GET['price_min']) && is_numeric($_GET['price_min'])) {
            $conditions[] = 'p.price >= ?';
            $params[] = (float)$_GET['price_min'];
        }

        if (isset($_GET['price_max']) && is_numeric($_GET['price_max'])) {
            $conditions[] = 'p.price <= ?';
            $params[] = (float)$_GET['price_max'];
        }

        if (isset($_GET['living_area_min']) && is_numeric($_GET['living_area_min'])) {
            $conditions[] = 'p.living_area >= ?';
            $params[] = (float)$_GET['living_area_min'];
        }

        if (isset($_GET['living_area_max']) && is_numeric($_GET['living_area_max'])) {
            $conditions[] = 'p.living_area <= ?';
            $params[] = (float)$_GET['living_area_max'];
        }

        if (isset($_GET['surface_area_min']) && is_numeric($_GET['surface_area_min'])) {
            $conditions[] = 'p.surface_area >= ?';
            $params[] = (float)$_GET['surface_area_min'];
        }

        if (isset($_GET['surface_area_max']) && is_numeric($_GET['surface_area_max'])) {
            $conditions[] = 'p.surface_area <= ?';
            $params[] = (float)$_GET['surface_area_max'];
        }

        if (isset($_GET['build_year_min']) && is_numeric($_GET['build_year_min'])) {
            $conditions[] = 'p.build_year >= ?';
            $params[] = (int)$_GET['build_year_min'];
        }

        if (isset($_GET['build_year_max']) && is_numeric($_GET['build_year_max'])) {
            $conditions[] = 'p.build_year <= ?';
            $params[] = (int)$_GET['build_year_max'];
        }

        /* ─────────────────────────────
        EPC FILTERS
        ───────────────────────────── */

        if (!empty($_GET['epc_label'])) {
            $labels = array_map(
                'strtoupper',
                array_map('trim', explode(',', $_GET['epc_label']))
            );

            $conditions[] = 'p.epc_label IN (' . implode(',', array_fill(0, count($labels), '?')) . ')';
            array_push($params, ...$labels);
        }

        if (isset($_GET['epc_value_max']) && is_numeric($_GET['epc_value_max'])) {
            $conditions[] = 'p.epc_value <= ?';
            $params[] = (int)$_GET['epc_value_max'];
        }

        /* ─────────────────────────────
        BOOLEAN FILTERS
        ───────────────────────────── */

        if (isset($_GET['auction'])) {
            $conditions[] = 'p.auction = ?';
            $params[] = (int)(bool)$_GET['auction'];
        }

        if (isset($_GET['availability'])) {
            $conditions[] = 'p.availability = ?';
            $params[] = (int)(bool)$_GET['availability'];
        }

        /* ─────────────────────────────
        BASE QUERY - NOW WITH JOINS
        ───────────────────────────── */

        $sql = 'SELECT p.*, 
                    a.name AS agency_name,
                    pi.image_path AS main_image_path
                FROM properties p
                LEFT JOIN agencies a ON a.id = p.agency_id
                LEFT JOIN property_images pi ON pi.property_id = p.id AND pi.is_main = 1';

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        /* ─────────────────────────────
        SORTING (SAFE)
        ───────────────────────────── */

        if (!empty($_GET['sort'])) {
            $allowedSorts = [
                'price', 'created_at', 'updated_at',
                'living_area', 'surface_area', 'build_year'
            ];

            $direction = ($_GET['direction'] ?? 'asc') === 'desc'
                ? 'DESC'
                : 'ASC';

            if (in_array($_GET['sort'], $allowedSorts, true)) {
                $sql .= " ORDER BY p.{$_GET['sort']} $direction";
            }
        }

        /* ─────────────────────────────
        LIMIT & OFFSET
        ───────────────────────────── */
        $limit = null;
        $offset = null;

        if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
            $limit = max(1, min((int)$_GET['limit'], 100));
        }

        if (isset($_GET['offset']) && is_numeric($_GET['offset'])) {
            $offset = max(0, (int)$_GET['offset']);
        }

        if ($limit !== null) {
            $sql .= " LIMIT $limit";
            
            if ($offset !== null) {
                $sql .= " OFFSET $offset";
            }
        }
    

        $properties = $this->db->fetchAllAssociative($sql, $params);

        // Get total count for pagination (without limit/offset)
        $countSql = 'SELECT COUNT(*) as total FROM properties p';
        if (!empty($conditions)) {
            $countSql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $totalCount = (int)$this->db->fetchOne($countSql, $params);

        // Add full image URLs
        foreach ($properties as &$property) {
            if (!empty($property['main_image_path'])) {
                $property['main_image_url'] = '/uploads/properties/' . $property['main_image_path'];
            } else {
                $property['main_image_url'] = null;
            }
            unset($property['main_image_path']); // Remove internal field
        }

        // Return with pagination metadata
        ApiResponse::success([
            'data' => $properties,
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset ?? 0,
                'returned' => count($properties)
            ]
        ]);
    }


    public function post(): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $errors = $this->validatePropertyData($data, true);
        
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        $propertyData = $this->preparePropertyData($data);

        $result = $this->db->executeStatement(
            'INSERT INTO properties 
            (title, description, agency_id, address, postal, city, price, build_year, 
             bedrooms, bathrooms, living_area, surface_area, type, sub_type, status, 
             visibility, auction, epc_label, epc_value, floors, availability) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($propertyData)
        );

        if ($result === 1) {
            $id = $this->getLastInsertId();
            $message = 'Property created successfully.' . ($id ? " ID: $id" : '');
            ApiResponse::success(['message' => $message, 'id' => $id], status: 201);
        } else {
            ApiResponse::error('Could not create property.', 500);
        }
    }

    public function getPropertiesByAgency(int $agencyID): void
    {
        $properties = $this->db->fetchAllAssociative(
            'SELECT p.*, 
                    a.name AS agency_name, pi.image_path 
            FROM properties p 
            LEFT JOIN property_images pi ON pi.property_id = p.id AND pi.is_main = 1 
            INNER JOIN agencies a ON a.id = p.agency_id 
            WHERE p.agency_id = ? 
            ORDER BY p.created_at DESC',
            [$agencyID]
        );

        foreach ($properties as &$property) {
            if (!empty($property['image_path'])) {
                $property['main_image_url'] = '/uploads/properties/' . $property['image_path'];
            } else {
                $property['main_image_url'] = '/uploads/properties/property-placeholder.png';
            }

            unset($property['image_path']);
        }

        ApiResponse::success($properties);
    }

    public function getByID(int $propertyID): void
    {
        $propertyRow = $this->db->fetchAssociative(
            'SELECT * FROM properties WHERE id = ?', 
            [$propertyID]
        );
        
        if ($propertyRow === false) {
            ApiResponse::error('Property not found.', 404);
            return;
        }
        
        ApiResponse::success($propertyRow);
    }

    public function putByID(int $propertyID): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $errors = $this->validatePropertyData($data, false);
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        $updateData = $this->prepareUpdateData($data);

        if (empty($updateData)) {
            ApiResponse::error('No valid fields provided for update.', 422);
            return;
        }

        $fields = array_map(fn($field) => "$field = ?", array_keys($updateData));
        $params = array_merge(array_values($updateData), [$propertyID]);
        
        $sql = 'UPDATE properties SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $result = $this->db->executeStatement($sql, $params);

        if ($result >= 0) {
            ApiResponse::success(['message' => 'Property updated successfully.'], status: 200);
        } else {
            ApiResponse::error('Could not update property.', 500);
        }
    }

    public function deleteByID(int $propertyID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        try {
            $this->db->beginTransaction();
            
            $this->deleteAllImagesInternal($propertyID);
            
            $this->db->executeStatement(
                'DELETE FROM properties WHERE id = ?',
                [$propertyID]
            );
            
            $this->db->commit();
            
            ApiResponse::success(['message' => 'Property and all associated images deleted successfully.'], status: 200);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            ApiResponse::error('Failed to delete property: ' . $e->getMessage(), 500);
        }
    }

    // IMAGE MANAGEMENT

    public function getAllImg(int $propertyID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $images = $this->db->fetchAllAssociative(
            'SELECT id, image_path, is_main, created_at
            FROM property_images
            WHERE property_id = ?
            ORDER BY is_main DESC, created_at ASC',
            [$propertyID]
        );

        ApiResponse::success([
        'property_id' => $propertyID,
        'images' => array_map(fn($img) => [
            'id' => (int)$img['id'],
            'image_url' => '/uploads/properties/' . $img['image_path'],
            'is_main' => (bool)$img['is_main'],
            'created_at' => $img['created_at']
        ], $images)
    ]);

    }

    public function postImg(int $propertyID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        if (!isset($_FILES['images'])) {
            ApiResponse::error('No images uploaded.', 422);
            return;
        }

        $this->ensureUploadDirectoryExists();

        $files = $this->normalizeFilesArray($_FILES['images']);
        $result = $this->processImageUploads($files, $propertyID);

        if ($result['successCount'] === 0) {
            ApiResponse::error(
                !empty($result['errors']) ? implode(' | ', $result['errors']) : 'No images uploaded.',
                422
            );
            return;
        }

        ApiResponse::success([
            'message' => "Uploaded {$result['successCount']} image(s).",
            'warnings' => $result['errors']
        ], status: 201);
    }

    public function deleteImg(int $propertyID, int $imgID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $image = $this->db->fetchAssociative(
            'SELECT id, image_path, is_main 
            FROM property_images 
            WHERE id = ? AND property_id = ?',
            [$imgID, $propertyID]
        );

        if (!$image) {
            ApiResponse::error('Image not found for this property.', 404);
            return;
        }

        $this->deleteImageFile($image['image_path']);

        $this->db->executeStatement(
            'DELETE FROM property_images WHERE id = ?',
            [$imgID]
        );

        if ((int)$image['is_main'] === 1) {
            $this->reassignMainImage($propertyID);
        }

        ApiResponse::success(['message' => 'Image deleted successfully.'], status: 200);
    }

    public function deleteAllImg(int $propertyID): void
    {
        $result = $this->deleteAllImagesInternal($propertyID);

        ApiResponse::success([
            'message' => "Deleted {$result['deletedCount']} image(s).",
            'errors' => $result['errors']
        ], status: 200);
    }

    // FEATURES MANAGEMENT

    public function featuresOverview(): void
    {
        $features = $this->db->fetchAllAssociative(
            'SELECT id, name FROM features ORDER BY name ASC'
        );

        ApiResponse::success([
            'features' => array_map(fn($f) => [
                'id' => (int)$f['id'],
                'name' => $f['name']
            ], $features)
        ]);
    }

    public function postFeature(): void
    {
        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        if (!isset($input['name']) || trim($input['name']) === '') {
            ApiResponse::error('Feature name is required.', 422);
            return;
        }

        $name = trim((string)$input['name']);

        if ($this->featureExists($name)) {
            ApiResponse::error('Feature already exists.', 409);
            return;
        }

        $this->db->executeStatement(
            'INSERT INTO features (name) VALUES (?)',
            [$name]
        );

        $id = $this->getLastInsertId();

        ApiResponse::success([
            'message' => 'Feature created successfully.',
            'feature' => ['id' => (int)$id, 'name' => $name]
        ], status: 201);
    }

    // PROPERTY FEATURES MANAGEMENT

    public function propertyFeaturesOverview(int $propertyID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $features = $this->db->fetchAllAssociative(
            'SELECT f.id, f.name
            FROM features f
            JOIN properties_features pf ON pf.feature_id = f.id
            WHERE pf.property_id = ?
            ORDER BY f.name ASC',
            [$propertyID]
        );

        ApiResponse::success([
            'property_id' => $propertyID,
            'features' => array_map(fn($f) => [
                'id' => (int)$f['id'],
                'name' => $f['name']
            ], $features)
        ]);
    }

    public function postFeatureToProperty(int $propertyID, int $featureID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        if (!$this->featureExistsById($featureID)) {
            ApiResponse::error('Feature not found.', 404);
            return;
        }

        if ($this->propertyHasFeature($propertyID, $featureID)) {
            ApiResponse::error('Feature is already assigned to this property.', 409);
            return;
        }

        $this->db->executeStatement(
            'INSERT INTO properties_features (property_id, feature_id) VALUES (?, ?)',
            [$propertyID, $featureID]
        );

        ApiResponse::success([
            'message' => 'Feature added to property successfully.',
            'property_id' => $propertyID,
            'feature_id' => $featureID
        ], status: 201);
    }

    public function deleteFeatureFromProperty(int $propertyID, int $featureID): void
    {
        if (!$this->propertyHasFeature($propertyID, $featureID)) {
            ApiResponse::error('Feature not assigned to this property.', 404);
            return;
        }

        $this->db->executeStatement(
            'DELETE FROM properties_features WHERE property_id = ? AND feature_id = ?',
            [$propertyID, $featureID]
        );

        ApiResponse::success([
            'message' => 'Feature removed from property successfully.',
            'property_id' => $propertyID,
            'feature_id' => $featureID
        ]);
    }

    // PRIVATE HELPER METHODS

    private function validatePropertyData(array $data, bool $isCreate): array
    {
        $errors = [];

        // Required fields for POST
        if ($isCreate) {
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
            if (!in_array($type, self::VALID_TYPES, true)) {
                $errors[] = 'Type must be one of: ' . implode(', ', self::VALID_TYPES);
            }

            $status = isset($data['status']) ? strtolower(trim((string)$data['status'])) : '';
            if (!in_array($status, self::VALID_STATUSES, true)) {
                $errors[] = 'Status must be one of: ' . implode(', ', self::VALID_STATUSES);
            }
        }

        // Validate enum fields if provided (for both POST and PUT)
        if (isset($data['type'])) {
            $type = strtolower(trim((string)$data['type']));
            if (!in_array($type, self::VALID_TYPES, true)) {
                $errors[] = 'Type must be one of: ' . implode(', ', self::VALID_TYPES);
            }
        }

        if (isset($data['status'])) {
            $status = strtolower(trim((string)$data['status']));
            if (!in_array($status, self::VALID_STATUSES, true)) {
                $errors[] = 'Status must be one of: ' . implode(', ', self::VALID_STATUSES);
            }
        }

        if (isset($data['visibility'])) {
            $visibility = strtolower(trim((string)$data['visibility']));
            if (!in_array($visibility, self::VALID_VISIBILITIES, true)) {
                $errors[] = 'Visibility must be one of: ' . implode(', ', self::VALID_VISIBILITIES);
            }
        }

        return $errors;
    }

    private function preparePropertyData(array $data): array
    {
        
        return [
            'title' => trim((string)$data['title']),
            'description' => trim((string)$data['description']),
            'agency_id' => (int)$data['agency_id'],
            'address' => trim((string)$data['address']),
            'postal' => trim((string)$data['postal']),
            'city' => trim((string)$data['city']),
            'price' => (float)$data['price'],
            'build_year' => isset($data['build_year']) ? (int)$data['build_year'] : null,
            'bedrooms' => isset($data['bedrooms']) ? (int)$data['bedrooms'] : null,
            'bathrooms' => isset($data['bathrooms']) ? (int)$data['bathrooms'] : null,
            'living_area' => isset($data['living_area']) ? (float)$data['living_area'] : null,
            'surface_area' => isset($data['surface_area']) ? (float)$data['surface_area'] : null,
            'type' => strtolower(trim((string)$data['type'])),
            'sub_type' => isset($data['sub_type']) ? trim((string)$data['sub_type']) : null,
            'status' => strtolower(trim((string)$data['status'])),
            'visibility' => isset($data['visibility']) ? strtolower(trim((string)$data['visibility'])) : 'public',
            'auction' => isset($data['auction']) ? (int)(bool)$data['auction'] : 0,
            'epc_label' => isset($data['epc_label']) ? strtoupper(trim((string)$data['epc_label'])) : null,
            'epc_value' => isset($data['epc_value']) ? (int)$data['epc_value'] : null,
            'floors' => isset($data['floors']) ? (int)$data['floors'] : null,
            'availability' => isset($data['availability']) ? (int)(bool)$data['availability'] : 1,
        ];
    }

    private function prepareUpdateData(array $data): array
    {

        $updateData = [];

        foreach ($allowedFields as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            if ($field === 'type') {
                $value = strtolower(trim((string)$value));
                if (!in_array($value, self::VALID_TYPES, true)) continue;
            }

            if ($field === 'status') {
                $value = strtolower(trim((string)$value));
                if (!in_array($value, self::VALID_STATUSES, true)) continue;
            }

            if ($field === 'visibility') {
                $value = strtolower(trim((string)$value));
                if (!in_array($value, self::VALID_VISIBILITIES, true)) continue;
            }

            if (in_array($field, ['auction', 'availability'])) {
                $value = (int)(bool)$value;
            } elseif (in_array($field, ['price', 'living_area', 'surface_area'])) {
                $value = (float)$value;
            } elseif (in_array($field, ['agency_id', 'build_year', 'bedrooms', 'bathrooms', 'epc_value', 'floors'])) {
                $value = (int)$value;
            } elseif ($field === 'epc_label' && $value !== null) {
                $value = strtoupper(trim((string)$value));
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            $updateData[$field] = $value;
        }

        return $updateData;
    }

    private function propertyExists(int $propertyID): bool
    {
        return $this->entityExists('properties', $propertyID);
    }

    private function featureExists(string $name): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT id FROM features WHERE name = ?',
            [$name]
        );
        
        return $result !== false;
    }

    private function featureExistsById(int $featureID): bool
    {
        return $this->entityExists('features', $featureID);
    }

    private function propertyHasFeature(int $propertyID, int $featureID): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT * FROM properties_features WHERE property_id = ? AND feature_id = ?',
            [$propertyID, $featureID]
        );
        
        return $result !== false;
    }

    private function getLastInsertId(): int
    {
        return (int)$this->db->lastInsertId();
    }

    private function normalizeFilesArray(array $files): array
    {
        $normalized = [];
        $count = is_array($files['name']) ? count($files['name']) : 1;
        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
            ];
        }
        return $normalized;
    }

    private function ensureUploadDirectoryExists(): void
    {
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }
    }

    private function processImageUploads(array $files, int $propertyID): array
    {
        $successCount = 0;
        $errors = [];

        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Upload error for {$file['name']} (code: {$file['error']})";
                continue;
            }

            if ($file['size'] > self::MAX_UPLOAD_SIZE) {
                $errors[] = "{$file['name']} exceeds 10MB limit.";
                continue;
            }

            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExt, self::ALLOWED_IMAGE_EXTENSIONS, true)) {
                $errors[] = "{$file['name']} has an invalid file type.";
                continue;
            }

            $newFileName = uniqid("prop_{$propertyID}_") . '.' . $fileExt;
            $destination = self::UPLOAD_DIR . $newFileName;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                $errors[] = "Failed to upload {$file['name']}.";
                continue;
            }

            $isMain = ($successCount === 0) ? 1 : 0;

            $this->db->executeStatement(
                'INSERT INTO property_images (property_id, image_path, is_main) VALUES (?, ?, ?)',
                [$propertyID, $newFileName, $isMain]
            );

            $successCount++;
        }

        return ['successCount' => $successCount, 'errors' => $errors];
    }

    private function deleteImageFile(string $imagePath): void
    {
        $filePath = self::UPLOAD_DIR . $imagePath;

        if (file_exists($filePath)) {
            if (!unlink($filePath)) {
                error_log("Failed to delete image file: $filePath");
            }
        }
    }

    private function reassignMainImage(int $propertyID): void
    {
        $newMain = $this->db->fetchAssociative(
            'SELECT id FROM property_images 
            WHERE property_id = ? 
            ORDER BY created_at ASC 
            LIMIT 1',
            [$propertyID]
        );

        if ($newMain) {
            $this->db->executeStatement(
                'UPDATE property_images SET is_main = 1 WHERE id = ?',
                [$newMain['id']]
            );
        }
    }

    private function deleteAllImagesInternal(int $propertyID): array
    {
        $images = $this->db->fetchAllAssociative(
            'SELECT id, image_path FROM property_images WHERE property_id = ?',
            [$propertyID]
        );

        $errors = [];
        $deletedCount = 0;

        foreach ($images as $img) {
            $filePath = self::UPLOAD_DIR . $img['image_path'];
            
            if (file_exists($filePath) && !@unlink($filePath)) {
                $errors[] = "Failed to delete file: {$img['image_path']}";
                continue;
            }

            $this->db->executeStatement(
                'DELETE FROM property_images WHERE id = ?',
                [$img['id']]
            );

            $deletedCount++;
        }

        return ['deletedCount' => $deletedCount, 'errors' => $errors];
    }
}