<?php

namespace FacturaScripts\Test\Plugins;

require_once __DIR__ . '/AbstractPluginTestCase.php';

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\AlbaranCliente;
use FacturaScripts\Core\Model\FormaPago;
use FacturaScripts\Core\Session;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\MiliValeMovimiento;
use FacturaScripts\Dinamic\Model\PrePagoCli;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Lib\GestionVales;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Lib\TPVneo\SaleForm;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Model\MiliVale;

class GestionValesAlbaranClienteTest extends AbstractPluginTestCase
{
    private static FormaPago $formaPagoVales;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        new TpvTerminal();

        self::$formaPagoVales = new FormaPago();
        self::$formaPagoVales->descripcion = 'Forma de pago de vales albaran';
        self::$formaPagoVales->codpago = 'VALALB';
        self::assertTrue(self::$formaPagoVales->save());

        $empresa = new Empresa();
        $empresa->loadFromCode(1);
        $empresa->codpagovales = self::$formaPagoVales->codpago;
        self::assertTrue($empresa->save());

        GestionVales::crearProductoVale();
    }

    public function testCrearValeDesdeSaleFormEnAlbaranCliente(): void
    {
        $cliente = $this->createCustomer();
        $user = Session::user();
        $terminal = $this->createTerminalAlbaranCliente($cliente->codcliente);
        $caja = $this->createCajaForTerminal($terminal);
        $agente = $this->createAgent();
        $productoVale = $this->getValeProduct();

        $formData = [
            'action' => 'save-cart',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'formasPagos' => 1,
            'PAYPAL' => 50,
            'referencia_1' => GestionVales::REFERENCIA_VALE,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $productoVale->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $productoVale->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 50,
        ];

        $this->assertTrue(SaleForm::saveDoc($formData, $user, $caja, $agente->codagente));

        /** @var AlbaranCliente $albaran */
        $albaran = SaleForm::getDoc();
        $this->assertInstanceOf(AlbaranCliente::class, $albaran);

        $lineas = $albaran->getLines();
        $this->assertCount(1, $lineas);
        $this->assertSame(GestionVales::REFERENCIA_VALE, $lineas[0]->referencia);

        $vale = GestionVales::getValeFromLine($lineas[0]);
        $this->assertNotNull($vale);

        $valeEnBaseDeDatos = new MiliVale();
        $this->assertTrue($valeEnBaseDeDatos->loadFromCode($vale->id));
        $this->assertEquals($albaran->primaryColumnValue(), $valeEnBaseDeDatos->iddoc);
        $this->assertSame($albaran->modelClassName(), $valeEnBaseDeDatos->modeldoc);
        $this->assertEquals($lineas[0]->primaryColumnValue(), $valeEnBaseDeDatos->idlinea);
        $this->assertSame($lineas[0]->modelClassName(), $valeEnBaseDeDatos->modellinea);
        $this->assertSame($cliente->codcliente, $valeEnBaseDeDatos->codcliente);
        $this->assertSame($albaran->idempresa, $valeEnBaseDeDatos->idempresa);
        $this->assertEquals(50.0, $valeEnBaseDeDatos->saldoinicial);
        $this->assertEquals(50.0, $valeEnBaseDeDatos->saldoactual);
    }

    public function testConsumoValeEnAlbaranClienteRegistraMovimientoYDescuentaSaldo(): void
    {
        $cliente = $this->createCustomer();
        $user = Session::user();
        $agente = $this->createAgent();

        $terminalCompra = $this->createTerminalAlbaranCliente($cliente->codcliente);
        $cajaCompra = $this->createCajaForTerminal($terminalCompra);
        $productoVale = $this->getValeProduct();

        $compraValeData = [
            'action' => 'save-cart',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'formasPagos' => 1,
            'PAYPAL' => 50,
            'referencia_1' => GestionVales::REFERENCIA_VALE,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $productoVale->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $productoVale->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 50,
        ];

        $this->assertTrue(SaleForm::saveDoc($compraValeData, $user, $cajaCompra, $agente->codagente));
        /** @var AlbaranCliente $albaranVale */
        $albaranVale = SaleForm::getDoc();
        $vale = GestionVales::getValeFromLine($albaranVale->getLines()[0]);
        $this->assertNotNull($vale);
        $this->assertEquals(50.0, $vale->saldoactual);

        $terminalVenta = $this->createTerminalAlbaranCliente($cliente->codcliente);
        $cajaVenta = $this->createCajaForTerminal($terminalVenta);
        $ventaData = [
            'action' => 'save-cart',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'referencia_1' => '',
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => 'Producto test',
            'idlinea_1' => 1,
            'codimpuesto_1' => '',
            'new_precio_1' => '',
            'pvpunitario_1' => 25,
            'formasPagos' => 2,
            self::$formaPagoVales->codpago => 25,
            'PAYPAL' => 5.25,
            'codigovale' => $vale->codigo,
            'codpago' => self::$formaPagoVales->codpago,
        ];

        $this->assertTrue(SaleForm::saveDoc($ventaData, $user, $cajaVenta, $agente->codagente));

        /** @var AlbaranCliente $albaranVenta */
        $albaranVenta = SaleForm::getDoc();
        $this->assertInstanceOf(AlbaranCliente::class, $albaranVenta);

        $prepagoVale = new PrePagoCli();
        $this->assertTrue($prepagoVale->loadFromCode('', [
            new DataBaseWhere('modelname', 'AlbaranCliente'),
            new DataBaseWhere('modelid', $albaranVenta->primaryColumnValue()),
            new DataBaseWhere('codpago', self::$formaPagoVales->codpago),
        ]));
        $this->assertEquals(25.0, $prepagoVale->amount);

        $movimiento = new MiliValeMovimiento();
        $this->assertTrue($movimiento->loadFromCode('', [
            new DataBaseWhere('idvale', $vale->id),
        ]));
        $this->assertEquals(-25.0, $movimiento->importe);

        $valeRecargado = new MiliVale();
        $this->assertTrue($valeRecargado->loadFromCode($vale->id));
        $this->assertEquals(25.0, $valeRecargado->saldoactual);
    }

    private function createTerminalAlbaranCliente(string $codcliente): TpvTerminal
    {
        $terminal = $this->createTerminal($codcliente);
        $terminal->doctype = 'AlbaranCliente';
        $terminal->mostrarbeneficio = true;
        $terminal->abrircajonauto = false;
        $this->assertTrue($terminal->save());
        return $terminal;
    }

    private function createCajaForTerminal(TpvTerminal $terminal): \FacturaScripts\Dinamic\Model\TpvCaja
    {
        $caja = $this->createCaja();
        $caja->idtpv = $terminal->idtpv;
        $this->assertTrue($caja->save());
        return $caja;
    }

    private function getValeProduct(): Producto
    {
        $producto = new Producto();
        $this->assertTrue($producto->loadFromCode('', [new DataBaseWhere('referencia', GestionVales::REFERENCIA_VALE)]));
        return $producto;
    }
}
