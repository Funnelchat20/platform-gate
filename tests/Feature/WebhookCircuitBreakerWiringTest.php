<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Webhooks\DeliveryCircuitBreaker;

/**
 * El cableado del circuit breaker de webhooks (0.10) a través del service provider, no la
 * lógica en sí —esa está en `DeliveryCircuitBreakerTest`—.
 *
 * Lo que importa acá: un dominio que instala esta versión y no toca config no ve ningún
 * cambio de comportamiento, porque `enabled` sale de config en `false` por default.
 */
final class WebhookCircuitBreakerWiringTest extends GateTestCase
{
    public function test_sin_tocar_config_el_breaker_del_contenedor_nunca_pausa(): void
    {
        $breaker = $this->app->make(DeliveryCircuitBreaker::class);

        for ($i = 0; $i < 20; $i++) {
            $breaker->recordFailure('sub_1');
        }

        $this->assertFalse($breaker->isPaused('sub_1'));
    }

    public function test_prendiendo_el_flag_el_mismo_breaker_del_contenedor_pausa_al_umbral(): void
    {
        $this->app['config']->set('platform-gate.webhook_circuit_breaker.enabled', true);
        $this->app['config']->set('platform-gate.webhook_circuit_breaker.failure_threshold', 2);

        $breaker = $this->app->make(DeliveryCircuitBreaker::class);

        $breaker->recordFailure('sub_1');
        $breaker->recordFailure('sub_1');

        $this->assertTrue($breaker->isPaused('sub_1'));
    }
}
