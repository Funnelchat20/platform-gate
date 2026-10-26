<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

use Funnelchat\PlatformGate\Permissions\PermissionLabels;

/**
 * Terminal: la key existe, pero no tiene el permiso que esta ruta pide. Reintentar no sirve.
 *
 * El mensaje nombra el permiso como lo nombra el panel, nunca con el literal (0.8.1): quien
 * integra lo arregla en Desarrolladores, y ahí no hay ningún `conversations:send`. Si el panel
 * no lo nombra, el mensaje es genérico. El literal queda en `requiredPermission`, para el código
 * y los logs del dominio; no sale en la respuesta.
 */
final class PermissionDenied extends GateException
{
    public const MESSAGE_GENERIC = 'A esta API key le falta un permiso que esta ruta pide. '
        .'Revisá sus permisos en Desarrolladores, en el panel.';

    public function __construct(public readonly string $requiredPermission)
    {
        $label = PermissionLabels::for($requiredPermission);

        parent::__construct($label === null
            ? self::MESSAGE_GENERIC
            : "A esta API key le falta el permiso «{$label}». Agregalo desde Desarrolladores, en el panel.");
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
