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
            CreatedResource::declare($request, $type, $id);

            return new Response('creado', $status);
        };
    }

    /** @return iterable<string, array{string, int|string, string}> */
    public static function validDeclarations(): iterable
    {
        yield 'id entero' => ['message', 981, '981'];
        yield 'id como texto' => ['contact', '42', '42'];
        yield 'uuid' => ['scheduled_message', '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60', '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60'];
        yield 'ulid' => ['tag', '01JA2B3C4D5E6F7G8H9J0K1M2N', '01JA2B3C4D5E6F7G8H9J0K1M2N'];
        yield 'con prefijo' => ['webhook_subscription', 'whs_9f2c4e1a', 'whs_9f2c4e1a'];
        yield '64 de largo' => ['note', str_repeat('a', 64), str_repeat('a', 64)];
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
            CreatedResource::declare($request, 'contact', 5);
            CreatedResource::declare($request, 'message', 981);

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
        yield 'vacío' => ['message', '', 'id', null];
        yield 'id de 65' => ['message', str_repeat('a', 65), 'id', str_repeat('a', 65)];
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
            CreatedResource::declare($request, 'message', 981);
            CreatedResource::declare($request, 'contact', '+5491122334455');

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
        $request = Request::create('/api/v1/messages', 'POST');

        $this->assertNull(CreatedResource::of($request));

        CreatedResource::declare($request, 'message', 981);

        $this->assertSame('message', CreatedResource::of($request)?->type);
        $this->assertSame('981', CreatedResource::of($request)?->id);
    }

    public function test_el_cliente_no_puede_declarar_por_query_ni_por_cuerpo(): void
    {
        // Los atributos del pedido no se llenan desde la entrada: sólo el código los escribe.
        $request = Request::create('/api/v1/messages', 'POST', [CreatedResource::ATTRIBUTE => 'message']);

        $this->assertNull(CreatedResource::of($request));
    }
}
