<?php

use FacturaScripts\Core\Base\DataBase;

require_once __DIR__ . '/vendor/autoload.php';
const FS_FOLDER = __DIR__;
require_once __DIR__ . '/config.php';

$database = new DataBase();
$database->connect();

$database->beginTransaction();
$database->exec('SET FOREIGN_KEY_CHECKS = 0;');

$tables = [
    'albaranesprov',
    'albaranescli',
    'facturascli',
    'facturasprov',
    'pedidoscli',
    'pedidosprov',
    'lineasalbaranesprov',
    'lineasalbaranescli',
    'lineasfacturascli',
    'lineasfacturasprov',
    'lineaspedidoscli',
    'lineaspedidosprov',
    'logs',
    'activitylogs',
    'partidas',
    'stocks_movimientos',
    'asientos',
    'recibospagoscli',
    'pagoscli',
    'tickets_docs',
    'doctransformations',
    'productos_lotes',
    'productos_lotes_movs',
    'mili_puntos_movimientos',
    'stocks_lineasconteos',
    'stocks',
    'stocks_conteos',
    'stocks_lineasconteos_traza',
    'work_events'
];
foreach ($tables as $table) {
    $database->exec("TRUNCATE TABLE `$table`");
}

$database->exec('SET FOREIGN_KEY_CHECKS = 1;');
$database->commit();
