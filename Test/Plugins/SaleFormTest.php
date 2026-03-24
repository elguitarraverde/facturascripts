<?php

namespace FacturaScripts\Test\Plugins;

require_once __DIR__ . '/AbstractPluginTestCase.php';

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Lib\TPVneo\SaleForm;

class SaleFormTest extends AbstractPluginTestCase
{
    public function testApplyCalculaDescuentoPorCantidadEnLineaExistente(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, true, false, false);
        $producto = $this->createProduct(20.0);
        $this->createDiscount($producto->codfamilia, 2, 10, 10.0);

        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'descuentobloqueado_1' => false,
            'dtopor_1' => 0.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertSame($cliente->codcliente, SaleForm::getCliente()->codcliente);
        $this->assertFalse((bool)$lines[0]->descuentobloqueado);
        $this->assertEquals(50.0, $lines[0]->dtopor);
        $this->assertEquals(20.0, $lines[0]->pvpunitario);
    }

    public function testApplyMantieneDescuentoManualBloqueado(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, true, false, false);
        $producto = $this->createProduct(20.0);
        $this->createDiscount($producto->codfamilia, 2, 10, 10.0);

        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'descuentobloqueado_1' => false,
            'dtopor_1' => 7.5,
            'actualizar-descuento-manualmente' => 1,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertTrue((bool)$lines[0]->descuentobloqueado);
        $this->assertEquals(7.5, $lines[0]->dtopor);
    }

    public function testApplyDesbloqueaDescuentoManualYCorrigeConDescuentoPorCantidad(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, true, false, false);
        $producto = $this->createProduct(20.0);
        $this->createDiscount($producto->codfamilia, 2, 10, 10.0);

        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'descuentobloqueado_1' => true,
            'dtopor_1' => 0.0,
            'actualizar-descuento-manualmente' => 1,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertFalse((bool)$lines[0]->descuentobloqueado);
        $this->assertEquals(50.0, $lines[0]->dtopor);
    }

    public function testApplyRmLineOmiteLaLineaIndicada(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, true, false, false);
        $productoA = $this->createProduct(20.0);
        $productoB = $this->createProduct(15.0);

        SaleForm::apply([
            'action' => 'rm-line',
            'action-code' => 1,
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 2,
            'referencia_1' => $productoA->referencia,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $productoA->descripcion,
            'idlinea_1' => 11,
            'codimpuesto_1' => $productoA->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'descuentobloqueado_1' => false,
            'dtopor_1' => 0.0,
            'referencia_2' => $productoB->referencia,
            'orden_2' => 2,
            'cantidad_2' => 3,
            'descripcion_2' => $productoB->descripcion,
            'idlinea_2' => 22,
            'codimpuesto_2' => $productoB->codimpuesto,
            'new_precio_2' => '',
            'pvpunitario_2' => 15.0,
            'descuentobloqueado_2' => false,
            'dtopor_2' => 0.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertSame($productoB->referencia, $lines[0]->referencia);
        $this->assertEquals(3.0, $lines[0]->cantidad);
    }

    public function testApplyIgnoraPosicionesSinDescripcion(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, false, false, false);
        $producto = $this->createProduct(20.0);

        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 2,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'referencia_2' => $producto->referencia,
            'orden_2' => 2,
            'cantidad_2' => 3,
            'idlinea_2' => 2,
            'codimpuesto_2' => $producto->codimpuesto,
            'new_precio_2' => '',
            'pvpunitario_2' => 20.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertEquals(1.0, $lines[0]->cantidad);
    }

    public function testApplyAgrupaLineaExistenteSiNoPideUnidades(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, false, true, false);
        $producto = $this->createProduct(20.0, false);

        SaleForm::apply([
            'action' => 'add-product',
            'action-code' => $producto->referencia,
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertEquals(3.0, $lines[0]->cantidad);
    }

    public function testApplyNoAgrupaSiElProductoPideUnidades(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, false, true, false);
        $producto = $this->createProduct(20.0, true);

        SaleForm::apply([
            'action' => 'add-product',
            'action-code' => $producto->referencia,
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(2, $lines);
        $this->assertEquals(2.0, $lines[0]->cantidad);
        $this->assertEquals(1.0, $lines[1]->cantidad);
    }

    public function testApplyAddBarcodeAgrupaOCreaLineaNueva(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, true, true, false);
        $productoAgrupable = $this->createProduct(20.0, false, 'BARCODE-GROUP-' . mt_rand(1000, 9999));
        $productoNuevo = $this->createProduct(18.0, false, 'BARCODE-NEW-' . mt_rand(1000, 9999));
        $this->createDiscount($productoNuevo->codfamilia, 1, 10, 9.0);

        SaleForm::apply([
            'action' => 'add-barcode',
            'action-code' => $productoAgrupable->getVariants()[0]->codbarras,
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $productoAgrupable->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => $productoAgrupable->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $productoAgrupable->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'descuentobloqueado_1' => false,
            'dtopor_1' => 0.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertEquals(3.0, $lines[0]->cantidad);

        SaleForm::apply([
            'action' => 'add-barcode',
            'action-code' => $productoNuevo->getVariants()[0]->codbarras,
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 0,
            'quantity' => 2,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertSame($productoNuevo->referencia, $lines[0]->referencia);
        $this->assertEquals(2.0, $lines[0]->cantidad);
        $this->assertFalse((bool)$lines[0]->descuentobloqueado);
        $this->assertEquals(50.0, $lines[0]->dtopor);
    }

    public function testApplyAddBarcodeNoHaceNadaSiNoExisteLaVariante(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, false, true, false);

        SaleForm::apply([
            'action' => 'add-barcode',
            'action-code' => 'NO-EXISTE-' . mt_rand(1000, 9999),
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 0,
        ], Session::user(), $terminal, null);

        $this->assertCount(0, SaleForm::getLines());
    }

    public function testApplyAddBarcodeNoAgrupaSiPideUnidades(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, true, true, false);
        $producto = $this->createProduct(20.0, true, 'BARCODE-UNITS-' . mt_rand(1000, 9999));

        SaleForm::apply([
            'action' => 'add-barcode',
            'action-code' => $producto->getVariants()[0]->codbarras,
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'descuentobloqueado_1' => false,
            'dtopor_1' => 0.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(2, $lines);
        $this->assertEquals(2.0, $lines[0]->cantidad);
        $this->assertEquals(1.0, $lines[1]->cantidad);
    }

    public function testApplyNewLineRespetaCantidadIndicadaYPrecioNuevo(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, false, false, true);

        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => '',
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => 'Linea manual',
            'idlinea_1' => 1,
            'codimpuesto_1' => Tools::settings('default', 'codimpuesto'),
            'new_precio_1' => '12.10',
            'pvpunitario_1' => 99.0,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertSame('Linea manual', $lines[0]->descripcion);
        $this->assertGreaterThan(0.0, $lines[0]->pvpunitario);

        SaleForm::apply([
            'action' => 'new-line',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 0,
            'new-desc' => 'Segunda linea manual',
            'new-tax' => Tools::settings('default', 'codimpuesto'),
            'quantity' => 4,
        ], Session::user(), $terminal, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertSame('Segunda linea manual', $lines[0]->descripcion);
        $this->assertEquals(4.0, $lines[0]->cantidad);
    }

    public function testApplySoloCambiaPrecioSiLaCondicionCompuestaSeCumple(): void
    {
        $cliente = $this->createCustomer();
        $producto = $this->createProduct(20.0);

        $terminalSinCambio = $this->createTerminal($cliente->codcliente, false, false, false);
        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '12.10',
            'pvpunitario_1' => 20.0,
        ], Session::user(), $terminalSinCambio, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertEquals(20.0, $lines[0]->pvpunitario);

        $terminalConCambio = $this->createTerminal($cliente->codcliente, false, false, true);
        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '12.10',
            'pvpunitario_1' => 20.0,
        ], Session::user(), $terminalConCambio, null);

        $lines = SaleForm::getLines();
        $this->assertCount(1, $lines);
        $this->assertNotEquals(20.0, $lines[0]->pvpunitario);
        $this->assertEqualsWithDelta(10.0, $lines[0]->pvpunitario, 0.0001);
    }

    public function testApplyConvierteDescuentoEnEurosADescuentoGlobal(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, false, false, false);
        $producto = $this->createProduct(20.0);

        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'descuentoeneuros' => 5.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $producto->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
        ], Session::user(), $terminal, null);

        $doc = SaleForm::getDoc();
        $totalInicial = round(20.0 * 1.21, 2);
        $expectedDto = 100 - ((5.0 / $totalInicial) * 100);

        $this->assertEqualsWithDelta($expectedDto, SaleForm::dtopor1(), 0.0001);
        $this->assertEqualsWithDelta(5.0, $doc->total, 0.0001);
    }

    public function testRenderExponeDatosDelCarritoYGetters(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, true, false, false);
        $producto = $this->createProduct(20.0, false, 'BARCODE-RENDER-' . mt_rand(1000, 9999));
        $this->createDiscount($producto->codfamilia, 1, 10, 10.0);

        SaleForm::apply([
            'action' => '',
            'codcliente' => '',
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => $producto->referencia,
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => str_repeat('L', 130),
            'idlinea_1' => 1,
            'codimpuesto_1' => $producto->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 20.0,
            'descuentobloqueado_1' => false,
            'dtopor_1' => 0.0,
        ], Session::user(), $terminal, 'AGT1');

        $html = SaleForm::render($terminal);
        $doc = SaleForm::getDoc();

        $this->assertSame($cliente->codcliente, SaleForm::getCliente()->codcliente);
        $this->assertSame('AGT1', $doc->codagente);
        $this->assertEquals($doc->dtopor1, SaleForm::dtopor1());
        $this->assertEquals($doc->totalbeneficio, SaleForm::totalbeneficio());
        $this->assertStringContainsString('name="saleForm"', $html);
        $this->assertStringContainsString('id="inputTotalBeneficio"', $html);
        $this->assertStringContainsString($producto->referencia, $html);
        $this->assertStringContainsString('name="productocodfamilia_1"', $html);
        $this->assertStringContainsString('name="descuentobloqueado_1"', $html);
        $this->assertStringContainsString('...', $html);
        $this->assertStringContainsString('name="dtopor_1"', $html);
    }

    public function testRenderLineaManualSinReferenciaMuestraPrecioEditableSinDescuento(): void
    {
        $cliente = $this->createCustomer();
        $terminal = $this->createTerminal($cliente->codcliente, false, false, false);

        SaleForm::apply([
            'action' => '',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'linesCart' => 1,
            'referencia_1' => '',
            'orden_1' => 1,
            'cantidad_1' => 2,
            'descripcion_1' => 'Manual',
            'idlinea_1' => 1,
            'codimpuesto_1' => Tools::settings('default', 'codimpuesto'),
            'new_precio_1' => '',
            'pvpunitario_1' => 12.0,
        ], Session::user(), $terminal, null);

        $html = SaleForm::render($terminal);

        $this->assertStringContainsString('name="referencia_1" value=""', $html);
        $this->assertStringContainsString('class="btn btn-link price"', $html);
        $this->assertStringContainsString('name="new_precio_1" value=""', $html);
        $this->assertStringNotContainsString('name="dtopor_1"', $html);
    }
}
