<?php
require 'vendor/autoload.php';
require 'src/Services/ImporterService.php';
$records = App\Services\ImporterService::readExcelAsRecords('tmp_header_test/clientes_header.csv');
var_export($records);
