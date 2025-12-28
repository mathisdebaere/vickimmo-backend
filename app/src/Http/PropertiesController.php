<?php

namespace Http;

use \Services\ApiResponse;
use \Services\DatabaseConnector;

class PropertiesController
{
    protected \Doctrine\DBAL\Connection $db;
    
    private const UPLOAD_DIR = __DIR__ . '/uploads/properties/';
    private const MAX_UPLOAD_SIZE = 20971520; // 20MB
    private const ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];
    
    private const VALID_TYPES = ['house', 'apartment', 'land', 'garage', 'business'];
    private const VALID_STATUSES = ['for sale', 'for rent', 'sold', 'rented'];
    private const VALID_VISIBILITIES = ['public', 'private', 'archived'];

    public function __construct()
    {
        $this->db = DatabaseConnector::getConnection();
    }

    public function methodNotAllowed(): void
    {
        ApiResponse::error('HTTP request method ' . $_SERVER['REQUEST_METHOD'] . ' not allowed.', 405);
    }

    public function overview(): void
    {
        $propertyRows = $this->db->fetchAllAssociative('SELECT * FROM properties');
        ApiResponse::success($propertyRows);
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

    public function deleteFeature(int $featureID): void
    {
        $feature = $this->db->fetchAssociative(
            'SELECT id, name FROM features WHERE id = ?',
            [$featureID]
        );

        if (!$feature) {
            ApiResponse::error('Feature not found.', 404);
            return;
        }

        $this->db->executeStatement(
            'DELETE FROM features WHERE id = ?',
            [$featureID]
        );

        ApiResponse::success([
            'message' => "Feature '{$feature['name']}' deleted successfully."
        ]);
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

    public function deleteAllFeaturesFromProperty(int $propertyID): void
    {
        if (!$this->propertyExists($propertyID)) {
            ApiResponse::error('Property not found.', 404);
            return;
        }

        $deleted = $this->db->executeStatement(
            'DELETE FROM properties_features WHERE property_id = ?',
            [$propertyID]
        );

        ApiResponse::success([
            'message' => "Deleted $deleted feature(s) from property.",
            'property_id' => $propertyID
        ]);
    }

    // PRIVATE HELPER METHODS

    private function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error('Invalid JSON payload.', 422);
            return null;
        }

        return $data;
    }

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
        $allowedFields = [
            'title', 'description', 'agency_id', 'address', 'postal', 'city', 'price',
            'build_year', 'bedrooms', 'bathrooms', 'living_area', 'surface_area', 'type',
            'sub_type', 'status', 'visibility', 'auction', 'epc_label', 'epc_value', 'floors', 'availability'
        ];

        $updateData = [];

        foreach ($allowedFields as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            // Validate and normalize enum fields
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

            // Type casting
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
        $result = $this->db->fetchAssociative(
            'SELECT id FROM properties WHERE id = ?',
            [$propertyID]
        );
        
        return $result !== false;
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
        $result = $this->db->fetchAssociative(
            'SELECT id FROM features WHERE id = ?',
            [$featureID]
        );
        
        return $result !== false;
    }

    private function propertyHasFeature(int $propertyID, int $featureID): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT * FROM properties_features WHERE property_id = ? AND feature_id = ?',
            [$propertyID, $featureID]
        );
        
        return $result !== false;
    }

    private function getLastInsertId(): ?int
    {
        try {
            return (int)$this->db->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
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
                // Log error but don't fail the operation
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

            if (file_exists($filePath)) {
                if (!unlink($filePath)) {
                    $errors[] = "Failed to delete file: {$img['image_path']}";
                    continue;
                }
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