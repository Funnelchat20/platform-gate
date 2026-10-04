<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\CallerKind;

final class AllowedListTest extends GateTestCase
{
    public function test_sin_allowed_una_key_no_alcanza_ninguna_ruta(): void
    {
        config(['platform-gate.allowed' => []]);

        $token = $this->token([
            'kind' => 'api_key',
            'abilities' => ['communities:send', 'communities:operate'],
        ]);

        $this->assertRejected($this->send('GET', 'api/v1/groups', $token), 403, 'route_not_available');
        $this->assertRejected($this->send('POST', 'api/v1/groups', $token), 403, 'route_not_available');
    }

    public function test_sin_allowed_el_front_no_se_entera(): void
    {
        // `allowed` es la superficie de las KEYS. Una sesión web no pasa por ahí.
        config(['platform-gate.allowed' => []]);

        $token = $this->token(['kind' => 'web', 'abilities' => ['*']]);

        $this->assertSame(200, $this->send('POST', 'api/v1/groups', $token)->getStatusCode());
        $this->assertSame(CallerKind::Web, $this->caller()->kind);
    }

    public function test_una_config_publicada_sin_la_clave_allowed_tambien_cierra(): void
    {
        // Un `config/platform-gate.php` publicado antes de que existiera la clave puede
        // traerla en `null`: tiene que leerse igual que una lista vacía.
        config(['platform-gate.allowed' => null]);

        $token = $this->token(['kind' => 'api_key', 'abilities' => ['communities:read']]);

        $this->assertRejected($this->send('GET', 'api/v1/groups', $token), 403, 'route_not_available');
    }
}
