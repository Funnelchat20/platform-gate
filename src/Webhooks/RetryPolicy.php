<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Webhooks;

/**
 * Tres reintentos con espera creciente, y después se abandona.
 *
 * No cero: si los eventos son la forma en que el usuario se entera de todo, perder uno en
 * silencio es peor que no tenerlos. Tampoco la escalera de seis pasos hasta 24 horas del
 * plan grande: eso necesita un panel de entregas y desactivación automática de endpoints,
 * que están fuera del piloto. Tres intentos cubren el caso real —el n8n del cliente
 * estaba reiniciando— sin construir la maquinaria completa.
 *
 * Se reintenta sólo lo que puede mejorar solo: 5xx, 429 y errores de red. Un 4xx que no
 * sea 429 es el cliente diciendo "esto está mal": reintentarlo es ruido tres veces.
 */
final readonly class RetryPolicy
{
    /** @var list<int> espera en segundos antes del intento 2, 3 y 4 */
    private const BACKOFF = [60, 300, 1800];

    public const MAX_ATTEMPTS = 4;

    public function shouldRetry(int $attempt, ?int $statusCode): bool
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            return false;
        }

        if ($statusCode === null) {
            return true; // timeout, DNS, conexión cortada: puede mejorar solo
        }

        return $statusCode >= 500 || $statusCode === 429;
    }

    /** Segundos a esperar antes del intento siguiente. `null` si ya no hay siguiente. */
    public function delayAfter(int $attempt): ?int
    {
        return self::BACKOFF[$attempt - 1] ?? null;
    }
}
