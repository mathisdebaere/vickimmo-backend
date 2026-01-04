<?php

namespace Http;

use \Services\ApiResponse;
use \Services\DatabaseConnector;
use \Services\JWTService;

class AuthController
{
    protected \Doctrine\DBAL\Connection $db;
    
    public function __construct()
    {
        $this->db = DatabaseConnector::getConnection();
    }

    private function getSecretKey(): string
    {
        $key = $_ENV['JWT_SECRET_KEY'] ?? null;
        if (!$key || strlen($key) < 32) {
            throw new \Exception('JWT_SECRET_KEY must be at least 32 characters. Generate one with: openssl rand -base64 64');
        }
        return $key;
    }

    // Separate secret for refresh tokens (more secure)
    private function getRefreshSecretKey(): string
    {
        $key = $_ENV['JWT_REFRESH_SECRET_KEY'] ?? $this->getSecretKey();
        if (!$key || strlen($key) < 32) {
            throw new \Exception('JWT_REFRESH_SECRET_KEY must be at least 32 characters.');
        }
        return $key;
    }

    public function methodNotAllowed(): void
    {
        ApiResponse::error('HTTP request method ' . $_SERVER['REQUEST_METHOD'] . ' not allowed.', 405);
    }
    
    private function getAccessTokenLifetime(): int
    {
        return (int)($_ENV['ACCESS_TOKEN_LIFETIME'] ?? 30); 
    }
    
    private function getRefreshTokenLifetime(): int
    {
        return (int)($_ENV['REFRESH_TOKEN_LIFETIME'] ?? 15); 
    }

    public function register(): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $errors = $this->validateRegistrationData($data);
        
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        $name = trim((string)$data['name']);
        $email = strtolower(trim((string)$data['email']));
        $password = (string)$data['password'];
        $phone = isset($data['phone']) ? trim((string)$data['phone']) : null;
        $role = isset($data['role']) ? strtolower(trim((string)$data['role'])) : 'client';

