<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Closure;
use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\Http\Middleware\TrustEdge;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\HasApiTokens;

/**
 * De punta a punta, con el kernel HTTP de Laravel, `TrustEdge` en el stack global y el
 * `trustProxies` que pide «Actualizar desde 0.6»: For, Proto y Port, sin Host ni Prefix.
 *
 * Lo que importa es lo que ve quien integra: los links que arma el dominio —`$request->url()`,
 * el paginador, `url()`— y la IP que ven el registro y los límites del dominio.
 */
final class EdgeHttpTest extends GateTestCase
{
    private const SECRET = 'actual-3f9a1c7e5b2d4f6081a3c5e7b9d1f3a5';

    /** Lo que agrega AWS delante de Lambda: el pedido le llegó por https. */
    private const AWS = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('platform-gate.edge_secret', self::SECRET);
        $app['config']->set('platform-gate.public_hosts', 'api.funnelchat.com,api-staging.funnelchat.com');
        $app['config']->set('platform-gate.require_edge', true);
    }

    protected function defineRoutes($router): void
    {
        $show = static function (Request $request): array {
            $page = new LengthAwarePaginator(range(1, 10), 30, 10, 2, ['path' => Paginator::resolveCurrentPath()]);

            return [
                'url' => $request->url(),
                'generated' => url('api/v1/groups/9'),
                'next' => $page->nextPageUrl(),
                'ip' => $request->ip(),
                'warmed' => $request->attributes->get('warmed'),
            ];
        };

        $router->middleware(PlatformGate::class)->get('api/v1/groups', $show);

        // Sin `TrustEdge` global, y con algo que arma una URL antes del portero.
        $router->middleware(['warm-url-cache', PlatformGate::class])->get('api/v1/campaigns', $show);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // El `trustProxies` de «Actualizar desde 0.6».
        TrustProxies::at('*');
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

        $this->app->make(Kernel::class)->pushMiddleware(TrustEdge::class);

        $this->app->instance('warm-url-cache', new class
        {
            public function handle(Request $request, Closure $next): mixed
            {
                $request->attributes->set('warmed', url('/'));

                return $next($request);
            }
        });

        $user = (new class extends User
        {
            use HasApiTokens;
        })->forceFill(['id' => 7]);

        $user->withAccessToken($this->token(['kind' => 'api_key', 'abilities' => ['communities:read']]));

        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], -1);

        parent::tearDown();
    }

    public function test_con_secreto_valido_los_links_salen_con_el_host_publico(): void
    {
        $body = $this->fetch('api/v1/groups', [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            'X-Forwarded-Host' => 'api.funnelchat.com',
            'X-Funnelchat-Client-IP' => '203.0.113.9',
        ])->assertOk()->json();

        $this->assertSame('https://api.funnelchat.com/api/v1/groups', $body['url']);
        $this->assertSame('https://api.funnelchat.com/api/v1/groups/9', $body['generated']);
        $this->assertSame('https://api.funnelchat.com/api/v1/groups?page=3', $body['next']);
        $this->assertSame('203.0.113.9', $body['ip']);
    }

    public function test_con_secreto_valido_y_staging_los_links_salen_con_staging(): void
    {
        $body = $this->fetch('api/v1/groups', [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            'X-Forwarded-Host' => 'api-staging.funnelchat.com',
        ])->assertOk()->json();

        $this->assertSame('https://api-staging.funnelchat.com/api/v1/groups?page=3', $body['next']);
    }

    public function test_sin_secreto_x_forwarded_host_no_cambia_ningun_link(): void
    {
        // Con la exigencia apagada —la ventana en la que llega directo una key— el pedido
        // pasa, y lo que mandó el cliente no toca nada.
        config(['platform-gate.require_edge' => false]);
        $this->app->forgetScopedInstances();

        $body = $this->fetch('api/v1/groups', [
            'X-Forwarded-Host' => 'ejemplo-externo.test',
            'X-Funnelchat-Client-IP' => '203.0.113.9',
            'X-Forwarded-Prefix' => '/otro',
        ])->assertOk()->json();

        $this->assertSame('https://'.self::INTERNAL_HOST.'/api/v1/groups', $body['url']);
        $this->assertSame('https://'.self::INTERNAL_HOST.'/api/v1/groups/9', $body['generated']);
        $this->assertSame('https://'.self::INTERNAL_HOST.'/api/v1/groups?page=3', $body['next']);
        $this->assertSame('127.0.0.1', $body['ip']);
    }

    public function test_sin_secreto_y_con_la_exigencia_encendida_la_key_no_pasa(): void
    {
        $this->fetch('api/v1/groups', ['X-Forwarded-Host' => 'api.funnelchat.com'])
            ->assertForbidden()
            ->assertJson(['error' => 'use_public_endpoint', 'message' => 'Usá https://api.funnelchat.com']);
    }

    public function test_con_secreto_valido_un_host_fuera_de_la_lista_no_cambia_ningun_link(): void
    {
        $body = $this->fetch('api/v1/groups', [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            'X-Forwarded-Host' => 'ejemplo-externo.test',
        ])->assertOk()->json();

        $this->assertSame('https://'.self::INTERNAL_HOST.'/api/v1/groups?page=3', $body['next']);
    }

    public function test_sin_trust_edge_global_el_portero_igual_reescribe_y_olvida_la_raiz_ya_armada(): void
    {
        // Algo armó una URL antes del portero: el generador guardó la raíz interna. El portero
        // la tiene que hacer olvidar, o `url()` saldría con el host interno.
        $this->app->make(Kernel::class)->setGlobalMiddleware(array_values(array_filter(
            $this->app->make(Kernel::class)->getGlobalMiddleware(),
            static fn ($middleware) => $middleware !== TrustEdge::class,
        )));

        $body = $this->fetch('api/v1/campaigns', [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            'X-Forwarded-Host' => 'api.funnelchat.com',
            'X-Funnelchat-Client-IP' => '203.0.113.9',
        ])->assertOk()->json();

        // El generador sí guardó la raíz interna antes del portero…
        $this->assertSame('https://'.self::INTERNAL_HOST, $body['warmed']);

        // …y después del portero arma con la pública.
        $this->assertSame('https://api.funnelchat.com/api/v1/groups/9', $body['generated']);
        $this->assertSame('https://api.funnelchat.com/api/v1/campaigns?page=3', $body['next']);
        $this->assertSame('203.0.113.9', $body['ip']);
    }

    public function test_con_el_trust_proxies_de_0_6_cualquiera_cambia_los_links(): void
    {
        // Por qué la guía pide sacar Host (y Prefix) de `trustProxies`: con los headers por
        // defecto de Laravel, el portero no puede hacer nada — Laravel le cree al cliente
        // antes. Es lo que se vio en staging el 09-10. Este test documenta el agujero que el
        // cambio de `trustProxies` cierra; si un día deja de fallar así, cambió Laravel.
        TrustProxies::flushState();
        TrustProxies::at('*');
        config(['platform-gate.require_edge' => false]);
        $this->app->forgetScopedInstances();

        $body = $this->fetch('api/v1/groups', [
            'X-Forwarded-Host' => 'ejemplo-externo.test',
            'X-Forwarded-Prefix' => '/otro',
        ])->assertOk()->json();

        $this->assertSame('https://ejemplo-externo.test/otro/api/v1/groups?page=3', $body['next']);
    }

    /** @param array<string, string> $headers */
    private function fetch(string $path, array $headers): TestResponse
    {
        return $this->withHeaders(self::AWS + $headers)->getJson('http://'.self::INTERNAL_HOST.'/'.$path);
    }
}
