<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Console\PurgeUsageCommand;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * La purga del registro de uso (0.8, contrato 1019-registro-llamadas, L1): borra lo anterior al
 * corte y nada más, en lotes; N sale de la config, 90 por defecto; con N < 1 no borra nada; y al
 * log va sólo la cantidad y el corte.
 */
final class PurgeUsageCommandTest extends GateTestCase
{
    use UsageTable;

    private const NOW = '2026-10-19 12:00:00';

    /** `NOW` menos 90 días. */
    private const CUTOFF_90 = '2026-07-21 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->migrateUsage();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function insertAt(string ...$occurredAt): void
    {
        foreach (array_chunk($occurredAt, 500) as $chunk) {
            DB::table('api_call_log')->insert(array_map(static fn (string $at): array => [
                'key_id' => 'key_0123456789abcdef0123456789ab',
                'route' => 'api/v1/groups',
                'method' => 'GET',
                'status_code' => 200,
                'occurred_at' => $at,
                'duration_ms' => 3,
                'request_id' => null,
                'resource_type' => null,
                'resource_id' => null,
            ], $chunk));
        }
    }

    /** @return list<string> */
    private function remaining(): array
    {
        return array_column($this->rows(), 'occurred_at');
    }

    public function test_por_defecto_borra_lo_anterior_a_90_dias_y_nada_mas(): void
    {
        $this->insertAt(
            '2026-01-01 00:00:00',
            '2026-07-21 11:59:59', // un segundo antes del corte: se va
            self::CUTOFF_90,       // justo en el corte: queda
            '2026-07-21 12:00:01',
            '2026-10-19 11:59:00',
        );

        $this->artisan('platform-gate:purge-usage')->assertExitCode(0);

        $this->assertSame([self::CUTOFF_90, '2026-07-21 12:00:01', '2026-10-19 11:59:00'], $this->remaining());
    }

    public function test_n_sale_de_la_config(): void
    {
        config(['platform-gate.usage_retention_days' => 30]);

        $this->insertAt('2026-09-19 11:59:59', '2026-09-19 12:00:00', '2026-10-01 00:00:00');

        $this->artisan('platform-gate:purge-usage')->assertExitCode(0);

        $this->assertSame(['2026-09-19 12:00:00', '2026-10-01 00:00:00'], $this->remaining());
    }

    public function test_un_entero_como_texto_del_env_vale(): void
    {
        config(['platform-gate.usage_retention_days' => ' 30 ']);

        $this->insertAt('2026-09-19 11:59:59', '2026-09-19 12:00:00');

        $this->artisan('platform-gate:purge-usage')->assertExitCode(0);

        $this->assertSame(['2026-09-19 12:00:00'], $this->remaining());
    }

    public function test_un_dia_borra_lo_de_ayer_para_atras(): void
    {
        config(['platform-gate.usage_retention_days' => 1]);

        $this->insertAt('2026-10-18 11:59:59', '2026-10-18 12:00:00');

        $this->artisan('platform-gate:purge-usage')->assertExitCode(0);

        $this->assertSame(['2026-10-18 12:00:00'], $this->remaining());
    }

    public function test_borra_en_lotes(): void
    {
        $old = array_fill(0, 2 * PurgeUsageCommand::BATCH + 345, '2026-05-01 00:00:00');
        $this->insertAt(...$old, ...['2026-10-01 00:00:00', '2026-10-02 00:00:00']);

        $deletes = [];
        DB::listen(static function (QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with(strtolower($query->sql), 'delete')) {
                $deletes[] = $query->sql;
            }
        });

        $this->artisan('platform-gate:purge-usage')->assertExitCode(0);

        $this->assertCount(3, $deletes, 'tres lotes: 1000, 1000 y 345');
        $this->assertSame(['2026-10-01 00:00:00', '2026-10-02 00:00:00'], $this->remaining());
    }

    public function test_al_log_va_solo_la_cantidad_y_el_corte(): void
    {
        $this->insertAt('2026-01-01 00:00:00', '2026-02-01 00:00:00', '2026-10-01 00:00:00');

        $this->artisan('platform-gate:purge-usage')
            ->expectsOutputToContain('Borradas 2 filas anteriores a '.self::CUTOFF_90)
            ->assertExitCode(0);

        $this->assertSame([[
            'level' => 'info',
            'message' => 'platform-gate: purga del registro de uso',
            'context' => ['deleted' => 2, 'cutoff' => self::CUTOFF_90],
        ]], $this->logged);
    }

    public function test_sin_nada_que_borrar_termina_bien(): void
    {
        $this->insertAt('2026-10-01 00:00:00');

        $this->artisan('platform-gate:purge-usage')->assertExitCode(0);

        $this->assertSame(['2026-10-01 00:00:00'], $this->remaining());
        $this->assertSame(['deleted' => 0, 'cutoff' => self::CUTOFF_90], $this->logged[0]['context']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidRetention(): iterable
    {
        yield 'cero' => [0];
        yield 'negativo' => [-5];
        yield 'cero como texto' => ['0'];
        yield 'negativo como texto' => ['-5'];
        yield 'no es un número' => ['abc'];
        yield 'con unidad' => ['90d'];
        yield 'decimal' => [1.5];
        yield 'vacío' => [''];
        yield 'null' => [null];
        yield 'una lista' => [[90]];
    }

    #[DataProvider('invalidRetention')]
    public function test_se_niega_con_n_menor_que_1_o_que_no_es_un_entero(mixed $days): void
    {
        config(['platform-gate.usage_retention_days' => $days]);

        $this->insertAt('2020-01-01 00:00:00', '2026-10-19 11:00:00');

        $this->artisan('platform-gate:purge-usage')->assertExitCode(1);

        // Nada borrado: ni siquiera lo que sí es viejo.
        $this->assertCount(2, $this->rows());
        $this->assertLoggedError('No se borró nada');
    }

    public function test_purga_la_conexion_y_la_tabla_configuradas(): void
    {
        config([
            'database.connections.usage' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'platform-gate.usage_connection' => 'usage',
            'platform-gate.usage_table' => 'registro_api',
        ]);

        DB::connection('usage')->getSchemaBuilder()->create('registro_api', static function ($table): void {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
        });

        DB::connection('usage')->table('registro_api')->insert([
            ['occurred_at' => '2026-01-01 00:00:00'],
            ['occurred_at' => '2026-10-01 00:00:00'],
        ]);
        $this->insertAt('2026-01-01 00:00:00');

        $this->artisan('platform-gate:purge-usage')->assertExitCode(0);

        $this->assertSame(['2026-10-01 00:00:00'], DB::connection('usage')->table('registro_api')->pluck('occurred_at')->all());

        // La de la conexión por defecto no es la de uso: no se toca.
        $this->assertCount(1, $this->rows());
    }
}
