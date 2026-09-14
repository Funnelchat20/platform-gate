<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

/**
 * Una llamada, tal como se guarda. Seis campos y ni uno más (D12).
 *
 * Nada de PII y nada de contenido: ni teléfonos, ni cuerpos de request o response, ni
 * texto escrito por personas. La ruta es el URI DEFINIDO (`contacts/{contact}/messages`),
 * no el path real, justamente para que ningún id de persona entre por ahí.
 */
final readonly class ApiCall
{
    public function __construct(
        public string $keyId,
        public string $route,
        public string $method,
        public int $statusCode,
        public string $occurredAt,
        public int $durationMs,
    ) {
    }

    /** @return array<string,scalar> */
    public function toRow(): array
    {
        return [
            'key_id' => $this->keyId,
            'route' => $this->route,
            'method' => $this->method,
            'status_code' => $this->statusCode,
            'occurred_at' => $this->occurredAt,
            'duration_ms' => $this->durationMs,
        ];
    }
}
