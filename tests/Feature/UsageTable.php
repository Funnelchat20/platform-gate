<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * La tabla de uso de verdad —SQLite en memoria, con las migraciones del paquete— y una llamada
 * entera: `handle()` y después `terminate()`, que es donde se escribe la fila.
 */
trait UsageTable
{
    protected const OLD_MIGRATION = '2026_09_11_000001_create_api_call_log_table.php';

    /** Todas las migraciones del paquete, o sólo la de 0.7 para simular un dominio sin migrar. */
    protected function migrateUsage(bool $onlyOld = false): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations';

        $this->artisan('migrate', ['--path' => $onlyOld ? "{$path}/".self::OLD_MIGRATION : $path, '--realpath' => true])
            ->assertExitCode(0);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param (callable(\Illuminate\Http\Request): Response)|null $controller
     */
    protected function gateCall(string $method, string $uri, ?object $token, array $headers = [], ?callable $controller = null): Response
    {
        $request = $this->request($method, $uri, $token, $headers);
        $gate = $this->app->make(PlatformGate::class);

        $response = $gate->handle($request, $controller ?? static fn () => new Response('ok'));
        $gate->terminate($request, $response);

        return $response;
    }

    /** @return list<array<string, mixed>> */
    protected function rows(): array
    {
        return DB::table('api_call_log')->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }

    /** @return array<string, mixed> */
    protected function onlyRow(): array
    {
        $rows = $this->rows();
        $this->assertCount(1, $rows);

        return $rows[0];
    }
}
