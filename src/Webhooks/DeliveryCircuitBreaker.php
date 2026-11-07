<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Webhooks;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Pausa automática de una suscripción que falla sistemáticamente (0.10).
 *
 * Sin esto, un endpoint muerto se reintenta para siempre: cada evento que le toca ocupa
 * sus cuatro intentos (`RetryPolicy::MAX_ATTEMPTS`) contra una URL que no va a responder,
 * y eso sostiene varios slots de la cola compartida por nada. El relevamiento que motiva
 * esto es antes de este archivo, no una hipótesis.
 *
 * Cómo funciona: cada fallo de entrega cuenta en una ventana reciente (`window_seconds`).
 * Al llegar a `failure_threshold` fallos **consecutivos** —un éxito la vacía—, la
 * suscripción queda pausada por `pause_seconds`: mientras dure, `isPaused()` dice que no
 * se reintente más. No hace falta "reactivarla" a mano: la pausa es una clave de cache con
 * TTL, así que se abre sola cuando el TTL vence (medio abierto: el próximo intento decide).
 *
 * **Apagado por default** (`webhook_circuit_breaker.enabled`, 0.10): instalar esta versión
 * no cambia nada hasta que un dominio la prenda a propósito.
 *
 * **Falla abierto.** Esto no es un gate de permisos como `DailyCallCap` — no decide si una
 * llamada entra, decide si vale la pena seguir intentando una entrega. Si el store no
 * responde, la respuesta conservadora es "seguí intentando" (se pierde la protección de la
 * cola, no un evento), al revés de `DailyCallCap`, que corta porque ahí no decidir es dejar
 * pasar lo que se quería frenar (P7). Acá no decidir y frenar tiraría avisos reales por un
 * problema de infraestructura ajeno a la suscripción.
 *
 * No vive en `Subscription`: ese es un objeto de valor sin dependencias (`url`, `secret`,
 * `types`) y ensuciarlo con una `Cache` rompería a quien ya lo construye con
 * `new Subscription(...)`. El id de la suscripción es opaco para esta clase: lo decide
 * quien la llama (la PK de su propia tabla de suscripciones), porque esa tabla no es de
 * este paquete (ver «Dónde se guardan las suscripciones»).
 */
final readonly class DeliveryCircuitBreaker
{
    public function __construct(
        private Cache $cache,
        private bool $enabled,
        private int $failureThreshold,
        private int $windowSeconds,
        private int $pauseSeconds,
    ) {
    }

    /** `true` si a esta suscripción no hay que reintentarle ahora mismo. */
    public function isPaused(string $subscriptionId): bool
    {
        if (! $this->enabled) {
            return false;
        }

        try {
            return $this->cache->has($this->pausedKey($subscriptionId));
        } catch (Throwable) {
            return false; // falla abierto: no pausamos por un store caído
        }
    }

    /**
     * Un intento de entrega falló (5xx, 429 o error de red: lo mismo que
     * `RetryPolicy::shouldRetry()` considera reintentable). Al llegar al umbral, pausa.
     */
    public function recordFailure(string $subscriptionId): void
    {
        if (! $this->enabled || $this->failureThreshold <= 0) {
            return;
        }

        try {
            $key = $this->failuresKey($subscriptionId);

            $count = $this->cache->add($key, 1, $this->windowSeconds)
                ? 1
                : $this->cache->increment($key);

            if (is_int($count) && $count >= $this->failureThreshold) {
                $this->cache->put($this->pausedKey($subscriptionId), true, $this->pauseSeconds);
            }
        } catch (Throwable) {
            // Medición, no control: un store caído no debe frenar entregas (falla abierto).
        }
    }

    /** Un intento le llegó bien: cierra el circuito y borra lo acumulado. */
    public function recordSuccess(string $subscriptionId): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $this->cache->forget($this->failuresKey($subscriptionId));
            $this->cache->forget($this->pausedKey($subscriptionId));
        } catch (Throwable) {
            // Idem: no hay nada que "cortar" por no poder limpiar un contador.
        }
    }

    private function failuresKey(string $subscriptionId): string
    {
        return "platform-gate:webhooks:cb-fails:{$subscriptionId}";
    }

    private function pausedKey(string $subscriptionId): string
    {
        return "platform-gate:webhooks:cb-paused:{$subscriptionId}";
    }
}
