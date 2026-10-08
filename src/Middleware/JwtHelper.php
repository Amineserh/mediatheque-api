<?php

declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtHelper
{
    private static string $secretKey = 'ITcampus-SaintMichel-Annecy-BTS-CIEL-2026-BTS-CIEL-2026-BTS-CIEL-2026';
    private static string $algorithm = 'HS256';

    public static function generateToken(array $data, int $expiry = 3600): string
    {
        $issuedAt = time();

        $payload = [
            'iat'  => $issuedAt,
            'exp'  => $issuedAt + $expiry,
            'data' => $data,
        ];

        return JWT::encode($payload, self::$secretKey, self::$algorithm);
    }

    public static function validateToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key(self::$secretKey, self::$algorithm));
        } catch (\Exception $e) {
            return null;
        }
    }
}
