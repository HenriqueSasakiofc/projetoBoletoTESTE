<?php
namespace App\Support;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    public static function bearerToken(): ?string {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return trim(substr($authHeader, 7));
    }

    public static function payloadFromRequest(): ?object {
        $token = self::bearerToken();
        if (!$token) {
            return null;
        }

        try {
            $secretKey = $_ENV['SECRET_KEY'] ?? 'fallback_secret_key_change_me';
            return JWT::decode($token, new Key($secretKey, 'HS256'));
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function userFromRequest(): ?User {
        $payload = self::payloadFromRequest();

        if (!$payload || empty($payload->sub) || empty($payload->company_id)) {
            return null;
        }

        return User::where('id', (int) $payload->sub)
            ->where('company_id', (int) $payload->company_id)
            ->where('is_active', true)
            ->first();
    }
}
