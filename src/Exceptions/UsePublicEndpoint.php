<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

/**
 * Terminal: una API key llegó directo a un host interno y este dominio exige el borde (Q12,
 * Q14). La key puede estar bien; lo que está mal es la dirección. Reintentar en el mismo
 * host no sirve: hay que cambiar la URL, y el mensaje dice a cuál.
 */
final class UsePublicEndpoint extends GateException
{
    public function __construct(public readonly string $publicUrl)
    {
        parent::__construct("Usá {$publicUrl}");
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'use_public_endpoint';
    }
}
