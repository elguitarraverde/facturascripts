<?php

use FacturaScripts\Core\Base\MiniLog;

require_once __DIR__ . '/config.php';
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', __DIR__);
}
require_once __DIR__ . '/vendor/autoload.php';

class BorrarDatosOtrasEmpresas
{
    public static function borrarDatos($idempresa, $codalmacen)
    {
        $database = new FacturaScripts\Core\Base\DataBase();
        $database->connect();

        // TODO HAY QUE ELIMINAR LAS FACTURAS POR LOS MODELOS PARA QUE BORRE ASIENTOS, PAGOS, ETC DE UN TIRON

        // eliminar restriccion de integridad referencial
//        $sql = "SET FOREIGN_KEY_CHECKS=0;";
//        $database->exec($sql);

        $empresa = new FacturaScripts\Core\Model\Empresa();
        $empresa->loadFromCode($idempresa);

        $tabla = 'facturascli';
        $sql = "DELETE FROM $tabla WHERE idempresa <> $idempresa ORDER BY idfactura DESC;";
        $ok = $database->exec($sql);
        if(false === $ok){
            dd($sql, MiniLog::read('database', ['error']));
        }

        $tabla = 'almacenes';
        $sql = "DELETE FROM $tabla WHERE idempresa <> $idempresa;";
        $ok = $database->exec($sql);
        if(false === $ok){
            dd($sql, MiniLog::read('database', ['error']));
        }

        $tabla = 'empresas';
        $sql = "DELETE FROM $tabla WHERE idempresa <> $idempresa;";
        $ok = $database->exec($sql);
        if(false === $ok){
            dd($sql, MiniLog::read('database', ['error']));
        }

        foreach ($database->getTables() as $tabla) {
            MiniLog::clear();

            // consultar si existe el campo idempresa
            $columnas = $database->select("SHOW COLUMNS FROM $tabla;");
            $columnas = array_column($columnas, 'Field');

            if(in_array('idempresa', $columnas, true)){
                // borrar datos de otras empresas
                $sql = "DELETE FROM $tabla WHERE idempresa <> $idempresa;";
                $ok = $database->exec($sql);
                if(false === $ok){
                    dump($sql, MiniLog::read('database', ['error']));
                }
            }

            if(in_array('codalmacen', $columnas, true)){
                // borrar datos de otras empresas
                $sql = "DELETE FROM $tabla WHERE codalmacen <> \"$codalmacen\";";
                $ok = $database->exec($sql);
                if(false === $ok){
                    dump($sql, MiniLog::read('database', ['error']));
                }
            }
        }

        // restaurar restriccion de integridad referencial
//        $sql = "SET FOREIGN_KEY_CHECKS=1;";
//        $database->exec($sql);
    }
}

$idempresa = 1;
$codalmacen = 'ALG';
BorrarDatosOtrasEmpresas::borrarDatos($idempresa, $codalmacen);
