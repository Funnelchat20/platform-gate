<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Caller;

/**
 * Dónde vive el llamador durante el request. Singleton del contenedor.
 *
 * Arranca en `Unverified` y sólo el middleware del portero lo setea. Un dominio que
 * pregunta antes de que el portero corra —o sobre una ruta que el portero no cubre—
 * obtiene `Unverified`, que es la verdad, y no `Web`, que sería una mentira cómoda.
 */
final class CallerContext
{
    private Caller $caller;

    public function __construct()
    {
        $this->caller = Caller::unverified();
    }

    public function set(Caller $caller): void
    {
        $this->caller = $caller;
    }

    public function get(): Caller
    {
        return $this->caller;
    }

    public function kind(): CallerKind
    {
        return $this->caller->kind;
    }

    /** Atajo para el caso más común: ¿tengo que ser fail-closed en este envío? */
    public function isMachine(): bool
    {
        return $this->caller->kind->isMachine();
    }
}
