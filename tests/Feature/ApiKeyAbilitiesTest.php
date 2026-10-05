<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\CallerKind;

final class ApiKeyAbilitiesTest extends GateTestCase
{
    public function test_el_comodin_no_habilita_ninguna_ruta_a_una_key(): void
    {
        $token = $this->token(['kind' => 'api_key', 'abilities' => ['*']]);

        $this->assertRejected($this->send('GET', 'api/v1/groups', $token), 403, 'permission_denied');
        $this->assertRejected($this->send('POST', 'api/v1/groups', $token), 403, 'permission_denied');
    }

    public function test_el_permiso_se_compara_literal_contra_la_lista_de_la_key(): void
    {
        $reader = $this->token(['kind' => 'api_key', 'abilities' => ['communities:read']]);

        $this->assertSame(200, $this->send('GET', 'api/v1/groups', $reader)->getStatusCode());
        $this->assertRejected($this->send('POST', 'api/v1/groups', $reader), 403, 'permission_denied');
    }

    public function test_el_dominio_ve_los_permisos_que_trae_la_key(): void
    {
        // Para ramificar por permiso (p. ej. `operate`) el dominio lee la misma lista que
        // usó el portero, aunque el modelo de token no tenga `getAbilities()`.
        $token = $this->token(['kind' => 'api_key', 'abilities' => ['communities:read', 'communities:operate']]);

        $this->assertSame(200, $this->send('GET', 'api/v1/groups', $token)->getStatusCode());

        $caller = $this->caller();
        $this->assertSame(CallerKind::ApiKey, $caller->kind);
        $this->assertSame(['communities:read', 'communities:operate'], $caller->permissions);
        $this->assertTrue($caller->can('communities:operate'));
        $this->assertFalse($caller->can('communities:send'));
    }

    public function test_si_no_puede_leer_la_lista_de_la_key_corta_con_503(): void
    {
        // El dominio no selecciona `abilities` (o el modelo no la expone): no hay con qué
        // evaluar. Se corta, reintentable — ni 403, ni dejar pasar, ni un 500.
        $token = $this->token(['kind' => 'api_key']);

        $response = $this->send('GET', 'api/v1/groups', $token);

        $this->assertRejected($response, 503, 'gate_unavailable');
        $this->assertSame('5', $response->headers->get('Retry-After'));
        $this->assertNotSame(CallerKind::ApiKey, $this->caller()->kind);
    }
}
