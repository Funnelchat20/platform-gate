<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Escribe la fila. Una por llamada, append-only, y nadie la lee desde el código (D12).
 *
 * Por qué no agrega nada: todavía no está decidido si se cobra por cuota o por llamada, y
 * cualquier agregación hornea una decisión de pricing que no se tomó. Agregado no se
 * desagrega. A escala piloto son miles de filas por día.
 *
 * Política de fallo, distinta a propósito de la del resto del portero: si no se puede
 * escribir la fila, la llamada NO se rompe. Cuando esto corre, la llamada ya se atendió
 * —el `terminate` del middleware— así que romperla no la desharía: sólo le mentiría al
 * cliente sobre algo que sí pasó. Se loguea y se sigue. Lo que se pierde es medición, y
 * eso se ve en el log.
 */
final readonly class ApiCallRecorder
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
        private LoggerInterface $logger,
    ) {
    }

    public function record(ApiCall $call): void
    {
        try {
            $this->connection->table($this->table)->insert($call->toRow());
        } catch (Throwable $e) {
            $this->logger->warning('platform-gate: no se pudo registrar la llamada', [
                'route' => $call->route,
                'method' => $call->method,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
