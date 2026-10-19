<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

/**
 * Una llamada, tal como se guarda. Nueve campos y ni uno más (D12).
 *
 * D12 decía seis. El 17-10-2026 se sumaron tres (contrato 1019-registro-llamadas): el registro
 * no servía para rastrear un problema, porque no ligaba la llamada con lo que creó ni tenía
 * un id que el integrador pudiera citar.
 *
 *   - `requestId`: el id del pedido, el mismo que se devuelve en `X-Request-Id`. Al azar.
 *   - `resourceType` / `resourceId`: lo que el controlador declaró con `CreatedResource`.
 *     Un id INTERNO. Sin declaración, `null`.
 *
 * Sigue sin PII y sin contenido: ni teléfonos, ni cuerpos de request o response, ni texto
 * escrito por personas. La ruta es el URI DEFINIDO (`contacts/{contact}/messages`), no el path
 * real, justamente para que ningún id de persona entre por ahí.
 */
final readonly class ApiCall
{
    /** Las columnas de 0.8: una tabla que todavía no corrió su migración no las tiene. */
    public const ADDED_IN_0_8 = ['request_id', 'resource_type', 'resource_id'];

    public function __construct(
        public string $keyId,
        public string $route,
        public string $method,
        public int $statusCode,
        public string $occurredAt,
        public int $durationMs,
        public ?string $requestId = null,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
    ) {
    }

    /** @return array<string,scalar|null> */
    public function toRow(): array
    {
        return [
            'key_id' => $this->keyId,
            'route' => $this->route,
            'method' => $this->method,
            'status_code' => $this->statusCode,
            'occurred_at' => $this->occurredAt,
            'duration_ms' => $this->durationMs,
            'request_id' => $this->requestId,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }
}
