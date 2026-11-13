<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

/**
 * Terminal hasta las 00:00 UTC: la key agotó su tope diario de LLAMADAS en este dominio.
 *
 * El tope es por key **y por dominio** (0.10): cada dominio cuenta en su propio store, así que la
 * misma key puede estar cortada en uno y seguir andando en otro. El mensaje lo dice, y dice cuándo
 * se reinicia, porque «por hoy» no dice en qué zona horaria.
 */
final class DailyCapExceeded extends GateException
{
    public function __construct(
        public readonly int $cap,
        private readonly int $secondsUntilReset,
    ) {
        parent::__construct(
            "Esta API key superó su tope de {$cap} llamadas diarias. "
            .'El tope es por clave y por dominio, y se reinicia a las 00:00 UTC.'
        );
    }

    public function status(): int
    {
        return 429;
    }

    public function errorCode(): string
    {
        return 'daily_cap_exceeded';
    }

    public function retryAfter(): ?int
    {
        return $this->secondsUntilReset;
    }
}
