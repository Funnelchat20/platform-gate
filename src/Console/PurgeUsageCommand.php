<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Borra del registro de uso las filas anteriores a N días (0.8, contrato 1019-registro-llamadas).
 *
 * N es `platform-gate.usage_retention_days`, 90 por defecto: lo mismo que ya hacía accounts con
 * su `api-usage:purge`, ahora para todos. El dominio lo programa todos los días:
 *
 *     Schedule::command('platform-gate:purge-usage')->daily();
 *
 * Falla cerrado: con N menor que 1, o algo que no es un entero, no borra nada y termina con
 * error. Un 0 que se coló en el env no puede vaciar la tabla.
 *
 * En lotes, para no tener un DELETE gigante bloqueando la tabla en la que el portero escribe
 * en cada llamada. Borra lo ESTRICTAMENTE anterior al corte, y nada más.
 *
 * Al log va la cantidad y el corte. Nada de las filas.
 */
final class PurgeUsageCommand extends Command
{
    public const BATCH = 1000;

    protected $signature = 'platform-gate:purge-usage';

    protected $description = 'Borra del registro de uso de la API las filas más viejas que la retención configurada.';

    public function handle(DatabaseManager $db, LoggerInterface $logger): int
    {
        $days = self::retentionDays(config('platform-gate.usage_retention_days', 90));

        if ($days === null) {
            $message = 'platform-gate: `platform-gate.usage_retention_days` tiene que ser un entero de 1 o más '
                .'(`PLATFORM_GATE_USAGE_RETENTION_DAYS`). No se borró nada.';

            $logger->error($message);
            $this->error($message);

            return self::FAILURE;
        }

        // `occurred_at` se guarda en UTC (`gmdate`), así que el corte también.
        $cutoff = Carbon::now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        $table = $db->connection(config('platform-gate.usage_connection'))
            ->table((string) config('platform-gate.usage_table', 'api_call_log'));

        $deleted = 0;

        do {
            // `ORDER BY id`: un DELETE con LIMIT sin orden es no determinístico, y MySQL lo marca
            // como inseguro para la replicación por sentencia.
            $batch = (clone $table)
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->delete();

            $deleted += $batch;
        } while ($batch === self::BATCH);

        $logger->info('platform-gate: purga del registro de uso', ['deleted' => $deleted, 'cutoff' => $cutoff]);
        $this->info("Borradas {$deleted} filas anteriores a {$cutoff} (UTC).");

        return self::SUCCESS;
    }

    /** Un entero de 1 o más, o `null`. `"90"` del env vale; `"90d"`, `0`, `-1` o `1.5` no. */
    private static function retentionDays(mixed $configured): ?int
    {
        if (is_string($configured) && preg_match('/^\d+$/D', trim($configured)) === 1) {
            $configured = (int) trim($configured);
        }

        return is_int($configured) && $configured >= 1 ? $configured : null;
    }
}
