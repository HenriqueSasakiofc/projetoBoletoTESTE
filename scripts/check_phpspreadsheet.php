<?php
require 'vendor/autoload.php';
$r = new ReflectionClass('PhpOffice\PhpSpreadsheet\Worksheet\Worksheet');
$methods = array_filter($r->getMethods(), fn($m) => str_contains($m->getName(), 'Cell'));
foreach ($methods as $m) {
    echo $m->getName() . PHP_EOL;
}
