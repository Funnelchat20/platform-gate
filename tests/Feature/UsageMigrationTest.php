<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Usage\CreatedResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * La migración de 0.8 —tres columnas nullable y un índice por `request_id`— y lo que pasa con
 * un dominio que subió a 0.8 y todavía no la corrió: sigue registrando, como en 0.7.
 */
final class UsageMigrationTest extends GateTestCase
{
    use UsageTable;

    private const OLD_ROW = [
        'key_id' => 'key_0123456789abcdef0123456789ab',
        'route' => 'api/v1/groups',
        'method' => 'GET',
        'status_code' => 200,
        'occurred_at' => '2026-10-01 10:00:00',
        'duration_ms' => 12,
    ];

    private function key(): object
    {
        return $this->token(['kind' => 'api_key', 'abilities' => ['communities:write']]);
    }

    public function test_suma_tres_columnas_nullable_y_un_indice_por_request_id(): void
    {
        $this->migrateUsage();

        foreach (['request_id', 'resource_type', 'resource_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('api_call_log', $column), $column);
        }

        $indexes = collect(Schema::getIndexes('api_call_log'))->keyBy('name');

        $this->assertTrue($indexes->has('api_call_log_request_id_idx'));
        $this->assertSame(['request_id'], $indexes['api_call_log_request_id_idx']['columns']);

        // Nullable: una fila sin ninguna de las tres entra.
        DB::table('api_call_log')->insert(self::OLD_ROW);
        $this->assertCount(1, $this->rows());
    }

    public function test_las_filas_de_antes_quedan_como_estan(): void
    {
        $this->migrateUsage(onlyOld: true);
        DB::table('api_call_log')->insert(self::OLD_ROW);

        $this->migrateUsage();

        $row = $this->onlyRow();

        // SQLite devuelve los enteros como enteros; se compara con `==` por si el driver no.
        $this->assertEquals(self::OLD_ROW, array_intersect_key($row, self::OLD_ROW));
        $this->assertNull($row['request_id']);
        $this->assertNull($row['resource_type']);
        $this->assertNull($row['resource_id']);
    }

    public function test_el_rollback_deja_la_tabla_como_en_0_7(): void
    {
        $this->migrateUsage();

        $this->artisan('migrate:rollback', [
            '--path' => dirname(__DIR__, 2).'/database/migrations/2026_10_19_000001_add_request_and_resource_to_api_call_log_table.php',
            '--realpath' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('api_call_log'));
        $this->assertFalse(Schema::hasColumn('api_call_log', 'request_id'));
        $this->assertFalse(Schema::hasColumn('api_call_log', 'resource_type'));
        $this->assertFalse(Schema::hasColumn('api_call_log', 'resource_id'));
    }

    public function test_sin_la_migracion_nueva_la_llamada_se_registra_como_en_0_7(): void
    {
        // Un dominio que subió la versión y todavía no corrió `migrate`: la fila no se pierde.
        $this->migrateUsage(onlyOld: true);

        $response = $this->gateCall('POST', 'api/v1/messages', $this->key(), [], static function (Request $request): Response {
            CreatedResource::declare($request, 'message', 981);

            return new Response('creado', 201);
        });

        $this->assertSame(201, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('X-Request-Id'));

        $row = $this->onlyRow();
        $this->assertSame('api/v1/messages', $row['route']);
        $this->assertSame(201, (int) $row['status_code']);
        $this->assertArrayNotHasKey('request_id', $row);

        $this->assertLogged('warning', 'Corré la migración');
    }

    public function test_si_tampoco_asi_se_puede_escribir_se_loguea_como_siempre(): void
    {
        // Sin tabla: no es la migración que falta, es otra cosa. La llamada no se rompe.
        $response = $this->gateCall('GET', 'api/v1/groups', $this->key());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertLogged('warning', 'no se pudo registrar la llamada');
    }
}
