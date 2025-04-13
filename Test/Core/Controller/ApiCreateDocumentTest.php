<?php declare(strict_types=1);

namespace FacturaScripts\Test\Core\Controller;

use FacturaScripts\Core\Controller\ApiCreateDocument;
use FacturaScripts\Core\Model\AlbaranCliente;
use FacturaScripts\Core\Model\AlbaranProveedor;
use FacturaScripts\Core\Model\ApiKey;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\FacturaProveedor;
use FacturaScripts\Core\Model\PedidoCliente;
use FacturaScripts\Core\Model\PedidoProveedor;
use FacturaScripts\Core\Model\PresupuestoCliente;
use FacturaScripts\Core\Model\PresupuestoProveedor;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Core\Tools;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

class ApiCreateDocumentTest extends TestCase
{
    use LogErrorsTrait;
    use RandomDataTrait;
    use DefaultSettingsTrait;

    private ?ApiKey $apiKey = null;

    protected function setup(): void
    {
        new User();

        $this->setDefaultSettings();

        Tools::settingsSet('default', 'enable_api', true);

        $this->apiKey = new ApiKey();
        $this->apiKey->nick = 'admin';
        $this->apiKey->description = 'test';
        $this->apiKey->fullaccess = true;
        $this->assertTrue($this->apiKey->save());
    }

    public function documentosProvider(): array
    {
        return [
            ['AlbaranCliente', 'idalbaran', AlbaranCliente::class, 'cliente'],
            ['PresupuestoCliente', 'idpresupuesto', PresupuestoCliente::class, 'cliente'],
            ['PedidoCliente', 'idpedido', PedidoCliente::class, 'cliente'],
            ['FacturaCliente', 'idfactura', FacturaCliente::class, 'cliente'],
            ['AlbaranProveedor', 'idalbaran', AlbaranProveedor::class, 'proveedor'],
            ['PresupuestoProveedor', 'idpresupuesto', PresupuestoProveedor::class, 'proveedor'],
            ['PedidoProveedor', 'idpedido', PedidoProveedor::class, 'proveedor'],
            ['FacturaProveedor', 'idfactura', FacturaProveedor::class, 'proveedor'],
        ];
    }

    /**
     * @dataProvider documentosProvider
     * @throws ReflectionException
     */
    public function testCreateDocumento($docType, $primaryKey, $classFQN, string $subjectType): void
    {
        $_POST = [];
        $_SERVER = [];

        $subject = $subjectType === 'cliente'
            ? $this->getRandomCustomer()
            : $this->getRandomSupplier();

        $this->assertTrue($subject->save());

        $_SERVER['Token'] = $this->apiKey->apikey;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST[$subject::primaryColumn()] = $subject->primaryColumnValue();
        $_POST['lineas'] = json_encode([
            [
                'descripcion' => 'Mano de obra',
                'cantidad' => 1,
                'pvpunitario' => 30,
            ],
        ]);

        $apiCreateDocumentoCliente = new ApiCreateDocument('ApiCreateDocumentoCliente', '/api/3/crear' . $docType);

        $reflectionClass = new ReflectionClass(ApiCreateDocument::class);
        $metodo = $reflectionClass->getMethod('runResource');
        $metodo->setAccessible(true);

        $metodo->invoke($apiCreateDocumentoCliente);

        $propiedad = $reflectionClass->getProperty('response');
        $propiedad->setAccessible(true);

        $response = json_decode($propiedad->getValue($apiCreateDocumentoCliente)->getContent(), true);

        // comprobamos response
        $this->assertEquals(strtolower($docType) . '/' . $response['doc'][$primaryKey], $response['url']);
        $this->assertEquals($docType, $response['doc-type']);
        $this->assertEquals(30, $response['doc']['neto']);
        $this->assertEquals(30, $response['lines'][0]['pvpunitario']);

        // comprobamos que se ha guardado en BBDD
        $doc = new $classFQN();
        $doc->loadFromCode($response['doc'][$primaryKey]);
        $this->assertEquals(30, $doc->neto);
        $this->assertEquals(30, $doc->getLines()[0]->pvpunitario);

        // eliminamos
        $this->assertTrue($doc->delete());
        $this->assertTrue($subject->getDefaultAddress()->delete());
        $this->assertTrue($subject->delete());
    }

    protected function tearDown(): void
    {
        $this->assertTrue($this->apiKey->delete());

        $this->logErrors();
    }
}
