<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

define("FS_FOLDER", getcwd());

require_once __DIR__ . '/../vendor/autoload.php';

$config = FS_FOLDER . '/config-mysql.php';
if (!file_exists($config)) {
    die($config . " not found!\n");
}

require_once $config;

// connect to database
$db = new DataBase();
$db->connect();

// clean cache
Cache::clear();

// iniciamos el kernel
Kernel::init();

// load Init file for every plugin
Plugins::init();

new \FacturaScripts\Dinamic\Model\User();
new \FacturaScripts\Dinamic\Model\Serie();
new \FacturaScripts\Dinamic\Model\Almacen();
new \FacturaScripts\Dinamic\Model\ApiKey();
new \FacturaScripts\Dinamic\Model\Cliente();
new \FacturaScripts\Dinamic\Model\Divisa();
new \FacturaScripts\Dinamic\Model\Almacen();
new \FacturaScripts\Dinamic\Model\TpvTerminal();
new \FacturaScripts\Dinamic\Model\TpvCaja();
new \FacturaScripts\Dinamic\Model\ShopeameOrder();
new \FacturaScripts\Dinamic\Model\FacturaCliente();
new \FacturaScripts\Dinamic\Model\LineaPresupuestoCliente();
new \FacturaScripts\Dinamic\Model\LineaPresupuestoProveedor();
new \FacturaScripts\Dinamic\Model\LineaFacturaCliente();
new \FacturaScripts\Dinamic\Model\LineaFacturaProveedor();
