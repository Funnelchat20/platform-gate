<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Escribe la fila. Una por llamada, y nadie la lee desde el código (D12). Nunca se actualiza;
 * desde 0.8, lo único que la borra es la purga (`platform-gate:purge-usage`).
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
            $this->recordWithoutNewColumns($call, $e);
        }
    }

    /**
     * Un dominio que subió a 0.8 y todavía no corrió la migración nueva no tiene las tres
     * columnas: la fila se escribe como en 0.7, sin id de pedido ni recurso, y el log dice qué
     * falta. Si tampoco así se puede, es otra cosa —la base— y se loguea como siempre.
     */
    private function recordWithoutNewColumns(ApiCall $call, Throwable $first): void
    {
        try {
            $this->connection->table($this->table)
                ->insert(array_diff_key($call->toRow(), array_flip(ApiCall::ADDED_IN_0_8)));
        } catch (Throwable) {
            $this->logger->warning('platform-gate: no se pudo registrar la llamada', [
                'route' => $call->route,
                'method' => $call->method,
                'exception' => $first->getMessage(),
            ]);

            return;
        }

        $this->logger->warning(
            'platform-gate: la llamada se registró sin `request_id` ni recurso. Corré la migración '
            .'de 0.8 (`php artisan migrate`) en la conexión de uso.',
            ['route' => $call->route, 'method' => $call->method],
        );
    }
}
