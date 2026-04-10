<?php
header('Content-Type: application/json');
echo json_encode(['msg' => 'Direct access works', 'uri' => $_SERVER['REQUEST_URI']]);
