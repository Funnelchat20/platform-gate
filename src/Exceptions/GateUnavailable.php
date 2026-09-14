<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

use Throwable;

/**
 * REINTENTABLE: el portero no pudo evaluar. El store se cayó, la key no se pudo resolver
 * por infraestructura.
 *
 * Cortamos, no dejamos pasar (pedido P7). Dejar pasar cuando no se puede evaluar es
 * exactamente el agujero que el piloto cree haber cerrado: el cliente automatizado pasa
 * el límite justo cuando el control no responde.
 *
 * El motivo real NUNCA viaja al cliente — es infraestructura nuestra. Va al log.
 */
final class GateUnavailable extends GateException
{
    public function __construct(public readonly string $stage, ?Throwable $previous = null)
    {
        parent::__construct('No pudimos validar esta llamada. Reintentá en unos segundos.', 0, $previous);
    }

    public function status(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'gate_unavailable';
    }

    public function retryAfter(): ?int
    {
        return 5;
    }
}
