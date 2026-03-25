<?php

namespace FacturaScripts\Test\Plugins;

require_once __DIR__ . '/AbstractPluginTestCase.php';

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\Calculator;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\FormaPago;
use FacturaScripts\Core\Session;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\MiliValeMovimiento;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Plugins\TPVneo\Model\TpvPago;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Lib\GestionVales;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Lib\TPVneo\SaleForm;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Model\MiliVale;

class GestionValesTest extends AbstractPluginTestCase
{
    private static FormaPago $formaPagoVales;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        new TpvTerminal();

        self::$formaPagoVales = new FormaPago();
        self::$formaPagoVales->descripcion = 'Forma de pago de vales';
        self::$formaPagoVales->codpago = 'VALTPV';
        self::assertTrue(self::$formaPagoVales->save());

        $empresa = new Empresa();
        $empresa->loadFromCode(1);
        $empresa->codpagovales = self::$formaPagoVales->codpago;
        self::assertTrue($empresa->save());

        GestionVales::crearProductoVale();
    }

    public function testConsumoValeTPV(): void
    {
        // compramos un vale
        $facturaCompra = $this->getRandomCustomerInvoice();
        $this->assertTrue($facturaCompra->save());

        $linea = $facturaCompra->getNewLine();
        $linea->descripcion = 'compar-vale-test';
        $linea->cantidad = 1;
        $linea->pvpunitario = 50;
        $linea->save();

        $vale = new MiliVale();
        $vale->iddoc = $facturaCompra->idfactura;
        $vale->modeldoc = $facturaCompra->modelClassName();
        $vale->idlinea = $linea->idlinea;
        $vale->modellinea = $linea->modelClassName();
        $vale->saldoinicial = $linea->pvpunitario;
        $vale->saldoactual = $linea->pvpunitario;
        $vale->codcliente = $facturaCompra->getSubject()->codcliente;
        $vale->nick = Session::user()->nick;
        $vale->idempresa = $facturaCompra->idempresa;
        $this->assertTrue($vale->save());

        // consumimos el vale
        $cliente = $this->createCustomer();

        $user = Session::user();

        $terminal = $this->createTerminal($cliente->codcliente);
        $caja = $this->createCaja();
        $agente = $this->createAgent();
        $codagente = $agente->codagente;

        $formData = [
            'action' => 'save-cart',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'referencia_1' => '',
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => 'test',
            'idlinea_1' => 1,
            'codimpuesto_1' => '',
            'new_precio_1' => '',
            'pvpunitario_1' => 10,
            'formasPagos' => 2,
            self::$formaPagoVales->codpago => 4,
            'PAYPAL' => 6,
            'codigovale' => $vale->codigo,
        ];

        SaleForm::saveDoc($formData, $user, $caja, $codagente);

        /** @var FacturaCliente $factura */
        $factura = SaleForm::getDoc();

        $this->assertEquals(12.1, $factura->total);

        $recibos = $factura->getReceipts();
        $this->assertCount(2, $recibos);
        $this->assertEquals(6, $recibos[0]->importe);
        $this->assertEquals('PAYPAL', $recibos[0]->codpago);
        $this->assertEquals(4, $recibos[1]->importe);
        $this->assertEquals(self::$formaPagoVales->codpago, $recibos[1]->codpago);

        /** @var MiliValeMovimiento $movimiento */
        $movimiento = $recibos[1]->getMovimientoVale();
        $this->assertEquals(-4, $movimiento->importe);
        $this->assertEquals($factura->idfactura, $movimiento->idfactura);
    }

    public function testConsumoValeTPVMarcaPagadoAunqueElPagoDelTerminalNoLoEste(): void
    {
        $facturaCompra = $this->getRandomCustomerInvoice();
        $this->assertTrue($facturaCompra->save());

        $linea = $facturaCompra->getNewLine();
        $linea->descripcion = 'compar-vale-test-unpaid';
        $linea->cantidad = 1;
        $linea->pvpunitario = 50;
        $linea->save();

        $vale = new MiliVale();
        $vale->iddoc = $facturaCompra->idfactura;
        $vale->modeldoc = $facturaCompra->modelClassName();
        $vale->idlinea = $linea->idlinea;
        $vale->modellinea = $linea->modelClassName();
        $vale->saldoinicial = $linea->pvpunitario;
        $vale->saldoactual = $linea->pvpunitario;
        $vale->codcliente = $facturaCompra->getSubject()->codcliente;
        $vale->nick = Session::user()->nick;
        $vale->idempresa = $facturaCompra->idempresa;
        $this->assertTrue($vale->save());

        $cliente = $this->createCustomer();
        $user = Session::user();
        $terminal = $this->createTerminal($cliente->codcliente);
        $caja = $this->createCaja();
        $agente = $this->createAgent();

        $tpvPagoVale = new TpvPago();
        $tpvPagoVale->idtpv = $terminal->idtpv;
        $tpvPagoVale->codpago = self::$formaPagoVales->codpago;
        $tpvPagoVale->paid = false;
        $this->assertTrue($tpvPagoVale->save());

        $tpvPagoPaypal = new TpvPago();
        $tpvPagoPaypal->idtpv = $terminal->idtpv;
        $tpvPagoPaypal->codpago = 'PAYPAL';
        $tpvPagoPaypal->paid = true;
        $this->assertTrue($tpvPagoPaypal->save());

        $formData = [
            'action' => 'save-cart',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'referencia_1' => '',
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => 'test',
            'idlinea_1' => 1,
            'codimpuesto_1' => '',
            'new_precio_1' => '',
            'pvpunitario_1' => 10,
            'formasPagos' => 2,
            self::$formaPagoVales->codpago => 4,
            'PAYPAL' => 6,
            'codigovale' => $vale->codigo,
        ];

        $this->assertTrue(SaleForm::saveDoc($formData, $user, $caja, $agente->codagente));

        /** @var FacturaCliente $factura */
        $factura = SaleForm::getDoc();
        $recibos = $factura->getReceipts();
        $this->assertCount(2, $recibos);

        $reciboVale = null;
        foreach ($recibos as $recibo) {
            if ($recibo->codpago === self::$formaPagoVales->codpago) {
                $reciboVale = $recibo;
                break;
            }
        }

        $this->assertNotNull($reciboVale);
        $this->assertTrue((bool) $reciboVale->pagado);
        $this->assertEquals($vale->id, $reciboVale->idmilivale);

        $movimiento = $reciboVale->getMovimientoVale();
        $this->assertEquals(-4.0, $movimiento->importe);

        $valeRecargado = new MiliVale();
        $this->assertTrue($valeRecargado->loadFromCode($vale->id));
        $this->assertEquals(46.0, $valeRecargado->saldoactual);
    }

    public function testNoBorrarProductoVale(): void
    {
        $producto = new Producto();
        $producto->loadFromCode('', [new DataBaseWhere('referencia', GestionVales::REFERENCIA_VALE)]);
        $this->assertFalse($producto->delete());
    }

    public function testProductoValeTieneConfiguracionEsperadaYNoSePuedeModificar(): void
    {
        $producto = $this->getValeProduct();
        $variante = $producto->getVariants()[0];

        $this->assertSame(GestionVales::REFERENCIA_VALE, $producto->referencia);
        $this->assertSame(GestionVales::REFERENCIA_VALE, $variante->codbarras);
        $this->assertFalse((bool)$producto->secompra);
        $this->assertTrue((bool)$producto->sevende);
        $this->assertTrue((bool)$producto->nostock);

        $producto->descripcion = 'Descripcion modificada';
        $this->assertFalse($producto->save());

        $this->assertFalse($variante->delete());
    }

    public function testEmpresaPuedeRelacionarSuPropiaFormaDePagoDeVales(): void
    {
        $empresa = $this->createCompany();
        $formaPagoEmpresa = $this->createPaymentMethod('VAL' . mt_rand(10, 99), $empresa->idempresa);
        $this->assertNotSame(self::$formaPagoVales->codpago, $formaPagoEmpresa->codpago);
        $empresa->codpagovales = $formaPagoEmpresa->codpago;
        $this->assertTrue($empresa->save());
        $this->assertSame($formaPagoEmpresa->codpago, $empresa->codpagovales);
        $this->assertSame($empresa->idempresa, $formaPagoEmpresa->idempresa);
    }

    public function testNoSePuedeComprarUnValeSinPrecio(): void
    {
        $factura = $this->getRandomCustomerInvoice();
        $this->assertTrue($factura->save());

        $linea = $factura->getNewProductLine(GestionVales::REFERENCIA_VALE);
        $linea->cantidad = 1;
        $linea->pvpunitario = 0;

        $this->assertFalse($linea->save());
        $this->assertNull(GestionVales::getValeFromLine($linea));
    }

    public function testNoSePuedeComprarUnValeSinPrecioDesdeSaleForm(): void
    {
        $cliente = $this->createCustomer();
        $user = Session::user();
        $agente = $this->createAgent();
        $this->createTerminal($cliente->codcliente);
        $caja = $this->createCaja();
        $productoVale = $this->getValeProduct();

        $this->assertFalse(SaleForm::saveDoc([
            'action' => 'save-cart',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'formasPagos' => 1,
            'PAYPAL' => 0.0,
            'referencia_1' => GestionVales::REFERENCIA_VALE,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $productoVale->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $productoVale->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => 0.0,
        ], $user, $caja, $agente->codagente));
    }

    public function testCrearValeAlVenderProductoVale(): void
    {
        $factura = $this->getRandomCustomerInvoice();
        $this->assertTrue($factura->save());

        $linea = $factura->getNewProductLine(GestionVales::REFERENCIA_VALE);
        $linea->cantidad = 1;
        $linea->pvpunitario = 50;
        $this->assertTrue($linea->save());

        $vale = GestionVales::getValeFromLine($linea);

        $this->assertNotNull($vale);
        $this->assertEquals($factura->primaryColumnValue(), $vale->iddoc);
        $this->assertSame($factura->modelClassName(), $vale->modeldoc);
        $this->assertEquals($linea->primaryColumnValue(), $vale->idlinea);
        $this->assertSame($linea->modelClassName(), $vale->modellinea);
        $this->assertSame($factura->getSubject()->codcliente, $vale->codcliente);
        $this->assertSame($factura->idempresa, $vale->idempresa);
        $this->assertEquals(50.0, $vale->saldoinicial);
        $this->assertEquals(50.0, $vale->saldoactual);

        $lineaRecargada = $factura->getLines()[count($factura->getLines()) - 1];
        $this->assertStringContainsString($vale->codigo, $lineaRecargada->descripcion);
    }

    public function testCrearValeDesdeSaleForm(): void
    {
        $cliente = $this->createCustomer();

        $user = Session::user();

        $terminal = $this->createTerminal($cliente->codcliente);
        $caja = $this->createCaja();
        $agente = $this->createAgent();

        $productoVale = new Producto();
        $this->assertTrue($productoVale->loadFromCode('', [new DataBaseWhere('referencia', GestionVales::REFERENCIA_VALE)]));

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

        /** @var FacturaCliente $factura */
        $factura = SaleForm::getDoc();
        $this->assertNotNull($factura);

        $lineas = $factura->getLines();
        $this->assertCount(1, $lineas);
        $this->assertSame(GestionVales::REFERENCIA_VALE, $lineas[0]->referencia);

        $vale = GestionVales::getValeFromLine($lineas[0]);

        $this->assertNotNull($vale);
        $valeEnBaseDeDatos = new MiliVale();
        $this->assertTrue($valeEnBaseDeDatos->loadFromCode($vale->id));
        $this->assertEquals($factura->primaryColumnValue(), $valeEnBaseDeDatos->iddoc);
        $this->assertSame($factura->modelClassName(), $valeEnBaseDeDatos->modeldoc);
        $this->assertEquals($lineas[0]->primaryColumnValue(), $valeEnBaseDeDatos->idlinea);
        $this->assertSame($lineas[0]->modelClassName(), $valeEnBaseDeDatos->modellinea);
        $this->assertSame($cliente->codcliente, $valeEnBaseDeDatos->codcliente);
        $this->assertSame($factura->idempresa, $valeEnBaseDeDatos->idempresa);
        $this->assertEquals(50.0, $valeEnBaseDeDatos->saldoinicial);
        $this->assertEquals(50.0, $valeEnBaseDeDatos->saldoactual);
        $this->assertStringContainsString($vale->codigo, $lineas[0]->descripcion);
    }

    public function testDevolverFacturaDeValeAnulaElSaldoMedianteMovimiento(): void
    {
        $cliente = $this->createCustomer();
        $user = Session::user();
        $agente = $this->createAgent();
        $terminal = $this->createTerminal($cliente->codcliente);
        $caja = $this->createCaja();

        $productoVale = new Producto();
        $this->assertTrue($productoVale->loadFromCode('', [new DataBaseWhere('referencia', GestionVales::REFERENCIA_VALE)]));

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

        /** @var FacturaCliente $factura */
        $factura = SaleForm::getDoc();
        $lineaOriginal = $factura->getLines()[0];
        $vale = GestionVales::getValeFromLine($lineaOriginal);

        $this->assertNotNull($vale);
        $this->assertEquals(50.0, $vale->saldoactual);

        $facturaRectificativa = new FacturaCliente();
        $facturaRectificativa->setSubject($cliente);
        $facturaRectificativa->setAuthor($user);
        $facturaRectificativa->codejercicio = $factura->codejercicio;
        $facturaRectificativa->codalmacen = $factura->codalmacen;
        $facturaRectificativa->coddivisa = $factura->coddivisa;
        $facturaRectificativa->codpago = $factura->codpago;
        $facturaRectificativa->codserie = $factura->codserie;
        $facturaRectificativa->codigorect = $factura->codigo;
        $facturaRectificativa->idcaja = $factura->idcaja;
        $facturaRectificativa->idempresa = $factura->idempresa;
        $facturaRectificativa->idfacturarect = $factura->idfactura;
        $facturaRectificativa->idtpv = $factura->idtpv;
        $this->assertTrue($facturaRectificativa->save());

        $lineaRectificativa = $facturaRectificativa->getNewLine($lineaOriginal->toArray());
        $lineaRectificativa->cantidad = -1;
        $lineaRectificativa->idlinearect = $lineaOriginal->idlinea;
        $this->assertTrue($lineaRectificativa->save());

        $lineasRectificativa = $facturaRectificativa->getLines();
        $this->assertTrue(Calculator::calculate($facturaRectificativa, $lineasRectificativa, true));
        $facturaRectificativa->loadFromCode($facturaRectificativa->idfactura);

        $valeRecargado = new MiliVale();
        $this->assertTrue($valeRecargado->loadFromCode($vale->id));
        $this->assertEquals(0.0, $valeRecargado->saldoactual);

        $movimiento = new MiliValeMovimiento();
        $this->assertTrue($movimiento->loadFromCode('', [
            new DataBaseWhere('idvale', $vale->id),
            new DataBaseWhere('idfactura', $facturaRectificativa->idfactura),
            new DataBaseWhere('idrecibo', 0),
        ]));
        $this->assertEquals(-50.0, $movimiento->importe);
        $this->assertEquals($cliente->codcliente, $movimiento->codcliente);
        $this->assertEquals($facturaRectificativa->idempresa, $movimiento->idempresa);

        $lineaRectificativa = $facturaRectificativa->getLines()[0];
        $this->assertNull(GestionVales::getValeFromLine($lineaRectificativa));
        $this->assertStringContainsString($vale->codigo, $lineaRectificativa->descripcion);
    }

    public function testDevolucionDeFacturaPagadaConValeRegistraMovimientoEnRecibo(): void
    {
        $cliente = $this->createCustomer();
        $user = Session::user();
        $agente = $this->createAgent();

        $terminalCompraVale = $this->createTerminal($cliente->codcliente);
        $cajaCompraVale = $this->createCaja();
        $productoVale = new Producto();
        $this->assertTrue($productoVale->loadFromCode('', [new DataBaseWhere('referencia', GestionVales::REFERENCIA_VALE)]));

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

        $this->assertTrue(SaleForm::saveDoc($compraValeData, $user, $cajaCompraVale, $agente->codagente));
        $facturaVale = SaleForm::getDoc();
        $vale = GestionVales::getValeFromLine($facturaVale->getLines()[0]);
        $this->assertNotNull($vale);
        $this->assertEquals(50.0, $vale->saldoactual);

        $terminalVenta = $this->createTerminal($cliente->codcliente);
        $cajaVenta = $this->createCaja();
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

        /** @var FacturaCliente $facturaVenta */
        $facturaVenta = SaleForm::getDoc();
        $recibosVenta = $facturaVenta->getReceipts();
        $this->assertCount(2, $recibosVenta);

        $reciboValeVenta = null;
        foreach ($recibosVenta as $recibo) {
            if ($recibo->codpago === self::$formaPagoVales->codpago) {
                $reciboValeVenta = $recibo;
                break;
            }
        }

        $this->assertNotNull($reciboValeVenta);
        $this->assertEquals($vale->id, $reciboValeVenta->idmilivale);

        $movimientoConsumo = $reciboValeVenta->getMovimientoVale();
        $this->assertEquals(-25.0, $movimientoConsumo->importe);
        $movimientoConsumoEnBaseDeDatos = new MiliValeMovimiento();
        $this->assertTrue($movimientoConsumoEnBaseDeDatos->loadFromCode('', [
            new DataBaseWhere('idvale', $vale->id),
            new DataBaseWhere('idrecibo', $reciboValeVenta->idrecibo),
            new DataBaseWhere('idfactura', $facturaVenta->idfactura),
        ]));
        $this->assertEquals(-25.0, $movimientoConsumoEnBaseDeDatos->importe);
        $this->assertEquals($reciboValeVenta->idrecibo, $movimientoConsumoEnBaseDeDatos->idrecibo);
        $this->assertEquals($facturaVenta->idfactura, $movimientoConsumoEnBaseDeDatos->idfactura);

        $valeTrasConsumo = new MiliVale();
        $this->assertTrue($valeTrasConsumo->loadFromCode($vale->id));
        $this->assertEquals(25.0, $valeTrasConsumo->saldoactual);

        $facturaRectificativa = new FacturaCliente();
        $facturaRectificativa->setSubject($cliente);
        $facturaRectificativa->setAuthor($user);
        $facturaRectificativa->codejercicio = $facturaVenta->codejercicio;
        $facturaRectificativa->codalmacen = $facturaVenta->codalmacen;
        $facturaRectificativa->coddivisa = $facturaVenta->coddivisa;
        $facturaRectificativa->codpago = self::$formaPagoVales->codpago;
        $facturaRectificativa->codserie = $facturaVenta->codserie;
        $facturaRectificativa->codigorect = $facturaVenta->codigo;
        $facturaRectificativa->idcaja = $facturaVenta->idcaja;
        $facturaRectificativa->idempresa = $facturaVenta->idempresa;
        $facturaRectificativa->idfacturarect = $facturaVenta->idfactura;
        $facturaRectificativa->idtpv = $facturaVenta->idtpv;
        $this->assertTrue($facturaRectificativa->save());

        $lineaVenta = $facturaVenta->getLines()[0];
        $lineaRectificativa = $facturaRectificativa->getNewLine($lineaVenta->toArray());
        $lineaRectificativa->cantidad = -1;
        $lineaRectificativa->idlinearect = $lineaVenta->idlinea;
        $this->assertTrue($lineaRectificativa->save());

        $lineasRectificativa = $facturaRectificativa->getLines();
        $this->assertTrue(Calculator::calculate($facturaRectificativa, $lineasRectificativa, true));

        $recibosRectificativa = $facturaRectificativa->getReceipts();
        $this->assertCount(1, $recibosRectificativa);
        $reciboDevolucion = $recibosRectificativa[0];
        $reciboDevolucion->codpago = self::$formaPagoVales->codpago;
        $reciboDevolucion->importe = -25.0;
        $reciboDevolucion->pagado = true;
        $this->assertTrue($reciboDevolucion->save());

        $reciboDevolucionRecargado = $facturaRectificativa->getReceipts()[0];
        $this->assertEquals($vale->id, $reciboDevolucionRecargado->idmilivale);

        $movimientoDevolucion = $reciboDevolucionRecargado->getMovimientoVale();
        $this->assertEquals(25.0, $movimientoDevolucion->importe);
        $this->assertEquals('Devolución', $movimientoDevolucion->observaciones);
        $this->assertEquals($facturaRectificativa->idfactura, $movimientoDevolucion->idfactura);
        $this->assertEquals($reciboDevolucionRecargado->idrecibo, $movimientoDevolucion->idrecibo);

        $valeFinal = new MiliVale();
        $this->assertTrue($valeFinal->loadFromCode($vale->id));
        $this->assertEquals(50.0, $valeFinal->saldoactual);
    }

    public function testNoSePuedeDevolverUnValeSiYaTieneMovimientosAsociados(): void
    {
        $factura = $this->createValePurchaseInvoice(40.0);
        $lineaOriginal = $factura->getLines()[0];
        $vale = GestionVales::getValeFromLine($lineaOriginal);

        $movimiento = new MiliValeMovimiento();
        $movimiento->idvale = $vale->id;
        $movimiento->idrecibo = 0;
        $movimiento->idfactura = $factura->idfactura;
        $movimiento->importe = -10.0;
        $movimiento->nick = Session::user()->nick;
        $movimiento->idempresa = $factura->idempresa;
        $movimiento->codcliente = $factura->codcliente;
        $movimiento->observaciones = 'Consumo previo';
        $this->assertTrue($movimiento->save());

        $vale->save();
        $valeRecargado = new MiliVale();
        $this->assertTrue($valeRecargado->loadFromCode($vale->id));
        $this->assertEquals(30.0, $valeRecargado->saldoactual);
        $this->assertTrue((bool)$valeRecargado->activo);

        $facturaRectificativa = $this->createRectificativeInvoice($factura);
        $lineaRectificativa = $facturaRectificativa->getNewLine($lineaOriginal->toArray());
        $lineaRectificativa->cantidad = -1;
        $lineaRectificativa->idlinearect = $lineaOriginal->idlinea;

        $this->assertFalse($lineaRectificativa->save());
    }

    public function testLosMovimientosDeValeNoSePuedenModificarNiBorrar(): void
    {
        $factura = $this->createValePurchaseInvoice(35.0);
        $vale = GestionVales::getValeFromLine($factura->getLines()[0]);

        $movimiento = new MiliValeMovimiento();
        $movimiento->idvale = $vale->id;
        $movimiento->idrecibo = 0;
        $movimiento->idfactura = $factura->idfactura;
        $movimiento->importe = -5.0;
        $movimiento->nick = Session::user()->nick;
        $movimiento->idempresa = $factura->idempresa;
        $movimiento->codcliente = $factura->codcliente;
        $movimiento->observaciones = 'Consumo manual';
        $this->assertTrue($movimiento->save());

        $movimiento->importe = -4.0;
        $this->assertFalse($movimiento->save());
        $this->assertFalse($movimiento->delete());
    }

    public function testSaldoCeroDesactivaElVale(): void
    {
        $factura = $this->createValePurchaseInvoice(25.0);
        $vale = GestionVales::getValeFromLine($factura->getLines()[0]);

        $movimiento = new MiliValeMovimiento();
        $movimiento->idvale = $vale->id;
        $movimiento->idrecibo = 0;
        $movimiento->idfactura = $factura->idfactura;
        $movimiento->importe = -25.0;
        $movimiento->nick = Session::user()->nick;
        $movimiento->idempresa = $factura->idempresa;
        $movimiento->codcliente = $factura->codcliente;
        $movimiento->observaciones = 'Consumo total';
        $this->assertTrue($movimiento->save());

        $this->assertTrue($vale->save());

        $valeRecargado = new MiliVale();
        $this->assertTrue($valeRecargado->loadFromCode($vale->id));
        $this->assertEquals(0.0, $valeRecargado->saldoactual);
    }

    private function createValePurchaseInvoice(float $importe): FacturaCliente
    {
        $cliente = $this->createCustomer();
        $user = Session::user();
        $agente = $this->createAgent();
        $this->createTerminal($cliente->codcliente);
        $caja = $this->createCaja();
        $productoVale = $this->getValeProduct();

        $this->assertTrue(SaleForm::saveDoc([
            'action' => 'save-cart',
            'codcliente' => $cliente->codcliente,
            'dtopor_global' => 0.0,
            'formasPagos' => 1,
            'PAYPAL' => $importe,
            'referencia_1' => GestionVales::REFERENCIA_VALE,
            'orden_1' => 1,
            'cantidad_1' => 1,
            'descripcion_1' => $productoVale->descripcion,
            'idlinea_1' => 1,
            'codimpuesto_1' => $productoVale->codimpuesto,
            'new_precio_1' => '',
            'pvpunitario_1' => $importe,
        ], $user, $caja, $agente->codagente));

        return SaleForm::getDoc();
    }

    private function createRectificativeInvoice(FacturaCliente $factura): FacturaCliente
    {
        $facturaRectificativa = new FacturaCliente();
        $facturaRectificativa->setSubject($factura->getSubject());
        $facturaRectificativa->setAuthor(Session::user());
        $facturaRectificativa->codejercicio = $factura->codejercicio;
        $facturaRectificativa->codalmacen = $factura->codalmacen;
        $facturaRectificativa->coddivisa = $factura->coddivisa;
        $facturaRectificativa->codpago = $factura->codpago;
        $facturaRectificativa->codserie = $factura->codserie;
        $facturaRectificativa->codigorect = $factura->codigo;
        $facturaRectificativa->idcaja = $factura->idcaja;
        $facturaRectificativa->idempresa = $factura->idempresa;
        $facturaRectificativa->idfacturarect = $factura->idfactura;
        $facturaRectificativa->idtpv = $factura->idtpv;
        $this->assertTrue($facturaRectificativa->save());

        return $facturaRectificativa;
    }

    private function getValeProduct(): Producto
    {
        $producto = new Producto();
        $this->assertTrue($producto->loadFromCode('', [new DataBaseWhere('referencia', GestionVales::REFERENCIA_VALE)]));
        return $producto;
    }

}
