<?php

namespace Http;

use \Services\ApiResponse;

class UsersController extends BaseController
{
    public function overview()
    {
        $userRows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.created_at, u.updated_at,
                    GROUP_CONCAT(ae.agency_id) as agency_id
             FROM users u
             LEFT JOIN agencies_employees ae ON u.id = ae.user_id
             GROUP BY u.id',
            []
        );
        
        // Convert agency_ids to array
        $userRows = array_map(function($user) {
            $user['agency_id'] = $user['agency_id'] ? array_map('intval', explode(',', $user['agency_id'])) : [];
            return $user;
        }, $userRows);
        
        ApiResponse::success($userRows);
    }

    public function post()
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            ApiResponse::error('Invalid JSON input', 400);
            return;
        }

        $errors = $this->validateUserData($data, true);
        
        if (!empty($errors)) {
            ApiResponse::error('Validation failed', 400, ['errors' => $errors]);
            return;
        }

        // Hash the password
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        $userData = [
            'name' => trim($data['name']),
            'email' => trim(strtolower($data['email'])),
            'password' => $hashedPassword,
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
            'role' => $data['role']
        ];

        $result = $this->db->executeStatement(
            'INSERT INTO users (name, email, password, phone, role, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            array_values($userData)
        );

        if ($result === 1) {
            $id = $this->getLastInsertId();
            
            // Handle agency assignment if provided and user is employee
            if (isset($data['agency_id']) && $data['role'] === 'employee') {
                $agencyId = (int)$data['agency_id'];
                if ($agencyId > 0) {
                    $this->db->executeStatement(
                        'INSERT INTO agencies_employees (user_id, agency_id) VALUES (?, ?)',
                        [$id, $agencyId]
                    );
                }
            }
            
            $message = 'User created successfully.';
            ApiResponse::success(['message' => $message, 'id' => $id], status: 201);
        } else {
            ApiResponse::error('Could not create user.', 500);
        }
    }

    public function getByID($id)
    {
        $user = $this->db->fetchAssociative(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.created_at, u.updated_at,
                    GROUP_CONCAT(ae.agency_id) as agency_id
             FROM users u
             LEFT JOIN agencies_employees ae ON u.id = ae.user_id
             WHERE u.id = ?
             GROUP BY u.id',
            [$id]
        );

        if ($user === false) {
            ApiResponse::error('User not found', 404);
            return;
        }
        
        // Convert agency_ids to array
        $user['agency_id'] = $user['agency_id'] ? array_map('intval', explode(',', $user['agency_id'])) : [];

        ApiResponse::success($user);
    }

    public function putByID($id)
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            ApiResponse::error('Invalid JSON input', 400);
            return;
        }

        if (!$this->userExists($id)) {
            ApiResponse::error('User not found', 404);
            return;
        }

        $errors = $this->validateUserData($data, false);
        if (!empty($errors)) {
            ApiResponse::error('Validation failed', 400, ['errors' => $errors]);
            return;
        }

        $updateData = $this->prepareUpdateData($data);

        if (empty($updateData)) {
            ApiResponse::error('No valid fields to update', 400);
            return;
        }

        $fields = array_map(fn($field) => "$field = ?", array_keys($updateData));
        $params = array_merge(array_values($updateData), [$id]);
        
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?';
        $result = $this->db->executeStatement($sql, $params);
        
        // Handle agency assignment if provided and user is employee
        if (isset($data['agency_id']) && isset($data['role']) && $data['role'] === 'employee') {
            $agencyId = (int)$data['agency_id'];
            
            // Remove existing agency assignments
            $this->db->executeStatement(
                'DELETE FROM agencies_employees WHERE user_id = ?',
                [$id]
            );
            
            // Add new agency assignment if agency_id is not empty
            if ($agencyId > 0) {
                $this->db->executeStatement(
                    'INSERT INTO agencies_employees (user_id, agency_id) VALUES (?, ?)',
                    [$id, $agencyId]
                );
            }
        }

        if ($result >= 0) {
            ApiResponse::success(['message' => 'User updated successfully.'], status: 200);
        } else {
            ApiResponse::error('Could not update user.', 500);
        }
    }

    public function deleteByID($id)
    {
        if (!$this->userExists($id)) {
            ApiResponse::error('User not found', 404);
            return;
        }

        try {
            // Delete user's favourites
            $this->db->executeStatement(
                'DELETE FROM favourites WHERE user_id = ?',
                [$id]
            );

            // Delete user's reviews
            $this->db->executeStatement(
                'DELETE FROM reviews WHERE user_id = ?',
                [$id]
            );

            // Delete user's bids
            $this->db->executeStatement(
                'DELETE FROM bids WHERE user_id = ?',
                [$id]
            );

            // Delete user's refresh tokens
            $this->db->executeStatement(
                'DELETE FROM refresh_tokens WHERE user_id = ?',
                [$id]
            );

            // Delete user's agency associations (employees)
            $this->db->executeStatement(
                'DELETE FROM employees WHERE user_id = ?',
                [$id]
            );

            // Finally delete the user
            $this->db->executeStatement(
                'DELETE FROM users WHERE id = ?',
                [$id]
            );

            ApiResponse::success(['message' => 'User and all associated data deleted successfully.'], status: 200);
        } catch (\Throwable $e) {
            ApiResponse::error('Failed to delete user: ' . $e->getMessage(), 500);
        }
    }

    // PRIVATE HELPER METHODS

    private function validateUserData(array $data, bool $isCreate): array
    {
        $errors = [];

        if ($isCreate) {
            // Name validation
            if (empty($data['name']) || !is_string($data['name'])) {
                $errors['name'] = 'Name is required and must be a string.';
            } elseif (strlen(trim($data['name'])) < 2) {
                $errors['name'] = 'Name must be at least 2 characters long.';
            }

            // Email validation
            if (empty($data['email']) || !is_string($data['email'])) {
                $errors['email'] = 'Email is required and must be a string.';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format.';
            } elseif ($this->emailExists($data['email'])) {
                $errors['email'] = 'Email already exists.';
            }

            // Password validation (required for create)
            if (empty($data['password']) || !is_string($data['password'])) {
                $errors['password'] = 'Password is required and must be a string.';
            } elseif (strlen($data['password']) < 8) {
                $errors['password'] = 'Password must be at least 8 characters long.';
            }

            // Role validation
            if (empty($data['role']) || !in_array($data['role'], ['client', 'employee', 'admin'])) {
                $errors['role'] = 'Role must be one of: client, employee, admin.';
            }
        }

        // Email validation for updates
        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format.';
            }
        }

        // Phone validation if provided
        if (isset($data['phone']) && $data['phone'] !== null && $data['phone'] !== '') {
            if (!$this->validatePhone($data['phone'])) {
                $errors['phone'] = 'Invalid phone format.';
            }
        }

        // Password validation (optional for update, required for create)
        if (!empty($data['password'])) {
            if (!is_string($data['password'])) {
                $errors['password'] = 'Password must be a string.';
            } elseif (strlen($data['password']) < 8) {
                $errors['password'] = 'Password must be at least 8 characters long.';
            }
        }

        // Role validation for updates
        if (isset($data['role']) && !in_array($data['role'], ['client', 'employee', 'admin'])) {
            $errors['role'] = 'Role must be one of: client, employee, admin.';
        }

        return $errors;
    }

    private function prepareUpdateData(array $data): array
    {
        $allowedFields = ['name', 'email', 'phone', 'role', 'password'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];

                // Skip empty password (means don't update it)
                if ($field === 'password' && empty($value)) {
                    continue;
                }
                
                // Hash password if provided
                if ($field === 'password' && !empty($value)) {
                    $updateData[$field] = password_hash($value, PASSWORD_DEFAULT);
                    continue;
                }

                // Handle empty strings as null for optional fields
                if (in_array($field, ['phone']) && $value === '') {
                    $updateData[$field] = null;
                } else {
                    $updateData[$field] = is_string($value) ? trim($value) : $value;
                }
            }
        }

        return $updateData;
    }

}
