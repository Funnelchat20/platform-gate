<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

/** Terminal: la key existe, pero no tiene el permiso que esta ruta pide. Reintentar no sirve. */
final class PermissionDenied extends GateException
{
    public function __construct(public readonly string $requiredPermission)
    {
        parent::__construct("Esta API key no tiene el permiso `{$requiredPermission}`.");
    }

    public function status(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'permission_denied';
    }
}
