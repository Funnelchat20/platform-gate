<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

use Throwable;

/**
 * REINTENTABLE: el portero no pudo evaluar por algo que se arregla solo. El store del contador
 * se cayó, el esquema de la base no se pudo consultar.
 *
 * Cortamos, no dejamos pasar (pedido P7). Dejar pasar cuando no se puede evaluar es
 * exactamente el agujero que el piloto cree haber cerrado: el cliente automatizado pasa
 * el límite justo cuando el control no responde.
 *
 * Lo que tampoco se puede evaluar pero NO se arregla solo —la config del dominio, el modelo de
 * tokens, el montaje— es `GateMisconfigured` (0.11.0): una subclase, para que quien atrapa ésta
 * siga atrapando las dos, pero con otra respuesta. Hasta 0.10.0 todo salía acá, con
 * `Retry-After: 5`, y un cliente que seguía la doc reintentaba cada cinco segundos para siempre.
 *
 * El motivo real NUNCA viaja al cliente — es infraestructura nuestra. Va al log.
 */
class GateUnavailable extends GateException
{
    public function __construct(public readonly string $stage, ?Throwable $previous = null)
    {
        parent::__construct($this->clientMessage(), 0, $previous);
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

    protected function clientMessage(): string
    {
        return 'No pudimos validar esta llamada. Reintentá en unos segundos.';
    }
}
