<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

/**
 * Terminal: esta ruta no la habilita NINGÚN permiso. Sale para lo que está fuera de `allowed`
 * y para la superficie amplificada (`denied`), donde una llamada produce N mensajes.
 *
 * No es "te falta un permiso": no hay permiso que dar. El mensaje lo dice así para que nadie
 * pierda una tarde pidiendo un scope que no existe.
 *
 * No es "como si no existiera": una ruta que no existe da el 404 del dominio, y ésta un 403, así
 * que quien llama sabe que la ruta está. Es a propósito —el portal documenta este 403— y la
 * consecuencia es que una ruta cerrada no es secreta. Hasta 0.10.0 el README y la config
 * prometían lo contrario, y el mensaje decía «en el piloto» con la API ya en producción.
 */
final class RouteNotAvailable extends GateException
{
    public function __construct()
    {
        parent::__construct(
            'Esta ruta no está disponible para API keys. No es un permiso que falte: '
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
