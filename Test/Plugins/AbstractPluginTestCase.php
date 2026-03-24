<?php

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Model\Agente;
use FacturaScripts\Core\Model\Cliente;
use FacturaScripts\Core\Model\Empresa;
use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Model\FormaPago;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\TpvCaja;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\MililitrosPersonalizacion\Model\DescuentoPorCantidad;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

abstract class AbstractPluginTestCase extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;
    use RandomDataTrait;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::setAdminSession(true);
        self::setDefaultSettings();
        self::installAccountingPlan();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::setAdminSession();
    }

    protected function tearDown(): void
    {
        $this->logErrors();
        parent::tearDown();
    }

    protected static function setAdminSession(bool $isAdmin = false): void
    {
        $user = new User();
        $user->loadFromCode('admin');
        if ($isAdmin) {
            $user->admin = true;
        }

        Session::set('user', $user);
    }

    protected function createCustomer(): Cliente
    {
        $cliente = $this->getRandomCustomer();
        $this->assertTrue($cliente->save());
        return $cliente;
    }

    protected function createAgent(): Agente
    {
        $agente = $this->getRandomAgent();
        $this->assertTrue($agente->save());
        return $agente;
    }

    protected function createCaja(): TpvCaja
    {
        $caja = new TpvCaja();
        $this->assertTrue($caja->save());
        return $caja;
    }

    protected function createCompany(): Empresa
    {
        $empresa = new Empresa();
        $empresa->nombre = 'Empresa ' . Tools::randomString();
        $empresa->nombrecorto = 'E' . mt_rand(1000, 9999);
        $empresa->cifnif = (string) mt_rand(10000000, 99999999) . 'Z';
        $this->assertTrue($empresa->save());
        return $empresa;
    }

    protected function createPaymentMethod(string $codpago, ?int $idempresa = null): FormaPago
    {
        $formaPago = new FormaPago();
        $formaPago->descripcion = 'Pago ' . Tools::randomString();
        $formaPago->codpago = $codpago;
        if ($idempresa !== null) {
            $formaPago->idempresa = $idempresa;
        }

        $this->assertTrue($formaPago->save());
        return $formaPago;
    }

    protected function createDiscount(?string $codfamilia, float $cantidadMinima, float $cantidadMaxima, float $valor): DescuentoPorCantidad
    {
        $discount = new DescuentoPorCantidad();
        $discount->name = Tools::randomString();
        $discount->activo = true;
        $discount->cantidadminima = $cantidadMinima;
        $discount->cantidadmaxima = $cantidadMaxima;
        $discount->codfamilia = $codfamilia;
        $discount->tipoaplicacion = DescuentoPorCantidad::PRECIO_FIJO;
        $discount->valor = $valor;
        $this->assertTrue($discount->save());

        return $discount;
    }

    protected function createProduct(float $price, bool $pedirUnidades = false, string $barcode = ''): Producto
    {
        $family = new Familia();
        $family->descripcion = 'Familia ' . Tools::randomString();
        $this->assertTrue($family->save());

        $product = $this->getRandomProduct();
        $product->precio = $price;
        $product->codfamilia = $family->codfamilia;
        $product->pedirunidades = $pedirUnidades;
        $this->assertTrue($product->save());

        if ($barcode !== '') {
            $variant = $product->getVariants()[0];
            $variant->codbarras = $barcode;
            $this->assertTrue($variant->save());
        }

        return $product;
    }

    protected function createTerminal(string $codcliente, bool $addDiscount = false, bool $groupLines = false, bool $changePrice = false): TpvTerminal
    {
        $terminal = new TpvTerminal();
        $terminal->name = static::class . '-' . Tools::randomString();
        $terminal->codcliente = $codcliente;
        $terminal->adddiscount = $addDiscount;
        $terminal->grouplines = $groupLines;
        $terminal->changeprice = $changePrice;
        $this->assertTrue($terminal->save());

        return $terminal;
    }
}
