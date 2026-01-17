<?php

namespace Http;

use \Services\ApiResponse;

class AgenciesController extends BaseController
{
    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/agencies/';
    private const MAX_UPLOAD_SIZE = 10485760; // 10MB for logos
    private const ALLOWED_LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'svg', 'webp'];

    public function overview(): void
    {
        $conditions = [];
        $params = [];

        if (!empty($_GET['search'])) {
            $searchTerm = '%' . $_GET['search'] . '%';
            $conditions[] = '(a.name LIKE ? OR a.city LIKE ? OR a.description LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        /* ─────────────────────────────
        BASE QUERY
        ───────────────────────────── */
        $sql = 'SELECT 
                a.id,
                a.name,
                a.phone,
                a.email,
                a.description,
                a.address,
                a.city,
                a.postal,
                a.website,
                a.logo,
                a.created_at,
                a.updated_at,
                COUNT(DISTINCT p.id) AS property_count,
                COUNT(DISTINCT ae.user_id) AS employee_count,
                COUNT(r.user_id) AS review_count,
                ROUND(AVG(r.rating), 2) AS avg_rating
            FROM agencies a
            LEFT JOIN properties p ON p.agency_id = a.id
            LEFT JOIN agencies_employees ae ON ae.agency_id = a.id
            LEFT JOIN reviews r ON r.agency_id = a.id';

        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' GROUP BY a.id';

        /* ─────────────────────────────
        SORTING
        ───────────────────────────── */
        if (!empty($_GET['sort'])) {
            $allowedSorts = ['rating', 'name', 'created_at'];
            $direction = ($_GET['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

            if (in_array($_GET['sort'], $allowedSorts, true)) {
                if ($_GET['sort'] === 'rating') {
                    $sql .= " ORDER BY avg_rating $direction";
                } else {
                    $sql .= " ORDER BY a.{$_GET['sort']} $direction";
                }
            }
        } else {
            $sql .= ' ORDER BY a.name ASC';
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

        $agencies = $this->db->fetchAllAssociative($sql, $params);

        // Get total count for pagination (with search filter applied)
        $countSql = 'SELECT COUNT(*) as total FROM agencies a';
        if (!empty($conditions)) {
            $countSql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $totalCount = (int)$this->db->fetchOne($countSql, $params);

        ApiResponse::success([
            'agencies' => array_map(function ($agency) {
                // Format the response using the existing method
                $formatted = $this->formatAgencyResponse($agency);
                
                // Add the statistics
                $formatted['property_count'] = (int)$agency['property_count'];
                $formatted['employee_count'] = (int)$agency['employee_count'];
                $formatted['review_count'] = (int)$agency['review_count'];
                $formatted['avg_rating'] = $agency['avg_rating'] !== null
                    ? (float)$agency['avg_rating']
                    : null;
                
                return $formatted;
            }, $agencies),
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset ?? 0,
                'returned' => count($agencies)
            ]
        ]);
    }

    public function post(): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $errors = $this->validateAgencyData($data, true);
        
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        $agencyData = $this->prepareAgencyData($data);

        $result = $this->db->executeStatement(
            'INSERT INTO agencies 
            (name, phone, email, description, address, city, postal, website, logo) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($agencyData)
        );

        if ($result === 1) {
            $id = $this->getLastInsertId();
            $message = 'Agency created successfully.' . ($id ? " ID: $id" : '');
            ApiResponse::success(['message' => $message, 'id' => $id], status: 201);
        } else {
            ApiResponse::error('Could not create agency.', 500);
        }
    }

    public function getByID(int $id): void
    {
        $agency = $this->db->fetchAssociative(
            'SELECT id, name, phone, email, description, address, city, postal, 
                    website, logo, created_at, updated_at 
            FROM agencies 
            WHERE id = ?',
            [$id]
        );

        if ($agency === false) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        // Get average rating, review count, and employee count
        $stats = $this->db->fetchAssociative(
            'SELECT COUNT(DISTINCT r.user_id) as review_count, 
                    AVG(r.rating) as avg_rating,
                    COUNT(DISTINCT ae.user_id) as employee_count,
                    COUNT(DISTINCT p.id) as property_count
            FROM agencies a
            LEFT JOIN reviews r ON r.agency_id = a.id
            LEFT JOIN agencies_employees ae ON ae.agency_id = a.id
            LEFT JOIN properties p ON p.agency_id = a.id
            WHERE a.id = ?',
            [$id]
        );

        $response = $this->formatAgencyResponse($agency);
        $response['review_count'] = (int)($stats['review_count'] ?? 0);
        $response['employee_count'] = (int)($stats['employee_count'] ?? 0);
        $response['property_count'] = (int)($stats['property_count'] ?? 0);
        $response['avg_rating'] = $stats['avg_rating'] ? round((float)$stats['avg_rating'], 2) : null;

        ApiResponse::success($response);
    }

    public function putByID(int $id): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        if (!$this->agencyExists($id)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $errors = $this->validateAgencyData($data, false);
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
        $params = array_merge(array_values($updateData), [$id]);
        
        $sql = 'UPDATE agencies SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $result = $this->db->executeStatement($sql, $params);

        if ($result >= 0) {
            ApiResponse::success(['message' => 'Agency updated successfully.'], status: 200);
        } else {
            ApiResponse::error('Could not update agency.', 500);
        }
    }

    public function deleteByID(int $id): void
    {
        if (!$this->agencyExists($id)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        // Check if agency has properties
        $propertyCount = $this->db->fetchOne(
            'SELECT COUNT(*) FROM properties WHERE agency_id = ?',
            [$id]
        );

        if ($propertyCount > 0) {
            ApiResponse::error(
                "Cannot delete agency. It has $propertyCount associated property/properties. Please reassign or delete them first.",
                409
            );
            return;
        }

        try {
            $this->db->beginTransaction();

            // Get logo before deletion
            $agency = $this->db->fetchAssociative(
                'SELECT logo FROM agencies WHERE id = ?',
                [$id]
            );

            // Delete agency (reviews will cascade)
            $this->db->executeStatement(
                'DELETE FROM agencies WHERE id = ?',
                [$id]
            );

            // Delete logo file if exists
            if ($agency && $agency['logo']) {
                $this->deleteLogoFile($agency['logo']);
            }

            $this->db->commit();

            ApiResponse::success(['message' => 'Agency and all associated reviews deleted successfully.'], status: 200);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            ApiResponse::error('Failed to delete agency: ' . $e->getMessage(), 500);
        }
    }

    // LOGO MANAGEMENT

    public function postLogo(int $id): void
    {
        if (!$this->agencyExists($id)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        if (!isset($_FILES['logo'])) {
            ApiResponse::error('No logo file uploaded.', 422);
            return;
        }

        $this->ensureUploadDirectoryExists();

        $file = $_FILES['logo'];

        // Validate upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            ApiResponse::error("Upload error (code: {$file['error']})", 422);
            return;
        }

        if ($file['size'] > self::MAX_UPLOAD_SIZE) {
            ApiResponse::error('Logo file exceeds 5MB limit.', 422);
            return;
        }

        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, self::ALLOWED_LOGO_EXTENSIONS, true)) {
            ApiResponse::error('Invalid file type. Allowed: ' . implode(', ', self::ALLOWED_LOGO_EXTENSIONS), 422);
            return;
        }

        // Get old logo
        $oldLogo = $this->db->fetchOne(
            'SELECT logo FROM agencies WHERE id = ?',
            [$id]
        );

        // Simple naming: agency_1.png, agency_2.jpg, etc.
        $newFileName = "agency_{$id}." . $fileExt;
        $destination = self::UPLOAD_DIR . $newFileName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            ApiResponse::error('Failed to upload logo.', 500);
            return;
        }

        // Update database
        $this->db->executeStatement(
            'UPDATE agencies SET logo = ? WHERE id = ?',
            [$newFileName, $id]
        );

        // Delete old logo
        if ($oldLogo) {
            $this->deleteLogoFile($oldLogo);
        }

        ApiResponse::success([
            'message' => 'Logo uploaded successfully.',
            'logo_url' => '/uploads/agencies/' . $newFileName
        ], status: 200);
    }

    public function deleteLogo(int $id): void
    {
        if (!$this->agencyExists($id)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $logo = $this->db->fetchOne(
            'SELECT logo FROM agencies WHERE id = ?',
            [$id]
        );

        if (!$logo) {
            ApiResponse::error('Agency has no logo.', 404);
            return;
        }

        // Delete file
        $this->deleteLogoFile($logo);

        // Update database
        $this->db->executeStatement(
            'UPDATE agencies SET logo = NULL WHERE id = ?',
            [$id]
        );

        ApiResponse::success(['message' => 'Logo deleted successfully.'], status: 200);
    }

    // REVIEWS MANAGEMENT

    public function reviewsOverview(int $agencyID): void
    {
        if (!$this->agencyExists($agencyID)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $reviews = $this->db->fetchAllAssociative(
            'SELECT r.user_id, r.agency_id, r.rating, r.title, r.content, 
                    r.created_at, r.updated_at, u.name, u.email
            FROM reviews r
            JOIN users u ON u.id = r.user_id
            WHERE r.agency_id = ?
            ORDER BY r.created_at DESC',
            [$agencyID]
        );

        // Calculate statistics
        $stats = $this->db->fetchAssociative(
            'SELECT COUNT(*) as total_reviews, 
                    AVG(rating) as avg_rating,
                    MIN(rating) as min_rating,
                    MAX(rating) as max_rating
            FROM reviews 
            WHERE agency_id = ?',
            [$agencyID]
        );

        ApiResponse::success([
            'agency_id' => $agencyID,
            'statistics' => [
                'total_reviews' => (int)($stats['total_reviews'] ?? 0),
                'avg_rating' => $stats['avg_rating'] ? round((float)$stats['avg_rating'], 2) : null,
                'min_rating' => $stats['min_rating'] ? (int)$stats['min_rating'] : null,
                'max_rating' => $stats['max_rating'] ? (int)$stats['max_rating'] : null,
            ],
            'reviews' => array_map(fn($review) => [
                'user_id' => (int)$review['user_id'],
                'name' => $review['name'],
                'email' => $review['email'],
                'rating' => (int)$review['rating'],
                'title' => $review['title'],
                'content' => $review['content'],
                'created_at' => $review['created_at'],
                'updated_at' => $review['updated_at']
            ], $reviews)
        ]);
    }

    public function postReview(int $agencyID): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        if (!$this->agencyExists($agencyID)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $errors = $this->validateReviewData($data);
        
        if (!empty($errors)) {
            $errorMessage = is_array($errors) ? implode(' ', $errors) : $errors;
            ApiResponse::error($errorMessage, 422);
            return;
        }

        $userId = (int)$data['user_id'];
        $rating = (int)$data['rating'];
        $title = trim((string)$data['title']);
        $content = trim((string)$data['content']);

        // Check if user exists
        if (!$this->userExists($userId)) {
            ApiResponse::error('User not found.', 404);
            return;
        }

        // Check if review already exists (composite primary key)
        if ($this->reviewExists($userId, $agencyID)) {
            ApiResponse::error("You've already reviewed this agency.", 409);
            return;
        }

        $result = $this->db->executeStatement(
            'INSERT INTO reviews (user_id, agency_id, rating, title, content) 
            VALUES (?, ?, ?, ?, ?)',
            [$userId, $agencyID, $rating, $title, $content]
        );

        if ($result === 1) {
            ApiResponse::success([
                'message' => 'Review created successfully.',
                'review' => [
                    'user_id' => $userId,
                    'agency_id' => $agencyID,
                    'rating' => $rating,
                    'title' => $title,
                    'content' => $content
                ]
            ], status: 201);
        } else {
            ApiResponse::error('Could not create review.', 500);
        }
    }

    public function deleteReview(int $agencyID, int $userID): void
    {
        if (!$this->reviewExists($userID, $agencyID)) {
            ApiResponse::error('Review not found.', 404);
            return;
        }

        $this->db->executeStatement(
            'DELETE FROM reviews WHERE user_id = ? AND agency_id = ?',
            [$userID, $agencyID]
        );

        ApiResponse::success(['message' => 'Review deleted successfully.'], status: 200);
    }

    // PRIVATE HELPER METHODS

    private function validateAgencyData(array $data, bool $isCreate): array
    {
        $errors = [];

        if ($isCreate) {
            // Required fields for POST
            $name = isset($data['name']) ? trim((string)$data['name']) : '';
            if ($name === '' || mb_strlen($name) < 2) {
                $errors[] = 'Name must be at least 2 characters.';
            }

            $email = isset($data['email']) ? trim((string)$data['email']) : '';
            if ($email === '' || !$this->validateEmail($email)) {
                $errors[] = 'Valid email address is required.';
            }

            $address = isset($data['address']) ? trim((string)$data['address']) : '';
            if ($address === '') {
                $errors[] = 'Address is required.';
            }

            $city = isset($data['city']) ? trim((string)$data['city']) : '';
            if ($city === '') {
                $errors[] = 'City is required.';
            }

            $postal = isset($data['postal']) ? trim((string)$data['postal']) : '';
            if ($postal === '') {
                $errors[] = 'Postal code is required.';
            }
        }

        if (isset($data['email'])) {
            $email = trim((string)$data['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format.';
            }
        }

        // Validate phone format if provided
        if (isset($data['phone']) && $data['phone'] !== null) {
            $phone = trim((string)$data['phone']);
            if ($phone !== '' && !$this->validatePhone($phone)) {
                $errors[] = 'Invalid phone number format.';
            }
        }

        // Validate website URL if provided
        if (isset($data['website']) && $data['website'] !== null) {
            $website = trim((string)$data['website']);
            if ($website !== '' && !$this->validateUrl($website)) {
                $errors[] = 'Invalid website URL format.';
            }
        }

        return $errors;
    }

    private function prepareAgencyData(array $data): array
    {
        
        return [
            'name' => trim((string)$data['name']),
            'phone' => isset($data['phone']) ? trim((string)$data['phone']) : null,
            'email' => trim((string)$data['email']),
            'description' => isset($data['description']) ? trim((string)$data['description']) : null,
            'address' => trim((string)$data['address']),
            'city' => trim((string)$data['city']),
            'postal' => trim((string)$data['postal']),
            'website' => isset($data['website']) ? trim((string)$data['website']) : null,
            'logo' => null, 
        ];
    }

    private function prepareUpdateData(array $data): array
    {
        $allowedFields = ['name', 'phone', 'email', 'description', 'address', 'city', 'postal', 'website'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            // Trim string values
            if (is_string($value)) {
                $value = trim($value);
                // Allow null for optional fields
                if ($value === '' && in_array($field, ['phone', 'description', 'website'])) {
                    $value = null;
                }
            }

            $updateData[$field] = $value;
        }

        return $updateData;
    }

    private function validateReviewData(array $data, bool $isCreate = true): array
    {
        $errors = [];

        if ($isCreate) {
            // User ID is required for POST
            if (!isset($data['user_id']) || (int)$data['user_id'] <= 0) {
                $errors[] = 'Valid user_id is required.';
            }

            // Rating is required for POST
            $rating = isset($data['rating']) ? (int)$data['rating'] : 0;
            if ($rating < 1 || $rating > 5) {
                $errors[] = 'Rating must be between 1 and 5.';
            }

            // Title is required for POST
            $title = isset($data['title']) ? trim((string)$data['title']) : '';
            if ($title === '') {
                $errors[] = 'Title is required.';
            } elseif (mb_strlen($title) < 10) {
                $errors[] = 'Title must be at least 10 characters.';
            } elseif (mb_strlen($title) > 250) {
                $errors[] = 'Title must not exceed 250 characters.';
            }

            // Content is required for POST
            $content = isset($data['content']) ? trim((string)$data['content']) : '';
            if ($content === '') {
                $errors[] = 'Content is required.';
            } elseif (mb_strlen($content) < 10) {
                $errors[] = 'Content must be at least 10 characters.';
            } elseif (mb_strlen($content) > 250) {
                $errors[] = 'Content must not exceed 250 characters.';
            }
        } else {
            // For PUT, validate only if provided
            if (isset($data['rating'])) {
                $rating = (int)$data['rating'];
                if ($rating < 1 || $rating > 5) {
                    $errors[] = 'Rating must be between 1 and 5.';
                }
            }

            if (isset($data['title'])) {
                $title = trim((string)$data['title']);
                if (mb_strlen($title) < 10) {
                    $errors[] = 'Title must be at least 10 characters.';
                } elseif (mb_strlen($title) > 250) {
                    $errors[] = 'Title must not exceed 250 characters.';
                }
            }

            if (isset($data['content'])) {
                $content = trim((string)$data['content']);
                if (mb_strlen($content) < 10) {
                    $errors[] = 'Content must be at least 10 characters.';
                } elseif (mb_strlen($content) > 250) {
                    $errors[] = 'Content must not exceed 250 characters.';
                }
            }
        }

        return $errors;
    }



    private function reviewExists(int $userId, int $agencyId): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT user_id FROM reviews WHERE user_id = ? AND agency_id = ?',
            [$userId, $agencyId]
        );
        
        return $result !== false;
    }

    private function formatAgencyResponse(array $agency): array
    {
        return [
            'id' => (int)$agency['id'],
            'name' => $agency['name'],
            'phone' => $agency['phone'],
            'email' => $agency['email'],
            'description' => $agency['description'],
            'address' => $agency['address'],
            'city' => $agency['city'],
            'postal' => $agency['postal'],
            'website' => $agency['website'],
            'logo_url' => $agency['logo'] ? '/uploads/agencies/' . $agency['logo'] : null,
            'created_at' => $agency['created_at'],
            'updated_at' => $agency['updated_at']
        ];
    }

    private function ensureUploadDirectoryExists(): void
    {
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }
    }

    private function deleteLogoFile(string $logoPath): void
    {
        $filePath = self::UPLOAD_DIR . $logoPath;

        if (file_exists($filePath)) {
            if (!unlink($filePath)) {
                error_log("Failed to delete logo file: $filePath");
            }
        }
    }

    // ANALYTICS

    public function analyticsOverview(): void
    {
        // Get agencies with their stats
        $agencies = $this->db->fetchAllAssociative(
            'SELECT 
                a.id,
                a.name,
                a.logo,
                COUNT(DISTINCT p.id) AS property_count,
                COUNT(DISTINCT r.user_id) AS review_count,
                ROUND(AVG(r.rating), 2) AS avg_rating
            FROM agencies a
            LEFT JOIN properties p ON p.agency_id = a.id
            LEFT JOIN reviews r ON r.agency_id = a.id
            GROUP BY a.id'
        );

        // For each agency, get favourites count and weekly favourites data
        $analyticsData = [];
        foreach ($agencies as $agency) {
            $agencyId = (int)$agency['id'];

            // Get total favourites count for this agency's properties
            $favouritesResult = $this->db->fetchAssociative(
                'SELECT COUNT(*) as total_favourites
                FROM favourites f
                INNER JOIN properties p ON p.id = f.property_id
                WHERE p.agency_id = ?',
                [$agencyId]
            );

            // Get favourites per day for the last 7 days
            $weeklyfavourites = $this->db->fetchAllAssociative(
                'SELECT 
                    DATE(f.created_at) as date,
                    COUNT(*) as count
                FROM favourites f
                INNER JOIN properties p ON p.id = f.property_id
                WHERE p.agency_id = ?
                    AND f.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                GROUP BY DATE(f.created_at)
                ORDER BY date ASC',
                [$agencyId]
            );

            // Format weekly data to include all 7 days (fill missing days with 0)
            $weeklyData = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $found = false;
                foreach ($weeklyfavourites as $fav) {
                    if ($fav['date'] === $date) {
                        $weeklyData[] = [
                            'date' => $date,
                            'count' => (int)$fav['count']
                        ];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $weeklyData[] = [
                        'date' => $date,
                        'count' => 0
                    ];
                }
            }

            $analyticsData[] = [
                'id' => $agencyId,
                'name' => $agency['name'],
                'logo_url' => $agency['logo'] ? '/uploads/agencies/' . $agency['logo'] : null,
                'property_count' => (int)$agency['property_count'],
                'review_count' => (int)$agency['review_count'],
                'avg_rating' => $agency['avg_rating'] ? (float)$agency['avg_rating'] : null,
                'total_favourites' => (int)($favouritesResult['total_favourites'] ?? 0),
                'weekly_favourites' => $weeklyData
            ];
        }

        ApiResponse::success([
            'count' => count($analyticsData),
            'analytics' => $analyticsData
        ]);
    }


}