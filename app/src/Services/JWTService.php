<?php

namespace Services;

use Firebase\JWT\JWT;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

class JWTService
{
    static function generateJWTToken(int $userId, string $userName, ?array $rolesArray, string $secretKey, int $seconds = 60 * 60) : string
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + $seconds; // $seconds = 60 * 60 -> valid for 1 hour

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'sub' => $userId,
            'username' => $userName
        ];

        if ($rolesArray !== null) {
            $payload['roles'] = $rolesArray;
        }

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
