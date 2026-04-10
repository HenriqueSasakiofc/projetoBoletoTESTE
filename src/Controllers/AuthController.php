<?php
namespace App\Controllers;

use App\Models\User;
use Firebase\JWT\JWT;

class AuthController {
    
    // Equivalent to hash_password in Python
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT);
    }
    
    // Equivalent to verify_password in Python
    public static function verifyPassword(string $plainPassword, string $hash): bool {
        return password_verify($plainPassword, $hash);
    }

    public function login() {
        header('Content-Type: application/json');
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['email']) || !isset($input['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Email e senha são obrigatórios.']);
            return;
        }
        
        $email = trim(strtolower($input['email']));
        $password = $input['password'];
        
        // Find user by email
        $user = User::where('email', $email)->where('is_active', true)->first();
        
        if (!$user || !self::verifyPassword($password, $user->password_hash)) {
            http_response_code(401);
            echo json_encode(['error' => 'Credenciais inválidas.']);
            return;
        }
        
        // Generate JWT Token
        $issuedAt = time();
        $expire = $issuedAt + (($_ENV['ACCESS_TOKEN_EXPIRE_MINUTES'] ?? 1440) * 60);
        $secretKey = $_ENV['SECRET_KEY'] ?? 'fallback_secret_key_change_me';
        
        $payload = [
            'iat'  => $issuedAt,
            'exp'  => $expire,
            'sub'  => (string) $user->id,
            'company_id' => $user->company_id,
            'role' => $user->role
        ];
        
        $jwt = JWT::encode($payload, $secretKey, 'HS256');
        
        echo json_encode([
            'access_token' => $jwt,
            'token_type' => 'bearer',
            'user' => [
                'full_name' => $user->full_name,
                'email' => $user->email
            ]
        ]);
    }

    public function register() {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'error' => 'Cadastro publico de empresa desativado. Solicite a criacao manual do acesso.'
        ]);
        return;
        /*
        
        $companyName = $input['company_name'] ?? '';
        $adminFullName = $input['admin_full_name'] ?? '';
        $adminEmail = trim(strtolower($input['admin_email'] ?? ''));
        $adminPassword = $input['admin_password'] ?? '';
        
        if (!$companyName || !$adminEmail || !$adminPassword) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome da empresa, e-mail e senha são obrigatórios.']);
            return;
        }

        try {
            $company = \App\Models\Company::create([
                'slug' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $companyName)),
                'legal_name' => $companyName,
                'trade_name' => $companyName
            ]);

            $user = \App\Models\User::create([
                'company_id' => $company->id,
                'email' => $adminEmail,
                'full_name' => $adminFullName,
                'password_hash' => self::hashPassword($adminPassword),
                'role' => 'admin',
                'is_active' => true
            ]);

            // Auto-login after register
            $issuedAt = time();
            $expire = $issuedAt + (($_ENV['ACCESS_TOKEN_EXPIRE_MINUTES'] ?? 1440) * 60);
            $secretKey = $_ENV['SECRET_KEY'] ?? 'fallback_secret_key_change_me';
            
            $payload = [
                'iat'  => $issuedAt,
                'exp'  => $expire,
                'sub'  => (string) $user->id,
                'company_id' => $user->company_id,
                'role' => $user->role
            ];
            
            $jwt = \Firebase\JWT\JWT::encode($payload, $secretKey, 'HS256');

            echo json_encode([
                'access_token' => $jwt,
                'token_type' => 'bearer',
                'user' => [
                    'full_name' => $user->full_name,
                    'email' => $user->email
                ]
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar conta: ' . $e->getMessage()]);
        }
        */
    }
}
