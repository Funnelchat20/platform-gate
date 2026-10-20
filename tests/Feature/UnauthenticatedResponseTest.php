<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Http\UnauthenticatedResponse;
use Funnelchat\PlatformGate\PlatformGateServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Orchestra\Testbench\TestCase;

/**
 * El 401 de `api/*` es el mismo en todos los dominios, y no toca nada más.
 */
final class UnauthenticatedResponseTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PlatformGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('platform-gate.domain', 'communities');
    }

    protected function defineRoutes($router): void
    {
        $router->get('api/v1/groups', static fn () => throw new AuthenticationException());
        $router->get('api/v1/forbidden', static fn () => throw new AuthorizationException('No.'));
        $router->get('panel/groups', static fn () => throw new AuthenticationException());
        $router->get('v2/groups', static fn () => throw new AuthenticationException());
    }

    private function hook(string ...$paths): void
    {
        UnauthenticatedResponse::register(new Exceptions($this->app->make(ExceptionHandler::class)), ...$paths);
    }

    public function test_api_sin_autenticar_da_el_401_uniforme(): void
    {
        $this->hook();

        $this->getJson('api/v1/groups')
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthenticated', 'message' => UnauthenticatedResponse::MESSAGE])
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_lleva_x_request_id_aunque_no_haya_trust_edge(): void
    {
        // El portero no corrió y `TrustEdge` no está montado: el 401 igual se puede citar.
        $this->hook();

        $id = $this->getJson('api/v1/groups')->assertStatus(401)->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $id);
    }

    public function test_tambien_cuando_el_pedido_no_pide_json(): void
    {
        // Sin esto, Laravel redirige al login: en una API sin ruta `login`, un 500.
        $this->hook();

        $this->get('api/v1/groups', ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthenticated', 'message' => UnauthenticatedResponse::MESSAGE]);
    }

    public function test_fuera_de_api_contesta_lo_de_siempre(): void
    {
        $this->hook();

        $this->getJson('panel/groups')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_no_se_lleva_puesto_otro_rechazo(): void
    {
        $this->hook();

        $this->getJson('api/v1/forbidden')
            ->assertStatus(403)
            ->assertJsonMissingPath('error');
    }

    public function test_sin_engancharlo_el_dominio_sigue_como_antes(): void
    {
        $this->getJson('api/v1/groups')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_los_patrones_se_pueden_elegir(): void
    {
        $this->hook('v2/*');

        $this->getJson('v2/groups')->assertStatus(401)->assertJson(['error' => 'unauthenticated']);
        $this->getJson('api/v1/groups')->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
