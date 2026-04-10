<?php
require 'vendor/autoload.php';
require 'src/Services/ImporterService.php';
$values = ['1,850.00', '1,450.00', '620.40', '350,90'];
foreach ($values as $value) {
    echo $value . ' => ' . App\Services\ImporterService::parseDecimal($value) . PHP_EOL;
}
