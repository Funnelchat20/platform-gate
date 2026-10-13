<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\CallerKind;
use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\Http\Middleware\TrustEdge;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * El portero frente al borde: qué secreto vale, a quién se le exige y qué se le cree.
 *
 * Los links y `$request->ip()` de punta a punta, con el kernel HTTP y el `trustProxies` que
 * pide la guía, están en `EdgeHttpTest`. Acá, la decisión.
 */
final class EdgeTest extends GateTestCase
{
    private const SECRET = 'actual-3f9a1c7e5b2d4f6081a3c5e7b9d1f3a5';

    private const NEXT = 'siguiente-8e2b4d6f0a1c3e5b7d9f1a3c5e7b9d1f';

    private const WRONG = 'cualquier-otro-valor-7c1e3a5b9d2f4e6a8b0c';

    private const PUBLIC_HOST = 'api.funnelchat.com';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('platform-gate.edge_secret', self::SECRET);
        $app['config']->set('platform-gate.edge_secret_next', self::NEXT);
        $app['config']->set('platform-gate.public_hosts', 'api.funnelchat.com, api-staging.funnelchat.com');
    }

    /** @return iterable<string, array{?string, bool, int, ?string, bool}> */
    public static function secrets(): iterable
    {
        // secreto presentado, exigencia, status, error, ¿se le cree el host?
        yield 'actual, exigencia apagada' => [self::SECRET, false, 200, null, true];
        yield 'siguiente, exigencia apagada' => [self::NEXT, false, 200, null, true];
        yield 'ausente, exigencia apagada' => [null, false, 200, null, false];
        yield 'incorrecto, exigencia apagada' => [self::WRONG, false, 200, null, false];
        yield 'actual, exigencia encendida' => [self::SECRET, true, 200, null, true];
        yield 'siguiente, exigencia encendida' => [self::NEXT, true, 200, null, true];
        yield 'ausente, exigencia encendida' => [null, true, 403, 'use_public_endpoint', false];
        yield 'incorrecto, exigencia encendida' => [self::WRONG, true, 403, 'use_public_endpoint', false];
    }

    #[DataProvider('secrets')]
    public function test_una_key_frente_al_secreto_del_borde(?string $secret, bool $required, int $status, ?string $error, bool $trusted): void
    {
        config(['platform-gate.require_edge' => $required]);

        $response = $this->sendKey($secret, ['X-Forwarded-Host' => self::PUBLIC_HOST]);

        if ($error === null) {
            $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
            $this->assertSame(CallerKind::ApiKey, $this->caller()->kind);
        } else {
            $this->assertRejected($response, $status, $error);
            $this->assertSame('Usá https://api.funnelchat.com', json_decode((string) $response->getContent(), true)['message']);
            $this->assertNull($response->headers->get('Retry-After'));
            $this->assertNotSame(CallerKind::ApiKey, $this->caller()->kind);
        }

        $this->assertSame($trusted ? self::PUBLIC_HOST : self::INTERNAL_HOST, $this->lastRequest->getHost());
        $this->assertSame($trusted, $this->lastRequest->attributes->get(EdgeTrust::VERIFIED));
    }

    public function test_el_403_dice_la_url_publica_configurada(): void
    {
        config([
            'platform-gate.require_edge' => true,
            'platform-gate.public_url' => 'https://api-staging.funnelchat.com',
        ]);

        $response = $this->sendKey(null);

        $this->assertRejected($response, 403, 'use_public_endpoint');
        $this->assertSame('Usá https://api-staging.funnelchat.com', json_decode((string) $response->getContent(), true)['message']);
    }

    public function test_el_panel_no_necesita_el_secreto_con_la_exigencia_encendida(): void
    {
        config(['platform-gate.require_edge' => true]);

        $web = $this->token(['kind' => 'web', 'abilities' => ['*']]);

        $this->assertSame(200, $this->send('POST', 'api/v1/groups', $web, host: self::INTERNAL_HOST)->getStatusCode());
        $this->assertSame(CallerKind::Web, $this->caller()->kind);

        $this->assertSame(200, $this->send('POST', 'api/v1/groups', new TransientToken(), host: self::INTERNAL_HOST)->getStatusCode());
        $this->assertSame(CallerKind::Web, $this->caller()->kind);
    }

    public function test_sin_token_la_exigencia_no_decide_nada(): void
    {
        // Sin token, el 401 lo da `auth:sanctum` del dominio. El portero no inventa un 403.
        config(['platform-gate.require_edge' => true]);

        $this->assertSame(200, $this->send('GET', 'api/v1/groups', null, host: self::INTERNAL_HOST)->getStatusCode());
        $this->assertSame(CallerKind::Unverified, $this->caller()->kind);
    }

    public function test_exigencia_encendida_sin_secreto_configurado_corta_con_503(): void
    {
        // No hay contra qué comparar: "no pude evaluar", reintentable. Ni 403, ni dejar pasar.
        config([
            'platform-gate.require_edge' => true,
            'platform-gate.edge_secret' => null,
            'platform-gate.edge_secret_next' => null,
        ]);

        $response = $this->sendKey(self::SECRET);

        $this->assertRejected($response, 503, 'gate_unavailable');
        $this->assertNotSame(CallerKind::ApiKey, $this->caller()->kind);
        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
    }

    public function test_un_secreto_corto_no_es_secreto(): void
    {
        config([
            'platform-gate.require_edge' => true,
            'platform-gate.edge_secret' => 'changeme',
            'platform-gate.edge_secret_next' => null,
        ]);

        $response = $this->sendKey('changeme', ['X-Forwarded-Host' => self::PUBLIC_HOST]);

        $this->assertRejected($response, 503, 'gate_unavailable');
        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
        $this->assertLoggedError('`PLATFORM_GATE_EDGE_SECRET` no se usa');
        $this->assertNeverLogged('changeme');
    }

    public function test_un_secreto_corto_tampoco_hace_creer_el_host_con_la_exigencia_apagada(): void
    {
        config(['platform-gate.edge_secret' => 'changeme', 'platform-gate.edge_secret_next' => null]);

        $this->assertSame(200, $this->sendKey('changeme', ['X-Forwarded-Host' => self::PUBLIC_HOST])->getStatusCode());
        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
    }

    public function test_una_cabecera_vacia_no_coincide_con_el_siguiente_vacio(): void
    {
        config(['platform-gate.require_edge' => true, 'platform-gate.edge_secret_next' => '']);

        $this->assertRejected($this->sendKey(''), 403, 'use_public_endpoint');
    }

    public function test_dos_cabeceras_del_borde_no_valen_aunque_las_dos_sean_secretos(): void
    {
        // El Worker borra la del cliente antes de poner la suya: dos es un pedido que no armó.
        config(['platform-gate.require_edge' => true]);

        $this->assertRejected($this->sendKey([self::SECRET, self::NEXT]), 403, 'use_public_endpoint');
    }

    public function test_con_secreto_valido_un_host_fuera_de_la_lista_se_ignora(): void
    {
        $response = $this->sendKey(self::SECRET, ['X-Forwarded-Host' => 'ejemplo-externo.test']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
        $this->assertSame('http://'.self::INTERNAL_HOST.'/api/v1/groups', $this->lastRequest->url());
        $this->assertLogged('warning', 'no está en `PLATFORM_GATE_PUBLIC_HOSTS`');
    }

    public function test_con_secreto_valido_dos_hosts_no_eligen_uno(): void
    {
        $this->sendKey(self::SECRET, ['X-Forwarded-Host' => [self::PUBLIC_HOST, 'ejemplo-externo.test']]);

        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
    }

    public function test_sin_lista_de_hosts_no_se_cree_ningun_host(): void
    {
        config(['platform-gate.public_hosts' => '']);

        $this->sendKey(self::SECRET, ['X-Forwarded-Host' => self::PUBLIC_HOST]);

        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
    }

    public function test_una_url_en_la_lista_de_hosts_no_es_un_host(): void
    {
        config(['platform-gate.public_hosts' => 'https://api.funnelchat.com']);

        $this->sendKey(self::SECRET, ['X-Forwarded-Host' => self::PUBLIC_HOST]);

        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
        $this->assertLoggedError('no es un host');
    }

    public function test_con_secreto_valido_el_pedido_queda_con_el_host_y_la_ip_del_cliente(): void
    {
        $this->sendKey(self::SECRET, [
            'X-Forwarded-Host' => 'API-Staging.Funnelchat.com',
            'X-Funnelchat-Client-IP' => '203.0.113.9',
        ]);

        $this->assertSame('api-staging.funnelchat.com', $this->lastRequest->getHost());
        $this->assertSame('http://api-staging.funnelchat.com/api/v1/groups', $this->lastRequest->url());
        $this->assertSame('203.0.113.9', $this->lastRequest->headers->get('X-Forwarded-For'));
    }

    public function test_sin_secreto_ni_el_host_ni_la_ip_cambian_nada(): void
    {
        $this->sendKey(null, [
            'X-Forwarded-Host' => self::PUBLIC_HOST,
            'X-Funnelchat-Client-IP' => '203.0.113.9',
            'X-Forwarded-For' => '198.51.100.20',
        ]);

        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
        $this->assertSame('198.51.100.20', $this->lastRequest->headers->get('X-Forwarded-For'));
    }

    public function test_con_secreto_incorrecto_ni_el_host_ni_la_ip_cambian_nada(): void
    {
        $this->sendKey(self::WRONG, [
            'X-Forwarded-Host' => self::PUBLIC_HOST,
            'X-Funnelchat-Client-IP' => '203.0.113.9',
        ]);

        $this->assertSame(self::INTERNAL_HOST, $this->lastRequest->getHost());
        $this->assertNull($this->lastRequest->headers->get('X-Forwarded-For'));
        $this->assertLogged('warning', 'no coincide con ninguno de los dos secretos');
    }

    public function test_una_ip_del_cliente_que_no_es_ip_se_ignora(): void
    {
        $this->sendKey(self::SECRET, ['X-Funnelchat-Client-IP' => '203.0.113.9, 10.0.0.1']);

        $this->assertNull($this->lastRequest->headers->get('X-Forwarded-For'));
        $this->assertLogged('warning', 'no es una IP');
    }

    /** @return iterable<string, array{?string}> */
    public static function presentedSecrets(): iterable
    {
        yield 'el actual' => [self::SECRET];
        yield 'el siguiente' => [self::NEXT];
        yield 'uno incorrecto' => [self::WRONG];
    }

    #[DataProvider('presentedSecrets')]
    public function test_el_secreto_sale_del_pedido_y_no_llega_al_log_ni_a_la_respuesta(string $presented): void
    {
        config(['platform-gate.require_edge' => true]);

        $response = $this->sendKey($presented, ['X-Forwarded-Host' => 'ejemplo-externo.test']);

        $this->assertFalse($this->lastRequest->headers->has(EdgeTrust::SECRET_HEADER));
        $this->assertFalse($this->lastRequest->server->has('HTTP_X_FUNNELCHAT_EDGE'));
        $this->assertStringNotContainsString($presented, (string) $response->getContent());
        $this->assertStringNotContainsString($presented, (string) json_encode($response->headers->all()));
        $this->assertNeverLogged(self::SECRET);
        $this->assertNeverLogged(self::NEXT);
        $this->assertNeverLogged(self::WRONG);
    }

    public function test_el_borde_no_da_permisos(): void
    {
        // Llegar por el borde dice por dónde vino la key, no qué puede hacer.
        config(['platform-gate.require_edge' => true]);

        $reader = $this->token(['kind' => 'api_key', 'abilities' => ['communities:read']]);

        $response = $this->send('POST', 'api/v1/groups', $reader, [EdgeTrust::SECRET_HEADER => self::SECRET], self::INTERNAL_HOST);

        $this->assertRejected($response, 403, 'permission_denied');
    }

    public function test_con_trust_edge_montado_el_portero_lee_su_veredicto(): void
    {
        // `TrustEdge` saca el secreto del pedido; el portero no lo vuelve a buscar, lee lo
        // que decidió. Si lo buscara, la exigencia rechazaría todo lo que viene del borde.
        config(['platform-gate.require_edge' => true]);

        $request = $this->request('GET', 'api/v1/groups', $this->key(), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            'X-Forwarded-Host' => self::PUBLIC_HOST,
        ], self::INTERNAL_HOST);

        $response = $this->app->make(TrustEdge::class)->handle(
            $request,
            fn ($request) => $this->app->make(PlatformGate::class)->handle($request, static fn () => new Response('ok')),
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(CallerKind::ApiKey, $this->caller()->kind);
        $this->assertSame(self::PUBLIC_HOST, $request->getHost());
    }

    public function test_con_trust_edge_montado_un_secreto_incorrecto_sigue_rechazado(): void
    {
        config(['platform-gate.require_edge' => true]);

        $request = $this->request('GET', 'api/v1/groups', $this->key(), [EdgeTrust::SECRET_HEADER => self::WRONG], self::INTERNAL_HOST);

        $response = $this->app->make(TrustEdge::class)->handle(
            $request,
            fn ($request) => $this->app->make(PlatformGate::class)->handle($request, static fn () => new Response('ok')),
        );

        $this->assertRejected($response, 403, 'use_public_endpoint');
    }

    /**
     * @param string|list<string>|null    $secret
     * @param array<string, string|list<string>> $headers
     */
    private function sendKey(string|array|null $secret, array $headers = []): Response
    {
        if ($secret !== null) {
            $headers[EdgeTrust::SECRET_HEADER] = $secret;
        }

        return $this->send('GET', 'api/v1/groups', $this->key(), $headers, self::INTERNAL_HOST);
    }

    private function key(): object
    {
        return $this->token(['kind' => 'api_key', 'abilities' => ['communities:read']]);
    }
}
