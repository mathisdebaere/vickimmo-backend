<?php

namespace Services;

use Firebase\JWT\JWT;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

class JWTService
{
    static function generateJWTToken(array $user, string $secretKey, int $seconds = 60) : string
    {

        $expirationTime = time() + $seconds;
        
        $payload = [
            'iat' => time(),
            'exp' => $expirationTime,
            'sub' => $user['id'],
            'data' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'] ?? 'user',             
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];

        return JWT::encode($payload, $secretKey, 'HS256');
    }

    static function validateJWTToken(string $jwtToken, string $secretKey) : \stdClass
    {
        try {
            return JWT::decode($jwtToken,  new Key($secretKey, 'HS256'));
        } catch (ExpiredException $e) {
            throw new \Exception('Token expired');
        } catch (SignatureInvalidException $e) {
            throw new \Exception('Invalid token signature');
        } catch (BeforeValidException $e) {
            throw new \Exception('Token not valid yet');
        } catch (\Exception $e) {
            throw new \Exception('Invalid token');
        }
    }
}
