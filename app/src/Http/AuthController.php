<?php

namespace Http;

use \Services\ApiResponse;
use \Services\JWTService;

class AuthController extends BaseController
{

    private function getSecretKey(): string
    {
        $key = $_ENV['JWT_SECRET_KEY'] ?? null;
        if (!$key || strlen($key) < 32) {
            throw new \Exception('JWT_SECRET_KEY must be at least 32 characters. Generate one with: openssl rand -base64 64');
        }
        return $key;
    }

    private function getRefreshSecretKey(): string
    {
        $key = $_ENV['JWT_REFRESH_SECRET_KEY'] ?? $this->getSecretKey();
        if (!$key || strlen($key) < 32) {
            throw new \Exception('JWT_REFRESH_SECRET_KEY must be at least 32 characters.');
        }
        return $key;
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
                $tokens = $this->generateTokenPair($user);
                
                $this->setRefreshTokenCookie($tokens['refreshToken']);
                
                ApiResponse::success([
                    'message' => 'User registered successfully.',
                    'user' => [
                        'id' => $userId,
                        'name' => $name,
                        'email' => $email,
                        'role' => $role
                    ],
                    'accessToken' => $tokens['accessToken'],
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
            ApiResponse::error('Username and / or password are incorrect.', 401);
            return;
        }

        if (!password_verify($password, $user['password'])) {
            ApiResponse::error('Username and / or password are incorrect.', 401);
            return;
        }

        $tokens = $this->generateTokenPair($user);

        $this->setRefreshTokenCookie($tokens['refreshToken']);

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
            'expiresIn' => $this->getAccessTokenLifetime()
        ]);
    }

    public function logout(): void
    {
        $token = $this->getBearerToken();
        
        $userId = null;
        if ($token) {
            try {
                $decoded = JWTService::validateJWTToken($token, $this->getSecretKey());
                $userId = (int)$decoded->sub;
            } catch (\Exception) {
            }
        }
        
        if ($userId === null) {
            $refreshToken = $_COOKIE['refreshToken'] ?? '';
            if ($refreshToken !== '') {
                try {
                    $decoded = JWTService::validateJWTToken($refreshToken, $this->getRefreshSecretKey());
                    $userId = (int)$decoded->sub;
                } catch (\Exception) {
                }
            }
        }
        
        if ($userId !== null) {
            $this->db->executeStatement(
                'DELETE FROM refresh_tokens WHERE user_id = ?',
                [$userId]
            );
        }

        $this->clearRefreshTokenCookie();

        ApiResponse::success([
            'message' => 'Logout successful.'
        ]);
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

    public function refreshToken(): void
    {
        $refreshToken = $_COOKIE['refreshToken'] ?? '';
        
        if ($refreshToken === '') {
            ApiResponse::error('No refresh token provided.', 401);
            return;
        }

        try {
            $decoded = JWTService::validateJWTToken(
                $refreshToken,
                $this->getRefreshSecretKey()
            );
            
            $userId = (int)$decoded->sub;

            $deleted = $this->db->executeStatement(
                'DELETE FROM refresh_tokens WHERE token = ?',
                [$refreshToken]
            );

            if ($deleted === 0) {
                ApiResponse::error('Invalid or already used refresh token.', 401);
                return;
            }

            $user = $this->db->fetchAssociative(
                'SELECT id, name, email, role FROM users WHERE id = ?',
                [$userId]
            );

            if ($user === false) {
                ApiResponse::error('User not found.', 404);
                return;
            }

            $accessToken = JWTService::generateJWTToken(
                $user,
                $this->getSecretKey(),
                $this->getAccessTokenLifetime()
            );

            $newRefreshToken = JWTService::generateJWTToken(
                $user,
                $this->getRefreshSecretKey(),
                $this->getRefreshTokenLifetime()
            );

            $this->storeRefreshToken($userId, $newRefreshToken);
            $this->setRefreshTokenCookie($newRefreshToken);

            ApiResponse::success([
                'message' => 'Token refreshed successfully.',
                'accessToken' => $accessToken,
                'expiresIn' => $this->getAccessTokenLifetime()
            ]);
        } catch (\Exception $e) {
            try {
                $this->db->executeStatement(
                    'DELETE FROM refresh_tokens WHERE token = ?',
                    [$refreshToken]
                );
            } catch (\Exception) {
            }
            
            $this->cleanupExpiredTokens();
            
            ApiResponse::error('Invalid or expired refresh token.', 401);
        }
    }


    private function cleanupExpiredTokens(): void
    {
        try {
            $tokens = $this->db->fetchAllAssociative('SELECT id, token FROM refresh_tokens');
            
            foreach ($tokens as $row) {
                try {
                    JWTService::validateJWTToken($row['token'], $this->getRefreshSecretKey());
                } catch (\Exception) {
                    $this->db->executeStatement('DELETE FROM refresh_tokens WHERE id = ?', [$row['id']]);
                }
            }
        } catch (\Exception) {
        
        }
    }


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

        $this->storeRefreshToken($user['id'], $refreshToken);

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken
        ];
    }

    private function storeRefreshToken(int $userId, string $token): void
    {
        $expiresAt = (new \DateTime())
            ->add(new \DateInterval('PT' . $this->getRefreshTokenLifetime() . 'S'));

        $this->db->executeStatement(
            'INSERT INTO refresh_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$userId, $token, $expiresAt->format('Y-m-d H:i:s')]
        );
    }

    private function setRefreshTokenCookie(string $refreshToken): void
    {
        $lifetime = $this->getRefreshTokenLifetime();
        $secure = ($_ENV['COOKIE_SECURE'] ?? 'false') === 'true'; // true in production with HTTPS
        
        setcookie(
            'refreshToken',           // name
            $refreshToken,            // value
            time() + $lifetime,       // expires
            '/',                      // path
            '',                       // domain (empty = current domain)
            $secure,                  // secure (HTTPS only)
            true                      // httponly (not accessible via JavaScript)
        );
    }

    private function clearRefreshTokenCookie(): void
    {
        $secure = ($_ENV['COOKIE_SECURE'] ?? 'false') === 'true';
        
        setcookie(
            'refreshToken',           // name
            '',                       // empty value
            time() - 3600,            // expires in the past
            '/',                      // path
            '',                       // domain
            $secure,                  // secure
            true                      // httponly
        );
    }

    private function getLastInsertId(): int
    {
        return (int)$this->db->lastInsertId();
    }

    private function emailExists(string $email): bool
    {
        return (bool)$this->db->fetchOne(
            'SELECT COUNT(*) FROM users WHERE email = ?',
            [strtolower(trim($email))]
        );
    }
    private function isStrongPassword(string $password): bool 
    {
        return preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/',
            $password
        ) === 1;
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

        $password = isset($data['password']) ? (string)$data['password'] : '';

        if ($password === '') {
            $errors[] = 'Password is required.';
        } else {
            if (!$this->isStrongPassword($password)) {
                $errors[] = 'Password must be at least 8 characters and contain uppercase, lowercase, number and special character.';
            }
        }

        if (isset($data['password_confirm'])) {
            $passwordConfirmation = (string)$data['password_confirm'];
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