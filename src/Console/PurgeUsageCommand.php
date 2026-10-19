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
 *     Schedule::command('platform-gate:purge-usage')->daily()->onOneServer()->withoutOverlapping();
 *
 * Falla cerrado: con N menor que 1, o algo que no es un entero, no borra nada y termina con
 * error. Un 0 que se coló en el env no puede vaciar la tabla.
 *
 * En lotes, para no tener un DELETE gigante bloqueando la tabla en la que el portero escribe
 * en cada llamada. Borra lo ESTRICTAMENTE anterior al corte, y nada más.
 *
 * Por rango de id, sin índice nuevo: la tabla es de sólo agregado y el id crece con el tiempo.
 * No hay índice por `occurred_at` solo, así que filtrar por fecha recorre la clave primaria
 * desde el principio, y el último lote —el que no encuentra nada— recorre la tabla entera.
 * En cambio:
 *
 *   1. el límite es el PRIMER id con `occurred_at` en el corte o después. Se busca subiendo por
 *      la clave primaria y se para en la primera coincidencia: lee sólo las filas viejas;
 *   2. cada lote borra `id < límite` —un rango de la clave primaria— y además
 *      `occurred_at < corte`.
 *
 * El filtro de fecha se queda porque el id no es exactamente monótono con `occurred_at`: es el
 * momento en que EMPEZÓ el pedido, y la fila se escribe al terminarlo. Un pedido lento queda
 * con un id mayor que el de uno más nuevo y corto. Sin el filtro, borrar hasta el último id
 * viejo se llevaría filas nuevas; con el límite en el primer id nuevo, una fila vieja que quedó
 * después de él espera a la corrida siguiente. Se equivoca hacia guardar, nunca hacia borrar.
 *
 * Una fila con fecha futura —un reloj adelantado, un dato roto— no cuenta como límite: si no,
 * una sola fila con `occurred_at` en 2099 entre las viejas trabaría la purga para siempre, porque
 * nunca llega a ser vieja. Para el límite se ignoran las de más de un día en el futuro; no se
 * borran (no son anteriores al corte) y quedan en un warning con cuántas había antes del límite.
 * Las que están más adelante no se cuentan: contarlas sería recorrer la tabla entera.
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
        $now = Carbon::now('UTC');
        $cutoff = $now->copy()->subDays($days)->format('Y-m-d H:i:s');

        // Más allá de esto, la fecha no es creíble: no puede hacer de límite.
        $future = $now->copy()->addDay()->format('Y-m-d H:i:s');

        $table = $db->connection(config('platform-gate.usage_connection'))
            ->table((string) config('platform-gate.usage_table', 'api_call_log'));

        // `null`: no hay ninguna fila creíble del corte en adelante, todo lo que hay es viejo.
        $boundary = (clone $table)
            ->where('occurred_at', '>=', $cutoff)
            ->where('occurred_at', '<=', $future)
            ->orderBy('id')
            ->limit(1)
            ->value('id');

        // Las que se saltearon para llegar al límite: un rango de la clave primaria, no la tabla.
        $skipped = (clone $table)
            ->when($boundary !== null, static fn ($query) => $query->where('id', '<', $boundary))
            ->where('occurred_at', '>', $future)
            ->count();

        if ($skipped > 0) {
            $logger->warning(
                'platform-gate: hay filas del registro de uso con fecha de más de un día en el futuro (reloj '
                .'adelantado o dato roto). No se borran ni frenan la purga; revisalas.',
                ['future_rows' => $skipped, 'after' => $future],
            );
        }

        $deleted = 0;

        do {
            // `ORDER BY id`: un DELETE con LIMIT sin orden es no determinístico, y MySQL lo marca
            // como inseguro para la replicación por sentencia.
            $batch = (clone $table)
                ->when($boundary !== null, static fn ($query) => $query->where('id', '<', $boundary))
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
