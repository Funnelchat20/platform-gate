<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Limits;

use Funnelchat\PlatformGate\Exceptions\DailyCapExceeded;
use Funnelchat\PlatformGate\Exceptions\GateUnavailable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Tope duro diario POR KEY (pedido P4).
 *
 * ⚠️ ADVERTENCIA, y está acá porque es donde alguien la va a leer:
 *
 * Esto cuenta LLAMADAS, no mensajes. **No es un freno de envío.** En conversations una
 * sola llamada bien formada produce N mensajes: `POST broadcasts/{id}/execute` envía a
 * toda la audiencia, `start-flow` arranca un flujo que envía indefinidamente, y
 * `add-tags` con un solo contacto dispara un flujo por trigger de etiqueta. El incidente
 * que motiva el piloto —cuatro mil mensajes, calidad en rojo, número suspendido— lo
 * produce una sola llamada. Este tope no lo ve.
 *
 * Es un control de costo y de abuso. Contarlo como "el freno de envío" es lo que haría
 * que el freno real no se construya nunca. El freno real vive en la costura de salida de
 * cada dominio (D21, D22).
 *
 * Levantable a pedido sin deploy: el override por key sale de config.
 */
final readonly class DailyCallCap
{
    public function __construct(
        private Cache $cache,
        private int $default,
        /** @var array<string,int> keyId => tope propio. Config, sin deploy. */
        private array $overrides = [],
    ) {
    }

    /**
     * Cuenta esta llamada y corta si se pasó.
     *
     * @throws DailyCapExceeded  terminal por hoy
     * @throws GateUnavailable   no pudimos evaluar: cortamos, no dejamos pasar (P7)
     */
    public function consume(string $keyId): void
    {
        $cap = $this->overrides[$keyId] ?? $this->default;

        if ($cap <= 0) {
            return; // 0 o negativo = sin tope. Explícito, no accidental.
        }

        $day = gmdate('Y-m-d');
        $bucket = "platform-gate:calls:{$keyId}:{$day}";

        try {
            $used = $this->cache->add($bucket, 1, $this->secondsUntilUtcMidnight() + 60)
                ? 1
                : $this->cache->increment($bucket);
        } catch (Throwable $e) {
            throw new GateUnavailable('daily_cap', $e);
        }

        // `increment` devuelve false en algunos stores cuando la clave venció entre el
        // `add` y el `increment`. No sabemos cuánto lleva usado: no podemos evaluar.
        if ($used === false || $used === null) {
            throw new GateUnavailable('daily_cap');
        }

        if ((int) $used > $cap) {
            throw new DailyCapExceeded($cap, $this->secondsUntilUtcMidnight());
        }
    }

    private function secondsUntilUtcMidnight(): int
    {
        return (int) (strtotime('tomorrow midnight UTC') - time());
    }
}
