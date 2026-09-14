<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Permissions;

use Funnelchat\PlatformGate\Exceptions\PermissionDenied;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * ¿La key tiene el permiso que la ruta pide?
 *
 * Los permisos del piloto son diez (D10): `{dominio}:read` y `{dominio}:write` para los
 * cuatro dominios, más `{dominio}:send` donde el dominio produce egress a WhatsApp.
 *
 * La escalera es deliberada y va en un solo sentido: `send` implica `write`, y `write`
 * implica `read`. Quien puede mandar un mensaje puede escribir un contacto. Al revés no:
 * `write` NO habilita envío — esa es toda la razón por la que existe el tercer grado.
 *
 * Y si mañana los permisos se parten más fino, acá es donde el permiso viejo se trata
 * como equivalente al conjunto nuevo, para no obligar a nadie a recrear su key (D10).
 * Al revés no se promete por escrito.
 */
final class PermissionChecker
{
    private const IMPLIES = [
        'send' => ['send'],
        'write' => ['write', 'send'],
        'read' => ['read', 'write', 'send'],

        // `operate` es un EJE APARTE, no un peldaño más alto de la misma escalera, y por
        // eso sólo se satisface a sí mismo. Existe en communities, donde el riesgo no es
        // enviar sino mutar la sesión de WhatsApp: crear un grupo, agregar admins, salir,
        // aceptar una invitación. Eso quema el número del cliente sin mandar un mensaje.
        //
        // Que no implique nada, y que nada lo implique, es deliberado: quien puede mandar
        // un mensaje no tiene por qué poder reestructurarle los grupos a la cuenta, y
        // quien puede reestructurarlos no necesariamente puede mandar. Meterlo en la
        // escalera regalaría uno de los dos permisos sin que nadie lo haya decidido.
        'operate' => ['operate'],
    ];

    /**
     * @throws PermissionDenied
     */
    public function assert(HasAbilities $token, string $required): void
    {
        if (! $this->allows($token, $required)) {
            throw new PermissionDenied($required);
        }
    }

    public function allows(HasAbilities $token, string $required): bool
    {
        // Un token con `*` puede todo, y `*` es justamente lo que lleva un token WEB. Una
        // key nunca debería tener ese comodín —accounts las acuña con permisos
        // explícitos— pero si alguna se cuela, con `can('*')` pasaría por encima de los
        // diez permisos y del tercer grado. Defensa en profundidad: acá el comodín no
        // vale, y el permiso se chequea literal.
        [$domain, $grade] = array_pad(explode(':', $required, 2), 2, '');

        $abilities = method_exists($token, 'getAbilities') ? $token->getAbilities() : null;

        foreach (self::IMPLIES[$grade] ?? [$grade] as $satisfying) {
            $needle = "{$domain}:{$satisfying}";

            $granted = is_array($abilities)
                ? in_array($needle, $abilities, true)
                : $token->can($needle);

            if ($granted) {
                return true;
            }
        }

        return false;
    }
}
