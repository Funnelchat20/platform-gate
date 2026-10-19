<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\Http\Middleware\TrustEdge;
use Funnelchat\PlatformGate\Http\RequestId;
use Funnelchat\PlatformGate\Http\UnauthenticatedResponse;
use Funnelchat\PlatformGate\Usage\CreatedResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Laravel\Sanctum\HasApiTokens;
use RuntimeException;

/**
 * El id del pedido de punta a punta, con el kernel HTTP y `TrustEdge` en el stack global: el
 * mismo id en la respuesta, en lo que ve el controlador y en la fila, aunque lo pidan dos
 * middleware; y `X-Request-Id` también en lo que no pasó por el portero —un 500 del dominio,
 * el 401—.
 */
final class RequestIdHttpTest extends GateTestCase
{
    use UsageTable;

    private const SECRET = 'actual-3f9a1c7e5b2d4f6081a3c5e7b9d1f3a5';

    private const EDGE_ID = '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('platform-gate.edge_secret', self::SECRET);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(PlatformGate::class)->post('api/v1/messages', static function (Request $request): array {
            CreatedResource::declare('message', 981);

            return ['seen' => RequestId::of($request)];
        });

        // Lo normal en una ruta que crea: el controlador recibe un FormRequest, que es una
        // copia del pedido.
        $router->middleware(PlatformGate::class)->post('api/v1/contacts', static function (StoreContactRequest $request): array {
            CreatedResource::declare('contact', 42);

            return ['seen' => RequestId::of($request), 'name' => $request->validated('name')];
        });

        $router->middleware(PlatformGate::class)->get('api/v1/broken', static fn () => throw new RuntimeException('se rompió'));

        // Fuera del portero, con y sin un `X-Request-Id` propio del dominio.
        $router->get('panel/own-id', static fn () => response('ok')->header(RequestId::RESPONSE_HEADER, 'id-del-dominio'));
        $router->get('panel/plain', static fn () => response('ok'));
        $router->middleware(PlatformGate::class)->get('api/v1/own-id', static fn () => response('ok')->header(RequestId::RESPONSE_HEADER, 'id-del-dominio'));

        $router->get('api/v1/private', static fn () => throw new AuthenticationException());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateUsage();
        $this->app->make(Kernel::class)->pushMiddleware(TrustEdge::class);

        $user = (new class extends User
        {
            use HasApiTokens;
        })->forceFill(['id' => 7]);

        $user->withAccessToken($this->token(['kind' => 'api_key', 'abilities' => ['communities:write']]));

        $this->actingAs($user);
    }

    public function test_el_mismo_id_en_la_respuesta_el_controlador_y_la_fila(): void
    {
        $response = $this->postJson('api/v1/messages', [], [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ]);

        $response->assertOk()->assertHeader(RequestId::RESPONSE_HEADER, self::EDGE_ID)->assertJson(['seen' => self::EDGE_ID]);

        $row = $this->onlyRow();
        $this->assertSame(self::EDGE_ID, $row['request_id']);
        $this->assertSame('message', $row['resource_type']);
        $this->assertSame('981', $row['resource_id']);
    }

    public function test_un_controlador_con_form_request_deja_el_recurso_en_la_fila(): void
    {
        $this->postJson('api/v1/contacts', ['name' => 'x'], [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ])->assertOk()->assertJson(['seen' => self::EDGE_ID, 'name' => 'x']);

        $row = $this->onlyRow();
        $this->assertSame(self::EDGE_ID, $row['request_id']);
        $this->assertSame('contact', $row['resource_type']);
        $this->assertSame('42', $row['resource_id']);
    }

    public function test_generado_tambien_es_uno_solo_aunque_lo_pidan_trust_edge_y_el_portero(): void
    {
        $response = $this->postJson('api/v1/messages')->assertOk();

        $id = $response->headers->get(RequestId::RESPONSE_HEADER);

        $this->assertNotNull($id);
        $response->assertJson(['seen' => $id]);
        $this->assertSame($id, $this->onlyRow()['request_id']);
    }

    public function test_un_500_del_dominio_tambien_lo_devuelve(): void
    {
        $response = $this->getJson('api/v1/broken', [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ]);

        $response->assertStatus(500)->assertHeader(RequestId::RESPONSE_HEADER, self::EDGE_ID);
        $this->assertSame(self::EDGE_ID, $this->onlyRow()['request_id']);
    }

    public function test_fuera_del_portero_se_respeta_el_id_del_dominio(): void
    {
        $this->get('panel/own-id', [EdgeTrust::SECRET_HEADER => self::SECRET, RequestId::EDGE_HEADER => self::EDGE_ID])
            ->assertOk()
            ->assertHeader(RequestId::RESPONSE_HEADER, 'id-del-dominio');
    }

    public function test_fuera_del_portero_sin_id_propio_se_pone_el_del_pedido(): void
    {
        $this->get('panel/plain', [EdgeTrust::SECRET_HEADER => self::SECRET, RequestId::EDGE_HEADER => self::EDGE_ID])
            ->assertOk()
            ->assertHeader(RequestId::RESPONSE_HEADER, self::EDGE_ID);
    }

    public function test_por_el_portero_el_id_del_portero_reemplaza_al_del_dominio(): void
    {
        $this->getJson('api/v1/own-id', [EdgeTrust::SECRET_HEADER => self::SECRET, RequestId::EDGE_HEADER => self::EDGE_ID])
            ->assertOk()
            ->assertHeader(RequestId::RESPONSE_HEADER, self::EDGE_ID);

        $this->assertSame(self::EDGE_ID, $this->onlyRow()['request_id']);
    }

    public function test_con_trust_edge_el_log_queda_limpio_al_terminar_aunque_el_portero_no_corra(): void
    {
        $log = $this->app->make('log');
        $this->app->instance(\Psr\Log\LoggerInterface::class, $log);
        $this->app->forgetInstance(EdgeTrust::class);
        $this->app->forgetInstance(RequestId::class);

        $this->get('panel/plain')->assertOk();

        $this->assertArrayNotHasKey('request_id', $log->sharedContext());
    }

    public function test_el_401_lo_devuelve_aunque_el_portero_no_haya_corrido(): void
    {
        UnauthenticatedResponse::register(new Exceptions($this->app->make(ExceptionHandler::class)));

        $this->getJson('api/v1/private', [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ])->assertStatus(401)->assertHeader(RequestId::RESPONSE_HEADER, self::EDGE_ID);
    }
}

final class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}
