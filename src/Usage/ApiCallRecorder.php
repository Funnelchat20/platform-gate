<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use PDOException;
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
 *
 * Singleton: que a la tabla le falta la migración de 0.8 se descubre una vez por proceso, como
 * en `KindColumnProbe`, y desde ahí se escribe directo la fila de 0.7. Bajo PHP-FPM un proceso
 * atiende un pedido, así que ahí es una vez por llamada mientras dure la ventana entre el
 * deploy y el `migrate`.
 *
 * El modo 0.7 se apaga solo: mientras dura, se vuelve a probar con las columnas como mucho una
 * vez por minuto. Después del `migrate`, un proceso de larga vida vuelve a escribir las filas
 * completas sin que haya que reiniciarlo.
 */
final class ApiCallRecorder
{
    public const RECHECK_SECONDS = 60;

    /** Faltan las columnas de 0.8: hasta este momento (unix) no se vuelve a probar con ellas. */
    private ?int $withoutNewColumnsUntil = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(ApiCall $call): void
    {
        $now = Carbon::now()->getTimestamp();

        if ($this->withoutNewColumnsUntil !== null && $now < $this->withoutNewColumnsUntil) {
            $this->insertWithoutNewColumns($call);

            return;
        }

        try {
            $this->connection->table($this->table)->insert($call->toRow());
        } catch (Throwable $e) {
            if (self::isMissingColumn($e)) {
                // Una vez por proceso, y antes de reintentar: lo que falta es la migración, salga
                // o no la fila. Las comprobaciones de cada minuto que siguen fallando no lo repiten.
                if ($this->withoutNewColumnsUntil === null) {
                    $this->logger->warning(
                        'platform-gate: a la tabla de uso le faltan las columnas de 0.8: las llamadas se '
                        .'registran sin `request_id` ni recurso. Corré la migración de 0.8 '
                        .'(`php artisan migrate`) en la conexión de uso.',
                        ['table' => $this->table],
                    );
                }

                $this->withoutNewColumnsUntil = $now + self::RECHECK_SECONDS;
                $this->insertWithoutNewColumns($call);

                return;
            }

            $this->couldNotRecord($call, $e);

            return;
        }

        if ($this->withoutNewColumnsUntil !== null) {
            $this->withoutNewColumnsUntil = null;

            $this->logger->info(
                'platform-gate: la tabla de uso ya tiene las columnas de 0.8: las llamadas vuelven a '
                .'registrarse con `request_id` y recurso.',
                ['table' => $this->table],
            );
        }
    }

    /**
     * Un dominio que subió a 0.8 y todavía no corrió la migración nueva no tiene las tres
     * columnas: la fila se escribe como en 0.7, sin id de pedido ni recurso.
     */
    private function insertWithoutNewColumns(ApiCall $call): void
    {
        try {
            $this->connection->table($this->table)
                ->insert(array_diff_key($call->toRow(), array_flip(ApiCall::ADDED_IN_0_8)));
        } catch (Throwable $e) {
            $this->couldNotRecord($call, $e);
        }
    }

    /** El camino de 0.7: se loguea y se sigue. */
    private function couldNotRecord(ApiCall $call, Throwable $e): void
    {
        $this->logger->warning('platform-gate: no se pudo registrar la llamada', [
            'route' => $call->route,
            'method' => $call->method,
            'exception' => $e->getMessage(),
        ]);
    }

    /**
     * «Columna desconocida» y nada más: cualquier otra falla —la base caída, un deadlock, un
     * valor que no entra— no se arregla escribiendo menos columnas, y reintentarla diría
     * "corré la migración" sobre un problema que es otro.
     *
     *   MySQL / MariaDB ... SQLSTATE 42S22, código 1054 (`Unknown column`)
     *   PostgreSQL ........ SQLSTATE 42703 (`undefined_column`)
     *   SQLite ............ HY000 genérico: sólo el texto lo dice (`has no column named`)
     */
    private static function isMissingColumn(Throwable $e): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if (! $e instanceof PDOException) {
                continue;
            }

            $info = is_array($e->errorInfo) ? $e->errorInfo : [];
            $state = (string) ($info[0] ?? $e->getCode());
            $code = $info[1] ?? null;

            if (in_array($state, ['42S22', '42703'], true) || (int) $code === 1054) {
                return true;
            }

            if ($state === 'HY000' && str_contains((string) ($info[2] ?? $e->getMessage()), 'has no column named')) {
                return true;
            }
        }

        return false;
    }
}
