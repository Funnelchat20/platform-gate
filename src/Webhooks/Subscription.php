<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Webhooks;

/**
 * A qué se suscribió un cliente: una URL, un secreto, y **qué tipos quiere**.
 *
 * La suscripción por tipo no es una comodidad: una cuenta con volumen ahoga su propio n8n
 * si recibe todo. `message.status_changed` sale a razón de dos o tres por cada mensaje
 * enviado —sent, delivered, read— y es, con diferencia, el evento más caro del catálogo.
 * Que el cliente lo pida explícitamente es lo que evita que lo descubra por un timeout.
 *
 * Se acepta `recurso.*` para no obligar a enumerar; `*` a secas NO existe, a propósito:
 * suscribirse a todo tiene que ser una decisión, no el default cómodo.
 */
final readonly class Subscription
{
    /** @param list<string> $types patrones: `message.received` o `message.*` */
    public function __construct(
        public string $url,
        public string $secret,
        public array $types,
    ) {
    }

    public function wants(EventName $event): bool
    {
        foreach ($this->types as $pattern) {
            if ($pattern === $event->value) {
                return true;
            }

            if (str_ends_with($pattern, '.*') && $event->resource() === substr($pattern, 0, -2)) {
                return true;
            }
        }

        return false;
    }

    public function signature(): Signature
    {
        return new Signature($this->secret);
    }
}
