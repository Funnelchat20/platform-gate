<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Usage\ApiCall;
use Funnelchat\PlatformGate\Usage\ApiCallRecorder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

/**
 * Cuándo se reintenta sin las columnas de 0.8: sólo ante «columna desconocida». Con SQLite de
 * verdad está en `UsageMigrationTest`; acá, los códigos de MySQL y PostgreSQL, que los tests
 * no tienen cómo producir.
 */
final class ApiCallRecorderTest extends TestCase
{
    /** @var list<array<string, mixed>> lo que se intentó insertar, en orden */
    private array $inserts = [];

    /** @var list<array{string, string}> nivel y mensaje */
    private array $logged = [];

    private function call(): ApiCall
    {
        return new ApiCall('key_abc', 'api/v1/messages', 'POST', 201, '2026-10-19 12:00:00', 8, '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60', 'message', '981');
    }

    /** @param callable(array<string, mixed>): void $insert */
    private function recorder(callable $insert): ApiCallRecorder
    {
        $inserts = &$this->inserts;
        $logged = &$this->logged;

        $builder = $this->createMock(Builder::class);
        $builder->method('insert')->willReturnCallback(static function (array $row) use (&$inserts, $insert): bool {
            $inserts[] = $row;
            $insert($row);

            return true;
        });

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('table')->willReturn($builder);

        return new ApiCallRecorder($connection, 'api_call_log', new class($logged) extends AbstractLogger
        {
            /** @param list<array{string, string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        });
    }

    /** @param array{0: string, 1: int|null, 2: string} $errorInfo */
    private static function queryException(array $errorInfo): QueryException
    {
        $pdo = new PDOException($errorInfo[2]);
        $pdo->errorInfo = $errorInfo;

        return new QueryException('mysql', 'insert into api_call_log ...', [], $pdo);
    }

    /** @return iterable<string, array{Throwable}> */
    public static function missingColumn(): iterable
    {
        yield 'MySQL 1054' => [self::queryException(['42S22', 1054, "Unknown column 'request_id' in 'field list'"])];
        yield 'PostgreSQL 42703' => [self::queryException(['42703', 7, 'column "request_id" of relation "api_call_log" does not exist'])];
        yield 'SQLite' => [self::queryException(['HY000', 1, 'table api_call_log has no column named request_id'])];
    }

    #[DataProvider('missingColumn')]
    public function test_ante_columna_desconocida_escribe_la_fila_de_0_7(Throwable $missing): void
    {
        $recorder = $this->recorder(static function (array $row) use ($missing): void {
            if (array_key_exists('request_id', $row)) {
                throw $missing;
            }
        });

        $recorder->record($this->call());

        $this->assertCount(2, $this->inserts);
        $this->assertSame(['key_id', 'route', 'method', 'status_code', 'occurred_at', 'duration_ms'], array_keys($this->inserts[1]));
        $this->assertStringContainsString('Corré la migración', $this->logged[0][1]);
    }

    /** @return iterable<string, array{Throwable}> */
    public static function otherFailures(): iterable
    {
        yield 'deadlock' => [self::queryException(['40001', 1213, 'Deadlock found when trying to get lock'])];
        yield 'base caída' => [self::queryException(['HY000', 2002, 'Connection refused'])];
        yield 'no existe la tabla (SQLite)' => [self::queryException(['HY000', 1, 'no such table: api_call_log'])];
        yield 'no existe la tabla (MySQL)' => [self::queryException(['42S02', 1146, "Table 'x.api_call_log' doesn't exist"])];
        yield 'valor que no entra' => [self::queryException(['22001', 1406, "Data too long for column 'route'"])];
        yield 'no es de la base' => [new RuntimeException('Unknown column, pero no de PDO')];
    }

    #[DataProvider('otherFailures')]
    public function test_cualquier_otra_falla_sigue_el_camino_de_0_7_sin_reintentar(Throwable $failure): void
    {
        $recorder = $this->recorder(static fn () => throw $failure);

        $recorder->record($this->call());

        $this->assertCount(1, $this->inserts, 'un solo INSERT: no se reintenta');
        $this->assertSame([['warning', 'platform-gate: no se pudo registrar la llamada']], $this->logged);
    }

    public function test_si_la_fila_de_0_7_tambien_falla_se_loguea_como_en_0_7(): void
    {
        $missing = self::queryException(['42S22', 1054, "Unknown column 'request_id'"]);
        $down = self::queryException(['HY000', 2002, 'Connection refused']);

        $recorder = $this->recorder(static function (array $row) use ($missing, $down): void {
            throw array_key_exists('request_id', $row) ? $missing : $down;
        });

        $recorder->record($this->call());

        $this->assertCount(2, $this->inserts);
        $this->assertSame([['warning', 'platform-gate: no se pudo registrar la llamada']], $this->logged);
    }
}
