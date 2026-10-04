<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Permissions;

use Funnelchat\PlatformGate\Exceptions\GateUnavailable;
use Funnelchat\PlatformGate\Exceptions\PermissionDenied;
use Laravel\Sanctum\Contracts\HasAbilities;
use Throwable;
use Traversable;

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
     * @throws GateUnavailable si no se puede leer la lista de permisos del token.
     */
    public function assert(HasAbilities $token, string $required): void
    {
        if (! $this->allows($token, $required)) {
            throw new PermissionDenied($required);
        }
    }

    /**
     * @throws GateUnavailable si no se puede leer la lista de permisos del token.
     */
    public function allows(HasAbilities $token, string $required): bool
    {
        // El permiso se busca LITERAL en la lista de la key. Nunca se le pregunta a
        // `$token->can()`: ésa es la interpretación de Sanctum, para la que `*` es un
        // comodín —es lo que lleva un token WEB—. Una key nunca debería tener ese
        // comodín —accounts las acuña con permisos explícitos— y si alguna lo trae, acá
        // es un string más: no habilita ninguno de los diez permisos ni el tercer grado.
        $abilities = $this->abilitiesOf($token);

        [$domain, $grade] = array_pad(explode(':', $required, 2), 2, '');

        foreach (self::IMPLIES[$grade] ?? [$grade] as $satisfying) {
            if (in_array("{$domain}:{$satisfying}", $abilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La lista de permisos de la key, tal como se emitió y sin interpretar.
     *
     * Sale de `getAbilities()` si el modelo lo tiene y, si no, del atributo `abilities`,
     * que es donde la guarda Sanctum: su `PersonalAccessToken` no expone ningún método
     * para leerla. Si el atributo llega como el texto JSON de la columna —un modelo del
     * dominio que redefine `$casts` pierde el cast— se decodifica.
     *
     * Si no hay lista legible —el dominio no selecciona la columna, el valor no es una
     * lista, leer el atributo tira— no hay con qué evaluar, y se corta (P7). Ni "evalué
     * y está denegado" (sería un 403 terminal que miente) ni dejar pasar.
     *
     * @return list<string>
     *
     * @throws GateUnavailable
     */
    public function abilitiesOf(object $token): array
    {
        try {
            $raw = method_exists($token, 'getAbilities')
                ? $token->getAbilities()
                : ($token->abilities ?? null);

            if (is_string($raw)) {
                $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }

            if ($raw instanceof Traversable) {
                $raw = iterator_to_array($raw, false);
            }
        } catch (Throwable $e) {
            throw new GateUnavailable('unreadable_abilities', $e);
        }

        if (! is_array($raw)) {
            throw new GateUnavailable('unreadable_abilities');
        }

        return array_values(array_filter($raw, 'is_string'));
    }
}
