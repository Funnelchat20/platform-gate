<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit\Webhooks;

use Funnelchat\PlatformGate\Webhooks\DeliveryCircuitBreaker;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeliveryCircuitBreakerTest extends TestCase
{
    public function test_apagado_por_default_nunca_pausa_aunque_falle_siempre(): void
    {
        // La restricción dura: instalar 0.10 no cambia nada hasta que el dominio prenda
        // el flag a propósito.
        $breaker = new DeliveryCircuitBreaker($this->arrayCache(), enabled: false, failureThreshold: 1, windowSeconds: 60, pauseSeconds: 60);

        for ($i = 0; $i < 50; $i++) {
            $breaker->recordFailure('sub_1');
        }

        $this->assertFalse($breaker->isPaused('sub_1'));
    }

    public function test_antes_del_umbral_no_pausa(): void
    {
        $breaker = new DeliveryCircuitBreaker($this->arrayCache(), enabled: true, failureThreshold: 3, windowSeconds: 60, pauseSeconds: 60);

        $breaker->recordFailure('sub_1');
        $breaker->recordFailure('sub_1');

        $this->assertFalse($breaker->isPaused('sub_1'));
    }

    public function test_al_llegar_al_umbral_de_fallos_consecutivos_pausa(): void
    {
        $breaker = new DeliveryCircuitBreaker($this->arrayCache(), enabled: true, failureThreshold: 3, windowSeconds: 60, pauseSeconds: 60);

        $breaker->recordFailure('sub_1');
        $breaker->recordFailure('sub_1');
        $breaker->recordFailure('sub_1');

        $this->assertTrue($breaker->isPaused('sub_1'));
    }

    public function test_pausar_una_suscripcion_no_afecta_a_otra(): void
    {
        $breaker = new DeliveryCircuitBreaker($this->arrayCache(), enabled: true, failureThreshold: 2, windowSeconds: 60, pauseSeconds: 60);

        $breaker->recordFailure('sub_muerta');
        $breaker->recordFailure('sub_muerta');

        $this->assertTrue($breaker->isPaused('sub_muerta'));
        $this->assertFalse($breaker->isPaused('sub_sana'));
    }

    public function test_un_exito_cierra_el_circuito_y_vacia_lo_acumulado(): void
    {
        $cache = $this->arrayCache();
        $breaker = new DeliveryCircuitBreaker($cache, enabled: true, failureThreshold: 3, windowSeconds: 60, pauseSeconds: 60);

        $breaker->recordFailure('sub_1');
        $breaker->recordFailure('sub_1');
        $breaker->recordSuccess('sub_1');

        // Si el éxito no hubiera vaciado el contador, este tercer fallo alcanzaría el
        // umbral (2 + 1) y pausaría.
        $breaker->recordFailure('sub_1');

        $this->assertFalse($breaker->isPaused('sub_1'));
    }

    public function test_umbral_cero_o_negativo_es_sin_circuit_breaker(): void
    {
        $breaker = new DeliveryCircuitBreaker($this->arrayCache(), enabled: true, failureThreshold: 0, windowSeconds: 60, pauseSeconds: 60);

        $breaker->recordFailure('sub_1');
        $breaker->recordFailure('sub_1');

        $this->assertFalse($breaker->isPaused('sub_1'));
    }

    public function test_si_el_store_se_cae_falla_abierto_al_leer(): void
    {
        // Al revés de DailyCallCap (P7, falla cerrado): acá no pausamos por un store
        // caído, porque frenar entregas reales por un problema de infraestructura ajeno
        // a la suscripción es peor que seguir intentando.
        $breaker = new DeliveryCircuitBreaker($this->brokenCache(), enabled: true, failureThreshold: 1, windowSeconds: 60, pauseSeconds: 60);

        $this->assertFalse($breaker->isPaused('sub_1'));
    }

    public function test_si_el_store_se_cae_falla_abierto_al_contar_un_fallo(): void
    {
        $breaker = new DeliveryCircuitBreaker($this->brokenCache(), enabled: true, failureThreshold: 1, windowSeconds: 60, pauseSeconds: 60);

        // No debe tirar: medición, no control.
        $breaker->recordFailure('sub_1');
        $breaker->recordSuccess('sub_1');

        $this->addToAssertionCount(1);
    }

    private function arrayCache(): Cache
    {
        return new class implements Cache
        {
            /** @var array<string, mixed> */
            private array $store = [];

            public function add($key, $value, $ttl = null): bool
            {
                if (array_key_exists($key, $this->store)) {
                    return false;
                }

                $this->store[$key] = $value;

                return true;
            }

            public function increment($key, $value = 1)
            {
                $this->store[$key] = ((int) ($this->store[$key] ?? 0)) + $value;

                return $this->store[$key];
            }

            public function put($key, $value, $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function has($key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function forget($key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function get($key, $default = null): mixed { return $this->store[$key] ?? $default; }
            public function many(array $keys): array { return []; }
            public function pull($key, $default = null): mixed { return null; }
            public function putMany(array $values, $ttl = null): bool { return true; }
            public function decrement($key, $value = 1) { return 0; }
            public function forever($key, $value): bool { return $this->put($key, $value); }
            public function remember($key, $ttl, \Closure $callback): mixed { return $callback(); }
            public function sear($key, \Closure $callback): mixed { return $callback(); }
            public function rememberForever($key, \Closure $callback): mixed { return $callback(); }
            public function getStore() { return null; }
            public function clear(): bool { $this->store = []; return true; }
            public function delete($key): bool { return $this->forget($key); }
            public function deleteMultiple($keys): bool { return true; }
            public function getMultiple($keys, $default = null): iterable { return []; }
            public function set($key, $value, $ttl = null): bool { return $this->put($key, $value); }
            public function setMultiple($values, $ttl = null): bool { return true; }
        };
    }

    private function brokenCache(): Cache
    {
        return new class implements Cache
        {
            public function add($key, $value, $ttl = null): bool { throw new RuntimeException('redis caído'); }
            public function increment($key, $value = 1) { throw new RuntimeException('redis caído'); }
            public function put($key, $value, $ttl = null): bool { throw new RuntimeException('redis caído'); }
            public function has($key): bool { throw new RuntimeException('redis caído'); }
            public function forget($key): bool { throw new RuntimeException('redis caído'); }

            public function get($key, $default = null): mixed { return null; }
            public function many(array $keys): array { return []; }
            public function pull($key, $default = null): mixed { return null; }
            public function putMany(array $values, $ttl = null): bool { return true; }
            public function decrement($key, $value = 1) { return 0; }
            public function forever($key, $value): bool { return true; }
            public function remember($key, $ttl, \Closure $callback): mixed { return $callback(); }
            public function sear($key, \Closure $callback): mixed { return $callback(); }
            public function rememberForever($key, \Closure $callback): mixed { return $callback(); }
            public function getStore() { return null; }
            public function clear(): bool { return true; }
            public function delete($key): bool { return true; }
            public function deleteMultiple($keys): bool { return true; }
            public function getMultiple($keys, $default = null): iterable { return []; }
            public function set($key, $value, $ttl = null): bool { return true; }
            public function setMultiple($values, $ttl = null): bool { return true; }
        };
    }
}
