<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Initialize Environment and Database
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

\Config\Database::connect();

use App\Models\Company;
use App\Models\User;
use App\Controllers\AuthController;

try {
    // Create Test Company
    $company = Company::updateOrCreate(
        ['slug' => 'empresa-teste'],
        [
            'legal_name' => 'Empresa de Teste LTDA',
            'trade_name' => 'Empresa de Teste',
            'is_active' => true
        ]
    );

    // Create Test User
    $user = User::updateOrCreate(
        ['email' => 'teste@exemplo.com'],
        [
            'company_id' => $company->id,
            'full_name' => 'Usuário de Teste',
            'password_hash' => AuthController::hashPassword('senha123'),
            'role' => 'admin',
            'is_active' => true
        ]
    );

    echo "Usuário de teste criado com sucesso!\n";
    echo "E-mail: teste@exemplo.com\n";
    echo "Senha: senha123\n";
} catch (\Exception $e) {
    echo "Erro ao criar usuário: " . $e->getMessage() . "\n";
}
