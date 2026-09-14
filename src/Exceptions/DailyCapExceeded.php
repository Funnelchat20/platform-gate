<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

/** Terminal por hoy, reintentable mañana: la key agotó su tope diario de LLAMADAS. */
final class DailyCapExceeded extends GateException
{
    public function __construct(
        public readonly int $cap,
        private readonly int $secondsUntilReset,
    ) {
        parent::__construct("Esta API key superó su tope de {$cap} llamadas diarias.");
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
