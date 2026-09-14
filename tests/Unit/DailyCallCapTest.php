<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Exceptions\DailyCapExceeded;
use Funnelchat\PlatformGate\Exceptions\GateUnavailable;
use Funnelchat\PlatformGate\Limits\DailyCallCap;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DailyCallCapTest extends TestCase
{
    public function test_corta_al_pasarse_del_tope_y_dice_cuanto_esperar(): void
    {
        $cap = new DailyCallCap($this->cacheAt(4), default: 3);

        $this->expectException(DailyCapExceeded::class);

        try {
            $cap->consume('key_abc');
        } catch (DailyCapExceeded $e) {
            $this->assertSame(429, $e->status());
            $this->assertGreaterThan(0, $e->retryAfter());
            throw $e;
        }
    }

    public function test_justo_en_el_tope_todavia_pasa(): void
    {
        (new DailyCallCap($this->cacheAt(3), default: 3))->consume('key_abc');

        $this->addToAssertionCount(1);
    }

    public function test_si_el_store_se_cae_corta_y_es_reintentable(): void
    {
        // El pedido P7 en una línea: no pudimos evaluar ≠ evaluamos y está denegado.
        // Dejar pasar acá es el agujero exacto que el piloto cree haber cerrado.
        $cache = new class implements Cache
        {
            public function add($key, $value, $ttl = null): bool
            {
                throw new RuntimeException('redis caído');
            }

            public function increment($key, $value = 1)
            {
                throw new RuntimeException('redis caído');
            }

            public function has($key): bool { return false; }
            public function get($key, $default = null): mixed { return null; }
            public function many(array $keys): array { return []; }
            public function pull($key, $default = null): mixed { return null; }
            public function put($key, $value, $ttl = null): bool { return true; }
            public function putMany(array $values, $ttl = null): bool { return true; }
            public function decrement($key, $value = 1) { return 0; }
            public function forever($key, $value): bool { return true; }
            public function remember($key, $ttl, \Closure $callback): mixed { return $callback(); }
            public function sear($key, \Closure $callback): mixed { return $callback(); }
            public function rememberForever($key, \Closure $callback): mixed { return $callback(); }
            public function forget($key): bool { return true; }
            public function getStore() { return null; }
            public function clear(): bool { return true; }
            public function delete($key): bool { return true; }
            public function deleteMultiple($keys): bool { return true; }
            public function getMultiple($keys, $default = null): iterable { return []; }
            public function set($key, $value, $ttl = null): bool { return true; }
            public function setMultiple($values, $ttl = null): bool { return true; }
        };

        $this->expectException(GateUnavailable::class);

        try {
            (new DailyCallCap($cache, default: 10))->consume('key_abc');
        } catch (GateUnavailable $e) {
            $this->assertSame(503, $e->status());
            $this->assertSame('gate_unavailable', $e->errorCode());
            throw $e;
        }
    }

    public function test_tope_cero_es_sin_tope_y_ni_toca_el_store(): void
    {
        $cache = new class implements Cache
        {
            public function add($key, $value, $ttl = null): bool
            {
                throw new RuntimeException('no se debería tocar el store');
            }

            public function increment($key, $value = 1)
            {
                throw new RuntimeException('no se debería tocar el store');
            }

            public function has($key): bool { return false; }
            public function get($key, $default = null): mixed { return null; }
            public function many(array $keys): array { return []; }
            public function pull($key, $default = null): mixed { return null; }
            public function put($key, $value, $ttl = null): bool { return true; }
            public function putMany(array $values, $ttl = null): bool { return true; }
            public function decrement($key, $value = 1) { return 0; }
            public function forever($key, $value): bool { return true; }
            public function remember($key, $ttl, \Closure $callback): mixed { return $callback(); }
            public function sear($key, \Closure $callback): mixed { return $callback(); }
            public function rememberForever($key, \Closure $callback): mixed { return $callback(); }
            public function forget($key): bool { return true; }
            public function getStore() { return null; }
            public function clear(): bool { return true; }
            public function delete($key): bool { return true; }
            public function deleteMultiple($keys): bool { return true; }
            public function getMultiple($keys, $default = null): iterable { return []; }
            public function set($key, $value, $ttl = null): bool { return true; }
            public function setMultiple($values, $ttl = null): bool { return true; }
        };

        (new DailyCallCap($cache, default: 0))->consume('key_abc');

        $this->addToAssertionCount(1);
    }

    private function cacheAt(int $used): Cache
    {
        return new class($used) implements Cache
        {
            public function __construct(private int $used)
            {
            }

            public function add($key, $value, $ttl = null): bool
            {
                return false; // la clave ya existe: hoy ya hubo llamadas
            }

            public function increment($key, $value = 1)
            {
                return $this->used;
            }

            public function has($key): bool { return true; }
            public function get($key, $default = null): mixed { return $this->used; }
            public function many(array $keys): array { return []; }
            public function pull($key, $default = null): mixed { return null; }
            public function put($key, $value, $ttl = null): bool { return true; }
            public function putMany(array $values, $ttl = null): bool { return true; }
            public function decrement($key, $value = 1) { return 0; }
            public function forever($key, $value): bool { return true; }
            public function remember($key, $ttl, \Closure $callback): mixed { return $callback(); }
            public function sear($key, \Closure $callback): mixed { return $callback(); }
            public function rememberForever($key, \Closure $callback): mixed { return $callback(); }
            public function forget($key): bool { return true; }
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
