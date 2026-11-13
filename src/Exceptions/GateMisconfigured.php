<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

/**
 * NO REINTENTABLE: el portero no pudo evaluar por un error de configuración nuestro, y eso no
 * se arregla esperando (0.11.0). Un `permission_prefix` ilegible o ambiguo, la exigencia del
 * borde encendida sin secreto, un modelo de tokens que no selecciona `kind`, `abilities` o
 * `workspace_id`, un `kind` que esta versión no conoce, el portero montado donde no hay ruta.
 *
 * Sigue siendo "no pude evaluar", nunca "evalué y está denegado": no se colapsa en un 403, y
 * no deja pasar (P7). Lo que cambia es lo que se le dice al cliente:
 *
 *   - **500, no 503.** 503 es "no disponible por ahora": invita a reintentar, y los clientes
 *     HTTP lo reintentan solos. Esto no es por ahora.
 *   - **Sin `Retry-After`.** No hay un momento en el que reintentar sirva.
 *   - **`gate_misconfigured`**, un código propio, para que quien integra lo distinga del
 *     `gate_unavailable` que sí se reintenta, y del `internal_error` genérico.
 *
 * Extiende `GateUnavailable` para que un dominio que atrapaba ésa —`PermissionChecker::allows()`
 * y `RoutePermissions::requiredFor()` la tiran— siga atrapando las dos sin cambiar código.
 * Hasta 0.10.0 todo esto salía como 503 `gate_unavailable` con `Retry-After: 5`, y un cliente
 * que seguía la documentación reintentaba cada cinco segundos para siempre (revisión de huecos
 * del 13-11, iniciativa api-abierta).
 */
final class GateMisconfigured extends GateUnavailable
{
    public function status(): int
    {
        return 500;
    }

    public function errorCode(): string
    {
        return 'gate_misconfigured';
    }

    public function retryAfter(): ?int
    {
        return null;
    }

    protected function clientMessage(): string
    {
        return 'No pudimos validar esta llamada por un problema de configuración de nuestro lado. '
            .'Reintentar no lo resuelve: escribile a soporte de Funnelchat con el X-Request-Id de esta respuesta.';
    }
}
