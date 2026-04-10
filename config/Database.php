<?php
namespace Config;

use Illuminate\Database\Capsule\Manager as Capsule;

class Database {
    public static function connect() {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver'    => $_ENV['DB_CONNECTION'] ?? 'mysql',
            'host'      => $_ENV['DB_HOST'] ?? 'localhost',
            'port'      => (int) ($_ENV['DB_PORT'] ?? 3306),
            'database'  => $_ENV['DB_DATABASE'] ?? 'projeto_boleto',
            'username'  => $_ENV['DB_USERNAME'] ?? 'root',
            'password'  => $_ENV['DB_PASSWORD'] ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}
