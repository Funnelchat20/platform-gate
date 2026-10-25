<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Permissions;

/**
 * Cómo se llama cada permiso en el panel: «área → casilla».
 *
 * Quien crea la key la crea en Desarrolladores, en el panel, y ahí no ve `conversations:send`:
 * ve el área «Contactos y conversaciones» y la casilla «Enviar». Un mensaje que nombra el
 * literal le pide que traduzca algo que nunca vio, y el pedido del 25-10 (iniciativa
 * api-abierta) fue que ese literal no aparezca en ningún lado, tampoco en la API. Por eso todo
 * texto que le habla a quien integra nombra el permiso con esto, y nunca con el literal.
 *
 * Vive acá, y una sola vez, para que los dominios la usen si tienen sus propios mensajes en
 * vez de copiarla. Los nombres los decide el panel: si el panel cambia una casilla, cambia
 * esta tabla, en una versión nueva.
 *
 * Un permiso que no está en la tabla no tiene nombre de panel: `for()` devuelve `null` y quien
 * arma el mensaje dice algo genérico. Nunca se inventa un nombre ni se cae al literal.
 */
final class PermissionLabels
{
    /** @var array<string, array{0: string, 1: string}> literal => [área, casilla] */
    private const LABELS = [
        'conversations:read' => ['Contactos y conversaciones', 'Ver'],
        'conversations:write' => ['Contactos y conversaciones', 'Editar'],
        'conversations:send' => ['Contactos y conversaciones', 'Enviar'],

        'communities:read' => ['Grupos y campañas', 'Ver'],
        'communities:write' => ['Grupos y campañas', 'Editar'],
        'communities:send' => ['Grupos y campañas', 'Enviar'],
        'communities:operate' => ['Grupos y campañas', 'Administrar grupos'],

        'accounts:read' => ['Números de WhatsApp', 'Ver'],
        'accounts:write' => ['Números de WhatsApp', 'Editar'],
        'accounts:operate' => ['Números de WhatsApp', 'Crear, conectar y borrar'],

        'webhooks:read' => ['Avisos (webhooks)', 'Ver'],
        'webhooks:write' => ['Avisos (webhooks)', 'Administrar'],
    ];

    /**
     * El nombre del permiso en el panel, `Área → Casilla` —`for('accounts:operate')` es
     * `Números de WhatsApp → Crear, conectar y borrar`—, o `null` si el panel no lo nombra.
     *
     * El literal se busca tal cual: `Accounts:Operate` o ` accounts:operate` no son permisos.
     */
    public static function for(string $permission): ?string
    {
        $label = self::LABELS[$permission] ?? null;

        return $label === null ? null : "{$label[0]} → {$label[1]}";
    }
}
