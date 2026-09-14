<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Webhooks;

/**
 * Cómo se firma un evento, y cómo lo verifica el que lo recibe.
 *
 *   X-Funnelchat-Signature: t=1789131830,v1=3f8a…
 *
 * Se firma `t + "." + cuerpo_crudo`, no sólo el cuerpo: sin el timestamp adentro, una
 * entrega vieja capturada se puede reenviar para siempre y la firma sigue siendo válida.
 * Con él, el que recibe rechaza lo que llegó demasiado tarde.
 *
 * `v1` es el esquema de firma, no la versión del evento. Si mañana hay que cambiar el
 * algoritmo, entra `v2` en el mismo header y los dos conviven mientras los clientes
 * migran — que es la única forma de rotar esto sin romperle el workflow a alguien.
 *
 * La comparación es en tiempo constante: `hash_equals`, nunca `===`.
 */
final readonly class Signature
{
    public const HEADER = 'X-Funnelchat-Signature';

    public const EVENT_HEADER = 'X-Funnelchat-Event';

    public const DELIVERY_HEADER = 'X-Funnelchat-Delivery';

    /** Tolerancia por default, en segundos. Cubre un reloj corrido y una cola lenta. */
    public const DEFAULT_TOLERANCE = 300;

    public function __construct(private string $secret)
    {
    }

    public function for(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);
    }

    /**
     * La verificación que hace el cliente. Está acá para que el snippet de las docs sea
     * este mismo código y no una traducción a mano que se desactualiza.
     */
    public function verify(
        string $payload,
        string $header,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        $parts = [];

        foreach (explode(',', $header) as $piece) {
            $kv = explode('=', trim($piece), 2);

            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret),
            $parts['v1']
        );
    }
}
