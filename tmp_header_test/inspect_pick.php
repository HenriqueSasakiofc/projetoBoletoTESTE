<?php
require 'vendor/autoload.php';
require 'src/Services/ImporterService.php';
$records = App\Services\ImporterService::readExcelAsRecords('tmp_header_test/clientes_header.csv');
$service = new ReflectionClass('App\\Services\\ImporterService');
$method = $service->getMethod('pickValue');
$method->setAccessible(true);
$row = $records[0];
echo $method->invoke(null, $row, ['email_para_cobranca', 'email para cobranca', 'email para cobrança', 'email_cobranca', 'email', 'e_mail']), PHP_EOL;
