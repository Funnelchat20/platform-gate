<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Usage\CreatedResource;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que creó la llamada (0.8, contrato 1019-registro-llamadas, L1): lo que el controlador
 * declara queda en la fila; sin declaración, la fila sale como en 0.7; y una declaración que
 * no es un id interno no se guarda ni rompe la llamada.
 */
final class CreatedResourceTest extends GateTestCase
{
    use UsageTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateUsage();
    }

    private function key(): object
    {
        return $this->token(['kind' => 'api_key', 'abilities' => ['communities:write']]);
    }

    /** @return callable(Request): Response */
    private function controllerDeclaring(string $type, int|string $id, int $status = 201): callable
    {
        return static function (Request $request) use ($type, $id, $status): Response {
            CreatedResource::declare($type, $id);

            return new Response('creado', $status);
        };
    }

    /** @return iterable<string, array{string, int|string, string}> */
    public static function validDeclarations(): iterable
    {
        yield 'entero' => ['message', 981, '981'];
        yield 'entero como texto' => ['contact', '42', '42'];
        yield 'el BIGINT UNSIGNED más grande' => ['message', '18446744073709551615', '18446744073709551615'];
        yield '20 dígitos por debajo del tope' => ['message', '10000000000000000000', '10000000000000000000'];
        yield 'el entero más grande de PHP' => ['message', PHP_INT_MAX, (string) PHP_INT_MAX];
        yield 'uuid' => ['scheduled_message', '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60', '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60'];
        yield 'uuid en mayúsculas' => ['webhook_subscription', '0B8F5D2E-6C41-4A7E-9F3D-2A1B7C9E4D60', '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60'];
        yield 'ulid' => ['tag', '01JA2B3C4D5E6F7G8H9J0K1M2N', '01JA2B3C4D5E6F7G8H9J0K1M2N'];
        yield 'ulid en minúsculas' => ['note', '01ja2b3c4d5e6f7g8h9j0k1m2n', '01JA2B3C4D5E6F7G8H9J0K1M2N'];
    }

    #[DataProvider('validDeclarations')]
    public function test_un_controlador_que_declara_deja_tipo_e_id_en_la_fila(string $type, int|string $id, string $stored): void
    {
        $response = $this->gateCall('POST', 'api/v1/messages', $this->key(), [], $this->controllerDeclaring($type, $id));

        $this->assertSame(201, $response->getStatusCode());

        $row = $this->onlyRow();
        $this->assertSame($type, $row['resource_type']);
        $this->assertSame($stored, $row['resource_id']);
    }

    public function test_sin_declaracion_la_fila_sale_como_en_0_7(): void
    {
        $this->gateCall('POST', 'api/v1/messages', $this->key());

        $row = $this->onlyRow();

        $this->assertNull($row['resource_type']);
        $this->assertNull($row['resource_id']);

        // Lo de siempre sigue igual.
        $this->assertStringStartsWith('key_', $row['key_id']);
        $this->assertSame('api/v1/messages', $row['route']);
        $this->assertSame('POST', $row['method']);
        $this->assertSame(200, (int) $row['status_code']);
        $this->assertNotNull($row['request_id']);
    }

    public function test_si_se_declara_dos_veces_queda_la_ultima(): void
    {
        $this->gateCall('POST', 'api/v1/messages', $this->key(), [], static function (Request $request): Response {
            CreatedResource::declare('contact', 5);
            CreatedResource::declare('message', 981);

            return new Response('creado', 201);
        });

        $row = $this->onlyRow();
        $this->assertSame('message', $row['resource_type']);
        $this->assertSame('981', $row['resource_id']);
    }

    /** @return iterable<string, array{string, int|string, string, ?string}> */
    public static function invalidDeclarations(): iterable
    {
        // tipo, id, qué es inválido, el valor que no tiene que ir al log
        yield 'tipo con mayúsculas' => ['Message', 981, 'type', null];
        yield 'tipo vacío' => ['', 981, 'type', null];
        yield 'tipo con espacio' => ['mensaje programado', 981, 'type', null];
        yield 'tipo de 33' => [str_repeat('a', 33), 981, 'type', null];
        yield 'un teléfono' => ['contact', '+5491122334455', 'id', '+5491122334455'];
        yield 'un email' => ['contact', 'ana@example.com', 'id', 'ana@example.com'];
        yield 'un texto' => ['note', 'llamar el lunes', 'id', 'llamar el lunes'];
        yield 'una palabra' => ['note', 'urgente', 'id', 'urgente'];
        yield 'con prefijo' => ['webhook_subscription', 'whs_9f2c4e1a', 'id', 'whs_9f2c4e1a'];
        yield 'vacío' => ['message', '', 'id', null];
        yield 'cero' => ['message', 0, 'id', null];
        yield 'negativo' => ['message', -5, 'id', null];
        yield 'cero como texto' => ['message', '0', 'id', null];
        yield 'negativo como texto' => ['message', '-5', 'id', null];
        yield 'con ceros adelante' => ['message', '007', 'id', null];
        yield 'entero de 21 dígitos' => ['message', '123456789012345678901', 'id', '123456789012345678901'];
        yield 'uno más que el BIGINT UNSIGNED más grande' => ['message', '18446744073709551616', 'id', '18446744073709551616'];
        yield '20 dígitos por encima del tope' => ['message', '99999999999999999999', 'id', '99999999999999999999'];
        yield 'decimal' => ['message', '9.5', 'id', null];
        yield 'uuid sin guiones' => ['message', '0b8f5d2e6c414a7e9f3d2a1b7c9e4d60', 'id', '0b8f5d2e6c414a7e9f3d2a1b7c9e4d60'];
        yield 'ulid con una U' => ['message', '01JA2B3C4D5E6F7G8H9J0K1M2U', 'id', '01JA2B3C4D5E6F7G8H9J0K1M2U'];
        yield 'ulid de más de 128 bits' => ['message', '81JA2B3C4D5E6F7G8H9J0K1M2N', 'id', '81JA2B3C4D5E6F7G8H9J0K1M2N'];
        yield 'id con salto de línea' => ['message', "981\n", 'id', null];
    }

    #[DataProvider('invalidDeclarations')]
    public function test_una_declaracion_invalida_no_se_guarda_ni_rompe_la_llamada(string $type, int|string $id, string $invalid, ?string $secret): void
    {
        $response = $this->gateCall('POST', 'api/v1/messages', $this->key(), [], $this->controllerDeclaring($type, $id));

        // El recurso ya se creó: la llamada contesta lo que contestaba.
        $this->assertSame(201, $response->getStatusCode());

        $row = $this->onlyRow();
        $this->assertNull($row['resource_type']);
        $this->assertNull($row['resource_id']);

        $this->assertLogged('warning', 'declaración de recurso inválida');
        $this->assertSame($invalid, $this->logged[array_key_last($this->logged)]['context']['invalid']);

        if ($secret !== null) {
            $this->assertNeverLogged($secret);
        }
    }

    public function test_una_declaracion_invalida_no_pisa_una_valida_anterior(): void
    {
        $this->gateCall('POST', 'api/v1/messages', $this->key(), [], static function (Request $request): Response {
            CreatedResource::declare('message', 981);
            CreatedResource::declare('contact', '+5491122334455');

            return new Response('creado', 201);
        });

        $this->assertSame('981', $this->onlyRow()['resource_id']);
    }

    public function test_un_rechazo_del_portero_no_llega_al_controlador_y_sale_sin_recurso(): void
    {
        $token = $this->token(['kind' => 'api_key', 'abilities' => ['communities:read']]);

        $response = $this->gateCall('POST', 'api/v1/messages', $token, [], $this->controllerDeclaring('message', 981));

        $this->assertRejected($response, 403, 'permission_denied');

        $row = $this->onlyRow();
        $this->assertSame(403, (int) $row['status_code']);
        $this->assertNull($row['resource_type']);
        $this->assertNull($row['resource_id']);
    }

    public function test_el_front_que_declara_no_deja_fila(): void
    {
        $this->gateCall('POST', 'api/v1/messages', new TransientToken(), [], $this->controllerDeclaring('message', 981));

        $this->assertSame([], $this->rows());
    }

    public function test_se_lee_lo_que_se_declaro(): void
    {
        $this->assertNull(CreatedResource::current());

        CreatedResource::declare('message', 981);

        $this->assertSame('message', CreatedResource::current()?->type);
        $this->assertSame('981', CreatedResource::current()?->id);
    }

    public function test_una_declaracion_no_queda_pegada_al_pedido_siguiente(): void
    {
        // El contexto es del request, pero dos llamadas en el mismo proceso (un test, un worker
        // de larga vida) lo comparten si nadie lo vacía: el portero lo vacía.
        $this->gateCall('POST', 'api/v1/messages', $this->key(), [], $this->controllerDeclaring('message', 981));
        $this->gateCall('POST', 'api/v1/messages', $this->key());

        $this->assertSame(['981', null], array_column($this->rows(), 'resource_id'));
        $this->assertNull(CreatedResource::current());
    }

    public function test_una_declaracion_previa_al_portero_no_cuenta(): void
    {
        CreatedResource::declare('contact', 5);

        $this->gateCall('POST', 'api/v1/messages', $this->key());

        $this->assertNull($this->onlyRow()['resource_id']);
    }
}
