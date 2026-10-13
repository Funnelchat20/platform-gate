<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\CallerKind;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * El permiso por ruta (Q48) de punta a punta: accounts sirve los webhooks y los quiere detrás
 * de `webhooks:read` / `webhooks:write`, no de `accounts:{grado}`.
 */
final class PermissionPrefixTest extends GateTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('platform-gate.domain', 'accounts');
        $app['config']->set('platform-gate.permission_prefix', ['api/v1/webhooks*' => 'webhooks']);
        $app['config']->set('platform-gate.send', ['POST api/v1/webhooks/{webhook}/test']);
    }

    /** @return iterable<string, array{list<string>, string, string, ?string}> */
    public static function calls(): iterable
    {
        // permisos de la key, método, ruta, error esperado (null = pasa)
        yield 'read lee' => [['webhooks:read'], 'GET', 'api/v1/webhooks', null];
        yield 'read no escribe' => [['webhooks:read'], 'POST', 'api/v1/webhooks', 'permission_denied'];
        yield 'write escribe' => [['webhooks:write'], 'DELETE', 'api/v1/webhooks/{webhook}', null];
        yield 'write lee (escalera dentro del prefijo)' => [['webhooks:write'], 'GET', 'api/v1/webhooks/{webhook}', null];
        yield 'write no manda' => [['webhooks:write'], 'POST', 'api/v1/webhooks/{webhook}/test', 'permission_denied'];
        yield 'send manda' => [['webhooks:send'], 'POST', 'api/v1/webhooks/{webhook}/test', null];
        yield 'send escribe y lee' => [['webhooks:send'], 'GET', 'api/v1/webhooks', null];
        yield 'el permiso del dominio no alcanza para leer' => [['accounts:write'], 'GET', 'api/v1/webhooks', 'permission_denied'];
        yield 'el permiso del dominio no alcanza para escribir' => [['accounts:send'], 'POST', 'api/v1/webhooks', 'permission_denied'];
        yield 'webhooks no alcanza fuera del prefijo' => [['webhooks:send'], 'GET', 'api/v1/devices', 'permission_denied'];
        yield 'una ruta sin declarar pide lo de 0.6' => [['accounts:read'], 'GET', 'api/v1/devices', null];
        yield 'una ruta sin declarar sigue negando lo de 0.6' => [['accounts:read'], 'POST', 'api/v1/devices', 'permission_denied'];
    }

    /** @param list<string> $abilities */
    #[DataProvider('calls')]
    public function test_permiso_por_ruta(array $abilities, string $method, string $uri, ?string $error): void
    {
        $response = $this->send($method, $uri, $this->token(['kind' => 'api_key', 'abilities' => $abilities]));

        if ($error === null) {
            $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $this->assertSame(CallerKind::ApiKey, $this->caller()->kind);

            return;
        }

        $this->assertRejected($response, 403, $error);
    }

    public function test_el_403_nombra_el_permiso_declarado(): void
    {
        $response = $this->send('POST', 'api/v1/webhooks', $this->token(['kind' => 'api_key', 'abilities' => ['accounts:write']]));

        $this->assertStringContainsString('`webhooks:write`', (string) $response->getContent());
    }

    public function test_declarar_un_prefijo_no_abre_superficie(): void
    {
        config(['platform-gate.allowed' => ['api/v1/devices*']]);

        $response = $this->send('GET', 'api/v1/webhooks', $this->token(['kind' => 'api_key', 'abilities' => ['webhooks:read']]));

        $this->assertRejected($response, 403, 'route_not_available');
    }

    public function test_si_alguien_escribe_el_permiso_entero_corta_con_503(): void
    {
        // El error más probable: `'GET api/v1/webhooks' => 'webhooks:read'`. No se adivina qué
        // quiso decir, y no se cae a `accounts:read` en la ruta que alguien quiso sacar de ahí.
        config(['platform-gate.permission_prefix' => ['GET api/v1/webhooks' => 'webhooks:read']]);

        $response = $this->send('GET', 'api/v1/webhooks', $this->token(['kind' => 'api_key', 'abilities' => ['accounts:read', 'webhooks:read']]));

        $this->assertRejected($response, 503, 'gate_unavailable');
        $this->assertNotSame(CallerKind::ApiKey, $this->caller()->kind);
    }

    public function test_al_panel_no_le_cambia_nada(): void
    {
        $response = $this->send('POST', 'api/v1/webhooks', $this->token(['kind' => 'web', 'abilities' => ['*']]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(CallerKind::Web, $this->caller()->kind);
    }
}
