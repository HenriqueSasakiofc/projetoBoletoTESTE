<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\AuthController;
use App\Models\Company;
use App\Models\User;
use Dotenv\Dotenv;

function usage(): void {
    echo "Uso:\n";
    echo "  php scripts/create_company_admin.php --company-name=\"Empresa X\" --admin-name=\"Admin\" --admin-email=\"admin@empresa.com\" --admin-password=\"senha-forte\"\n";
    echo "\n";
    echo "Opcoes:\n";
    echo "  --company-name     Nome legal da empresa (obrigatorio)\n";
    echo "  --slug             Slug da empresa. Se omitido, sera gerado pelo nome\n";
    echo "  --trade-name       Nome fantasia. Se omitido, usa o nome da empresa\n";
    echo "  --admin-name       Nome completo do usuario administrador (obrigatorio)\n";
    echo "  --admin-email      E-mail do usuario administrador (obrigatorio)\n";
    echo "  --admin-password   Senha do usuario administrador (obrigatorio)\n";
    echo "  --role             Papel do usuario. Padrao: ADMIN\n";
    echo "  --inactive         Cria empresa e usuario como inativos\n";
    echo "  --help             Mostra esta ajuda\n";
    echo "\n";
    echo "Observacao:\n";
    echo "  O login atual autentica apenas por e-mail e senha. Por isso, este script bloqueia o uso do mesmo e-mail em empresas diferentes.\n";
}

function makeSlug(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value ?? '');
    return trim((string) $value, '-');
}

$options = getopt('', [
    'help',
    'company-name:',
    'slug::',
    'trade-name::',
    'admin-name:',
    'admin-email:',
    'admin-password:',
    'role::',
    'inactive',
]);

if (isset($options['help'])) {
    usage();
    exit(0);
}

$companyName = trim((string) ($options['company-name'] ?? ''));
$adminName = trim((string) ($options['admin-name'] ?? ''));
$adminEmail = strtolower(trim((string) ($options['admin-email'] ?? '')));
$adminPassword = (string) ($options['admin-password'] ?? '');
$slug = trim((string) ($options['slug'] ?? ''));
$tradeName = trim((string) ($options['trade-name'] ?? ''));
$role = strtoupper(trim((string) ($options['role'] ?? 'ADMIN')));
$isActive = !isset($options['inactive']);

if ($companyName === '' || $adminName === '' || $adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "Parametros obrigatorios ausentes.\n\n");
    usage();
    exit(1);
}

if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "E-mail invalido: {$adminEmail}\n");
    exit(1);
}

if ($slug === '') {
    $slug = makeSlug($companyName);
}

if ($slug === '') {
    fwrite(STDERR, "Nao foi possivel gerar um slug valido para a empresa.\n");
    exit(1);
}

if ($tradeName === '') {
    $tradeName = $companyName;
}

$allowedRoles = ['ADMIN', 'IMPORTER', 'APPROVER', 'SENDER', 'AUDITOR', 'CLIENT_OPERATOR'];
if (!in_array($role, $allowedRoles, true)) {
    fwrite(STDERR, "Role invalida: {$role}\n");
    fwrite(STDERR, "Roles permitidas: " . implode(', ', $allowedRoles) . "\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
\Config\Database::connect();

try {
    $company = Company::firstOrNew(['slug' => $slug]);
    $companyWasCreated = !$company->exists;

    $company->legal_name = $companyName;
    $company->trade_name = $tradeName;
    $company->is_active = $isActive;
    $company->save();

    $emailInOtherCompany = User::where('email', $adminEmail)
        ->where('company_id', '!=', $company->id)
        ->first();

    if ($emailInOtherCompany) {
        fwrite(
            STDERR,
            "O e-mail {$adminEmail} ja esta vinculado a outra empresa (company_id={$emailInOtherCompany->company_id}). " .
            "Como o login atual usa apenas e-mail e senha, cada e-mail precisa ser unico no sistema.\n"
        );
        exit(1);
    }

    $user = User::where('company_id', $company->id)
        ->where('email', $adminEmail)
        ->first();

    $userWasCreated = $user === null;

    if (!$user) {
        $user = new User();
        $user->company_id = $company->id;
        $user->email = $adminEmail;
    }

    $user->full_name = $adminName;
    $user->password_hash = AuthController::hashPassword($adminPassword);
    $user->role = $role;
    $user->is_active = $isActive;
    $user->save();

    echo ($companyWasCreated ? "Empresa criada" : "Empresa atualizada") . " com sucesso.\n";
    echo "  ID: {$company->id}\n";
    echo "  Slug: {$company->slug}\n";
    echo "  Razao social: {$company->legal_name}\n";
    echo "  Nome fantasia: {$company->trade_name}\n";
    echo "  Ativa: " . ($company->is_active ? 'sim' : 'nao') . "\n";
    echo "\n";
    echo ($userWasCreated ? "Usuario criado" : "Usuario atualizado") . " com sucesso.\n";
    echo "  ID: {$user->id}\n";
    echo "  Empresa ID: {$user->company_id}\n";
    echo "  Nome: {$user->full_name}\n";
    echo "  E-mail: {$user->email}\n";
    echo "  Role: {$user->role}\n";
    echo "  Ativo: " . ($user->is_active ? 'sim' : 'nao') . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Erro ao provisionar empresa/usuario: {$e->getMessage()}\n");
    exit(1);
}