        if ($this->emailExists($email)) {
            ApiResponse::error('Email address is already registered.', 409);
            return;
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            $result = $this->db->executeStatement(
                'INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, ?)',
                [$name, $email, $hashedPassword, $phone, $role]
            );

            if ($result === 1) {
                $userId = $this->getLastInsertId();
                $user = [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'role' => $role
                ];
                // Generate both access and refresh tokens
                $tokens = $this->generateTokenPair($user);
                ApiResponse::success([
                    'message' => 'User registered successfully.',
                    'user' => [
                        'id' => $userId,
                        'name' => $name,
                        'email' => $email,
                        'role' => $role
                    ],
                    'accessToken' => $tokens['accessToken'],
                    'refreshToken' => $tokens['refreshToken'],
                    'expiresIn' => $this->getAccessTokenLifetime()
                ], status: 201);
            } else {
                ApiResponse::error('Could not register user.', 500);
            }
        } catch (\Throwable $e) {
            ApiResponse::error('Registration failed: ' . $e->getMessage(), 500);
        }
    }

    public function login(): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $email = isset($data['email']) ? strtolower(trim((string)$data['email'])) : '';
        $password = isset($data['password']) ? (string)$data['password'] : '';

        if ($email === '' || $password === '') {
            ApiResponse::error('Email and password are required.', 400);
            return;
        }

        $user = $this->db->fetchAssociative(
            'SELECT id, name, email, password, role FROM users WHERE email = ?',
            [$email]
        );

        if ($user === false) {
            ApiResponse::error('Invalid credentials.', 401);
            return;
        }

        if (!password_verify($password, $user['password'])) {
            ApiResponse::error('Invalid credentials.', 401);
            return;
        }

        // Generate both access and refresh tokens
        $tokens = $this->generateTokenPair($user);

        // Optional: Update last login
        $this->db->executeStatement(
            'UPDATE users SET updated_at = NOW() WHERE id = ?',
            [$user['id']]
        );

        ApiResponse::success([
            'message' => 'Login successful.',
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ],
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
            'expiresIn' => $this->getAccessTokenLifetime()
        ]);
    }

    public function logout(): void
    {
        $token = $this->getBearerToken();
        
        if (!$token) {
            ApiResponse::success([
                'message' => 'Logout successful.'
            ]);
            return;
        }

        try {
            // Invalidate the refresh token
            $decoded = JWTService::validateJWTToken($token, $this->getSecretKey());
            $userId = (int)$decoded->sub;
            
            // If you have a refresh_tokens table, delete user's tokens
            $this->db->executeStatement(
                'DELETE FROM refresh_tokens WHERE user_id = ?',
                [$userId]
            );

            ApiResponse::success([
                'message' => 'Logout successful. All tokens have been invalidated.'
            ]);
        } catch (\Exception) {
            ApiResponse::success([
                'message' => 'Logout successful.'
            ]);
        }
    }

    public function account(): void
    {
        $token = $this->getBearerToken();
        
        if (!$token) {
            ApiResponse::error('Authorization token is required.', 401);
            return;
        }

        try {
            $decoded = JWTService::validateJWTToken($token, $this->getSecretKey());
            
            $user = $this->db->fetchAssociative(
                'SELECT id, name, email, phone, role, created_at, updated_at FROM users WHERE id = ?',
                [(int)$decoded->sub]
            );

            if ($user === false) {
                ApiResponse::error('User not found.', 404);
                return;
            }

            ApiResponse::success([
                'user' => [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'phone' => $user['phone'],
                    'role' => $user['role'],
                    'created_at' => $user['created_at'],
                    'updated_at' => $user['updated_at']
                ]
            ]);
        } catch (\Exception $e) {
            ApiResponse::error($e->getMessage(), 401);
        }
    }

    public function updateProfile(): void
    {
        $token = $this->getBearerToken();
        
        if (!$token) {
            ApiResponse::error('Authorization token is required.', 401);
            return;
        }

        try {
            $decoded = JWTService::validateJWTToken($token, $this->getSecretKey());
            $userId = (int)$decoded->sub;
        } catch (\Exception $e) {
            ApiResponse::error($e->getMessage(), 401);
            return;
        }

        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $updateData = [];
        $params = [];

        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if (mb_strlen($name) >= 2) {
                $updateData[] = 'name = ?';
                $params[] = $name;
            }
        }

        if (isset($data['phone'])) {
            $phone = $data['phone'] === '' || $data['phone'] === null ? null : trim((string)$data['phone']);
            $updateData[] = 'phone = ?';
            $params[] = $phone;
        }

        if (empty($updateData)) {
            ApiResponse::error('No valid fields provided for update.', 422);
            return;
        }

        $updateData[] = 'updated_at = NOW()';
        $params[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $updateData) . ' WHERE id = ?';
        
        $result = $this->db->executeStatement($sql, $params);

        if ($result >= 0) {
            ApiResponse::success(['message' => 'Profile updated successfully.'], status: 200);
        } else {
            ApiResponse::error('Could not update profile.', 500);
        }
    }

    public function changePassword(): void
    {
        $token = $this->getBearerToken();
        
        if (!$token) {
            ApiResponse::error('Authorization token is required.', 401);
            return;
        }

        try {
            $decoded = JWTService::validateJWTToken($token, $this->getSecretKey());
            $userId = (int)$decoded->sub;
        } catch (\Exception $e) {
            ApiResponse::error($e->getMessage(), 401);
            return;
        }

        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $currentPassword = isset($data['current_password']) ? (string)$data['current_password'] : '';
        $newPassword = isset($data['new_password']) ? (string)$data['new_password'] : '';
        $confirmPassword = isset($data['confirm_password']) ? (string)$data['confirm_password'] : '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            ApiResponse::error('All password fields are required.', 422);
            return;
        }

        if (!$this->isStrongPassword($newPassword)) {
            ApiResponse::error('Password does not meet security requirements.', 422);
            return;
        }


        if ($newPassword !== $confirmPassword) {
            ApiResponse::error('New password and confirmation do not match.', 422);
            return;
        }

        $user = $this->db->fetchAssociative(
            'SELECT password FROM users WHERE id = ?',
            [$userId]
        );

        if (!password_verify($currentPassword, $user['password'])) {
            ApiResponse::error('Current password is incorrect.', 401);
            return;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->executeStatement(
            'UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?',
            [$hashedPassword, $userId]
        );

        // Invalidate all refresh tokens for security
        $this->db->executeStatement(
            'DELETE FROM refresh_tokens WHERE user_id = ?',
            [$userId]
        );

        ApiResponse::success([
            'message' => 'Password changed successfully. Please log in again.'
        ]);
    }

    private function isStrongPassword(string $password): bool 
    {
        return preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/',
            $password
        ) === 1;
    }


    /**
     * Refresh access token using a valid refresh token
     */
    public function refreshToken(): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        $refreshToken = isset($data['refreshToken']) ? trim((string)$data['refreshToken']) : '';
        
        if ($refreshToken === '') {
            ApiResponse::error('Refresh token is required.', 400);
            return;
        }

        try {
            // Validate refresh token with refresh secret
            $decoded = JWTService::validateJWTToken(
                $refreshToken,
                $this->getRefreshSecretKey()
            );
            
            $userId = (int)$decoded->sub;

            // Verify refresh token exists in database and is not revoked
            $storedToken = $this->db->fetchAssociative(
                'SELECT id, user_id, expires_at, revoked FROM refresh_tokens WHERE token = ? AND user_id = ?',
                [$refreshToken, $userId]
            );

            if ($storedToken === false) {
                ApiResponse::error('Invalid refresh token.', 401);
                return;
            }

            if ($storedToken['revoked']) {
                ApiResponse::error('Refresh token has been revoked.', 401);
                return;
            }

            // Check if token is expired
            $expiresAt = new \DateTime($storedToken['expires_at']);
            $now = new \DateTime();
            
            if ($now > $expiresAt) {
                ApiResponse::error('Refresh token has expired.', 401);
                return;
            }

            // Get fresh user data
            $user = $this->db->fetchAssociative(
                'SELECT id, name, email, role FROM users WHERE id = ?',
                [$userId]
            );

            if ($user === false) {
                ApiResponse::error('User not found.', 404);
                return;
            }

            // Generate new access token (and optionally a new refresh token)
            $accessToken = JWTService::generateJWTToken(
                $user,
                $this->getSecretKey(),
                $this->getAccessTokenLifetime()
            );

            // Optional: Rotate refresh token (generate new one and revoke old)
            $rotateRefreshToken = ($_ENV['ROTATE_REFRESH_TOKENS'] ?? 'false') === 'true';
            
            if ($rotateRefreshToken) {
                // Revoke old refresh token
                $this->db->executeStatement(
                    'UPDATE refresh_tokens SET revoked = 1 WHERE id = ?',
                    [$storedToken['id']]
                );

                // Generate new refresh token
                $newRefreshToken = JWTService::generateJWTToken(
                    $user,
                    $this->getRefreshSecretKey(),
                    $this->getRefreshTokenLifetime()
                );

                // Store new refresh token
                $this->storeRefreshToken($userId, $newRefreshToken);

                ApiResponse::success([
                    'message' => 'Token refreshed successfully.',
                    'accessToken' => $accessToken,
                    'refreshToken' => $newRefreshToken,
                    'expiresIn' => $this->getAccessTokenLifetime()
                ]);
            } else {
                // Just return new access token, keep same refresh token
                ApiResponse::success([
                    'message' => 'Token refreshed successfully.',
                    'accessToken' => $accessToken,
                    'expiresIn' => $this->getAccessTokenLifetime()
                ]);
            }
        } catch (\Exception $e) {
            ApiResponse::error('Invalid or expired refresh token: ' . $e->getMessage(), 401);
        }
    }

    // PRIVATE HELPER METHODS

    /**
     * Generate both access and refresh tokens
     */
    private function generateTokenPair(array $user): array
    {
        $accessToken = JWTService::generateJWTToken(
            $user,
            $this->getSecretKey(),
            $this->getAccessTokenLifetime()
        );

        $refreshToken = JWTService::generateJWTToken(
            $user,
            $this->getRefreshSecretKey(),
            $this->getRefreshTokenLifetime()
        );

        // Store refresh token in database
        $this->storeRefreshToken($user['id'], $refreshToken);

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken
        ];
    }

    /**
     * Store refresh token in database
     */
    private function storeRefreshToken(int $userId, string $token): void
    {
        $expiresAt = (new \DateTime())
            ->add(new \DateInterval('PT' . $this->getRefreshTokenLifetime() . 'S'));

        $this->db->executeStatement(
            'INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$userId, $token, $expiresAt->format('Y-m-d H:i:s')]
        );
    }

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

    private function validateRegistrationData(array $data): array
    {
        $errors = [];

        $name = isset($data['name']) ? trim((string)$data['name']) : '';
        if ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'Name must be at least 2 characters.';
        }

        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email address is required.';
        }

        // ✅ DEFINE PASSWORD HERE
        $password = isset($data['password']) ? (string)$data['password'] : '';

        if ($password === '') {
            $errors[] = 'Password is required.';
        } else {
            if (!$this->isStrongPassword($password)) {
                $errors[] = 'Password must be at least 8 characters and contain uppercase, lowercase, number and special character.';
            }
        }

        if (isset($data['password_confirmation'])) {
            $passwordConfirmation = (string)$data['password_confirmation'];
            if ($password !== $passwordConfirmation) {
                $errors[] = 'Password and confirmation do not match.';
            }
        }

        if (isset($data['phone']) && $data['phone'] !== null && $data['phone'] !== '') {
            $phone = trim((string)$data['phone']);
            if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
                $errors[] = 'Invalid phone number format.';
            }
        }

        if (isset($data['role'])) {
            $role = strtolower(trim((string)$data['role']));
            $validRoles = ['client', 'employee', 'admin'];
            if (!in_array($role, $validRoles, true)) {
                $errors[] = 'Role must be one of: ' . implode(', ', $validRoles);
            }
        }

        return $errors;
    }


    private function emailExists(string $email): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT id FROM users WHERE email = ?',
            [$email]
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

    private function getBearerToken(): ?string
    {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return $matches[1];
            }
        }
        
        if (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
}