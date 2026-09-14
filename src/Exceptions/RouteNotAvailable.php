<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

/**
 * Terminal: esta ruta no la habilita NINGÚN permiso del piloto.
 *
 * Es la superficie amplificada — una llamada produce N mensajes. No es "te falta un
 * permiso": no hay permiso que dar. El mensaje lo dice así para que nadie pierda una
 * tarde pidiendo un scope que no existe.
 */
final class RouteNotAvailable extends GateException
{
    public function __construct()
    {
        parent::__construct(
            'Esta ruta no está disponible para API keys en el piloto. No es un permiso que falte: '
            .'ninguna key la habilita.'
        );
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'route_not_available';
    }
}
