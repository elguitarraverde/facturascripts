<?php declare(strict_types=1);

namespace FacturaScripts\Core\Controller;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Model\AlbaranCliente;
use FacturaScripts\Core\Model\AlbaranProveedor;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\PedidoCliente;
use FacturaScripts\Core\Model\PedidoProveedor;
use FacturaScripts\Core\Model\PresupuestoCliente;
use FacturaScripts\Core\Model\PresupuestoProveedor;
use FacturaScripts\Core\Model\Proveedor;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Dinamic\Model\Cliente;

class ApiCreateDocument extends ApiController
{
    protected function runResource(): void
    {
        $documentTypes = [
            'AlbaranCliente' => AlbaranCliente::class,
            'PresupuestoCliente' => PresupuestoCliente::class,
            'PedidoCliente' => PedidoCliente::class,
            'FacturaCliente' => FacturaCliente::class,
            'AlbaranProveedor' => AlbaranProveedor::class,
            'PresupuestoProveedor' => PresupuestoProveedor::class,
            'PedidoProveedor' => PedidoProveedor::class,
            'FacturaProveedor' => FacturaProveedor::class,
        ];

        $doc = null;
        $docType = null;

        foreach ($documentTypes as $type => $class) {
            if (str_contains($this->url, $type)) {
                $doc = new $class();
                $docType = $type;
                break;
            }
        }

        // si el método no es POST o PUT, devolvemos un error
        if (!in_array($this->request->method(), ['POST', 'PUT'])) {
            $this->response->setHttpCode(Response::HTTP_METHOD_NOT_ALLOWED);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Method not allowed',
            ]));
            return;
        }

        $subject = $this->loadClienteOProveedor();
        if (!$subject) {
            return;
        }
        $doc->setSubject($subject);

        // asignamos el almacén
        $codalmacen = $this->request->get('codalmacen');
        if ($codalmacen && false === $doc->setWarehouse($codalmacen)) {
            $this->response->setHttpCode(Response::HTTP_NOT_FOUND);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Warehouse not found',
            ]));
            return;
        }

        // asignamos la fecha
        $fecha = $this->request->get('fecha');
        $hora = $this->request->get('hora', $doc->hora);
        if ($fecha && false === $doc->setDate($fecha, $hora)) {
            $this->response->setHttpCode(Response::HTTP_BAD_REQUEST);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Invalid date',
            ]));
            return;
        }

        // asignamos la divisa
        $coddivisa = $this->request->get('coddivisa');
        if ($coddivisa) {
            $doc->setCurrency($coddivisa);
        }

        // asignamos el resto de campos del modelo
        foreach ($doc->getModelFields() as $key => $field) {
            if ($this->request->request->has($key)) {
                $doc->{$key} = $this->request->request->get($key);
            }
        }

        $db = new DataBase();
        $db->beginTransaction();

        // guardamos el documento
        if (false === $doc->save()) {
            $this->response->setHttpCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Error saving the invoice',
            ]));
            $db->rollback();
            return;
        }

        // guardamos las líneas
        if (false === $this->saveLines($doc)) {
            $db->rollback();
            return;
        }

        // ¿Está pagada?
        if ($this->request->get('pagada', false)) {
            foreach ($doc->getReceipts() as $receipt) {
                $receipt->pagado = true;
                $receipt->save();
            }

            // recargamos el documento
            $doc->loadFromCode($doc->idfactura);
        }

        $db->commit();

        // devolvemos la respuesta
        $this->response->setContent(json_encode([
            'doc-type' => $docType,
            'url' => strtolower($docType) . '/' . $doc->primaryColumnValue(),
            'doc' => $doc->toArray(),
            'lines' => $doc->getLines(),
        ]));
    }

    protected function saveLines(&$doc): bool
    {
        if (!$this->request->request->has('lineas')) {
            $this->response->setHttpCode(Response::HTTP_BAD_REQUEST);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'lineas field is required',
            ]));
            return false;
        }

        $lineData = $this->request->request->get('lineas');
        $lineas = json_decode($lineData, true);
        if (!is_array($lineas)) {
            $this->response->setHttpCode(Response::HTTP_BAD_REQUEST);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Invalid lines',
            ]));
            return false;
        }

        $newLines = [];
        foreach ($lineas as $line) {
            $newLine = empty($line['referencia'] ?? '') ?
                $doc->getNewLine() :
                $doc->getNewProductLine($line['referencia']);

            $newLine->cantidad = (float)($line['cantidad'] ?? 1);
            $newLine->descripcion = $line['descripcion'] ?? $newLine->descripcion ?? '?';
            $newLine->pvpunitario = (float)($line['pvpunitario'] ?? $newLine->pvpunitario);
            $newLine->dtopor = (float)($line['dtopor'] ?? $newLine->dtopor);
            $newLine->dtopor2 = (float)($line['dtopor2'] ?? $newLine->dtopor2);

            if (!empty($line['excepcioniva'] ?? '')) {
                $newLine->excepcioniva = $line['excepcioniva'];
            }

            if (!empty($line['codimpuesto'] ?? '')) {
                $newLine->codimpuesto = $line['codimpuesto'];
            }

            if (!empty($line['suplido'] ?? '')) {
                $newLine->suplido = (bool)$line['suplido'];
            }

            if (!empty($line['mostrar_cantidad'] ?? '')) {
                $newLine->mostrar_cantidad = (bool)$line['mostrar_cantidad'];
            }

            if (!empty($line['mostrar_precio'] ?? '')) {
                $newLine->mostrar_precio = (bool)$line['mostrar_precio'];
            }

            if (!empty($line['salto_pagina'] ?? '')) {
                $newLine->salto_pagina = (bool)$line['salto_pagina'];
            }

            $newLines[] = $newLine;
        }

        // actualizamos los totales y guardamos
        if (false === Calculator::calculate($doc, $newLines, true)) {
            $this->response->setHttpCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            $this->response->setContent(json_encode([
                'status' => 'error',
                'message' => 'Error calculating the invoice',
            ]));
            return false;
        }

        return true;
    }

    /**
     * Carga una entidad (Cliente o Proveedor) a partir de los datos de la request.
     *
     * @return Cliente|Proveedor|null
     */
    private function loadClienteOProveedor()
    {
        // Mapa que relaciona los campos de la request con las clases correspondientes
        $map = [
            'codcliente' => Cliente::class,
            'codproveedor' => Proveedor::class,
        ];

        foreach ($map as $field => $class) {
            $code = $this->request->get($field);

            // Si el campo está presente en la request
            if (!empty($code)) {
                $entidad = new $class();

                // Intentamos cargar la entidad desde su código
                if (!$entidad->loadFromCode($code)) {
                    $this->response->setHttpCode(Response::HTTP_NOT_FOUND);
                    $this->response->setContent(json_encode([
                        'status' => 'error',
                        'message' => ucfirst($field) . ' not found',
                    ]));
                    return null;
                }

                // Entidad encontrada y cargada correctamente
                return $entidad;
            }
        }

        // Ninguno de los campos esperados vino en la request
        $this->response->setHttpCode(Response::HTTP_BAD_REQUEST);
        $this->response->setContent(json_encode([
            'status' => 'error',
            'message' => 'codcliente or codproveedor field is required',
        ]));
        return null;
    }
}
