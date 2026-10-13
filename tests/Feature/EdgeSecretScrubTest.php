<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\Http\Middleware\TrustEdge;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * El secreto del borde no queda en ningún lugar del que lea un reporte de errores.
 *
 * Auditoría de communities, 13-10: un reporte de errores como Sentry, ante un log emitido
 * antes de su propio middleware, arma el pedido con lo que encuentra en ese momento, y lo
 * que encuentra incluye `$_SERVER`. Los logs de la librería dentro de `TrustEdge` —secreto
 * mal cargado, rotación desordenada, host fuera de la lista— salen justo ahí.
 *
 * Acá el log hace lo mismo que ese reporte: en cada llamada mira el pedido en curso y los
 * globales, y anota qué vio. Y el pedido llega como llega a PHP: con la cabecera también en
 * `$_SERVER`, y en `$_ENV` para los SAPI que la copian ahí.
 */
final class EdgeSecretScrubTest extends GateTestCase
{
    private const SECRET = 'actual-3f9a1c7e5b2d4f6081a3c5e7b9d1f3a5';

    /** El del Worker cuando el dominio tiene otro: una rotación desordenada. */
    private const OTHER = 'del-worker-9d1f3a5c7e2b4d6f8a0c1e3b5d7f9a2c';

    /** Un secreto mal cargado: corto, no se usa. */
    private const SHORT = 'changeme';

    /** @var list<array<string, string>> lo que vio el «reporte» en cada log: lugar => json */
    private array $seenAtLog = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('platform-gate.edge_secret', self::SECRET);
        $app['config']->set('platform-gate.public_hosts', 'api.funnelchat.com');
    }

    protected function defineRoutes($router): void
    {
        // Lo que ve el dominio después de `TrustEdge` y del portero.
        $router->middleware(PlatformGate::class)->get('api/v1/groups', static fn (Request $request): array => [
            'headers' => $request->headers->has(EdgeTrust::SECRET_HEADER),
            'server' => $request->server->has('HTTP_X_FUNNELCHAT_EDGE'),
            '$_SERVER' => array_key_exists('HTTP_X_FUNNELCHAT_EDGE', $_SERVER),
            '$_ENV' => array_key_exists('HTTP_X_FUNNELCHAT_EDGE', $_ENV),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedServer = $_SERVER;
        $this->savedEnv = $_ENV;

        $this->app->make(Kernel::class)->pushMiddleware(TrustEdge::class);

        $logged = &$this->logged;
        $seen = &$this->seenAtLog;

        $this->app->instance(LoggerInterface::class, new class($logged, $seen, $this->app) extends AbstractLogger
        {
            /**
             * @param list<array{level: string, message: string, context: array<string, mixed>}> $records
             * @param list<array<string, string>> $seen
             */
            public function __construct(private array &$records, private array &$seen, private Container $app)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];

                $request = $this->app->make('request');

                $this->seen[] = [
                    'las cabeceras del pedido' => $this->json($request->headers->all()),
                    '$request->server' => $this->json($request->server->all()),
                    '$_SERVER' => $this->json($_SERVER),
                    '$_ENV' => $this->json($_ENV),
                ];
            }

            private function json(mixed $value): string
            {
                return (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        });
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_ENV = $this->savedEnv;

        parent::tearDown();
    }

    /** @return iterable<string, array{?string, string}> */
    public static function arrivals(): iterable
    {
        // secreto configurado en el dominio, secreto que llega
        yield 'llega bien' => [self::SECRET, self::SECRET];
        yield 'llega mal: rotación desordenada' => [self::SECRET, self::OTHER];
        yield 'el dominio no tiene secreto: Worker encendido antes' => [null, self::SECRET];
        yield 'el dominio lo tiene mal cargado' => [self::SHORT, self::SECRET];
    }

    #[DataProvider('arrivals')]
    public function test_el_secreto_sale_de_las_cabeceras_del_server_y_de_los_globales(?string $configured, string $presented): void
    {
        config(['platform-gate.edge_secret' => $configured]);

        $seen = $this->arrive($presented)->assertOk()->json();

        $this->assertSame(['headers' => false, 'server' => false, '$_SERVER' => false, '$_ENV' => false], $seen);
        $this->assertArrayNotHasKey('HTTP_X_FUNNELCHAT_EDGE', $_SERVER);
        $this->assertArrayNotHasKey('HTTP_X_FUNNELCHAT_EDGE', $_ENV);
    }

    public function test_sin_trust_edge_el_portero_tambien_lo_saca_de_los_globales(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->setGlobalMiddleware(array_values(array_filter(
            $kernel->getGlobalMiddleware(),
            static fn ($middleware) => $middleware !== TrustEdge::class,
        )));

        $seen = $this->arrive(self::OTHER)->assertOk()->json();

        $this->assertSame(['headers' => false, 'server' => false, '$_SERVER' => false, '$_ENV' => false], $seen);
        $this->assertLogged('warning', 'no coincide');
        $this->assertNothingSeenAtLog(self::OTHER);
    }

    /** @return iterable<string, array{array<string, mixed>, string, array<string, string>, string, string}> */
    public static function logsInsideTrustEdge(): iterable
    {
        // config del dominio, secreto que llega, otras cabeceras, nivel y texto del log
        yield 'secreto mal cargado' => [
            ['platform-gate.edge_secret' => self::SHORT], self::SECRET, [],
            'error', '`PLATFORM_GATE_EDGE_SECRET` no se usa',
        ];
        yield 'secreto siguiente mal cargado' => [
            ['platform-gate.edge_secret_next' => self::SHORT], self::OTHER, [],
            'error', '`PLATFORM_GATE_EDGE_SECRET_NEXT` no se usa',
        ];
        yield 'rotación desordenada' => [
            [], self::OTHER, [],
            'warning', 'no coincide con ninguno de los dos secretos',
        ];
        yield 'Worker encendido antes que el secreto' => [
            ['platform-gate.edge_secret' => null], self::SECRET, [],
            'warning', 'no tiene secreto del borde configurado',
        ];
        yield 'host mal cargado' => [
            ['platform-gate.public_hosts' => 'https://api.funnelchat.com'], self::SECRET, ['X-Forwarded-Host' => 'api.funnelchat.com'],
            'error', 'no es un host',
        ];
        yield 'host fuera de la lista' => [
            [], self::SECRET, ['X-Forwarded-Host' => 'ejemplo-externo.test'],
            'warning', 'no está en `PLATFORM_GATE_PUBLIC_HOSTS`',
        ];
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $headers
     */
    #[DataProvider('logsInsideTrustEdge')]
    public function test_un_log_emitido_dentro_de_trust_edge_no_encuentra_el_secreto(array $config, string $presented, array $headers, string $level, string $fragment): void
    {
        config($config);

        $this->arrive($presented, $headers)->assertOk();

        // El log salió: el caso que importa es el que loguea, no uno en el que no pasa nada.
        $this->assertLogged($level, $fragment);
        $this->assertNothingSeenAtLog($presented);

        $this->assertNeverLogged(self::SECRET);
        $this->assertNeverLogged(self::OTHER);
        $this->assertNeverLogged(self::SHORT);
    }

    public function test_lo_que_no_acepta_de_la_config_lo_dice_una_vez_y_desde_el_primer_pedido(): void
    {
        config(['platform-gate.edge_secret' => self::SHORT]);

        // El primer pedido no trae el secreto: el error sale igual, no espera a que llegue uno.
        $this->arrive(null)->assertOk();
        $this->assertSame(1, $this->countLogged('`PLATFORM_GATE_EDGE_SECRET` no se usa'));

        $this->arrive(self::SECRET)->assertOk();
        $this->assertSame(1, $this->countLogged('`PLATFORM_GATE_EDGE_SECRET` no se usa'));
    }

    /**
     * Como llega a PHP: la cabecera en el pedido y también en `$_SERVER` —y en `$_ENV`, si el
     * SAPI la copia ahí—.
     *
     * @param array<string, string> $headers
     */
    private function arrive(?string $presented, array $headers = []): TestResponse
    {
        if ($presented !== null) {
            $headers[EdgeTrust::SECRET_HEADER] = $presented;
            $_SERVER['HTTP_X_FUNNELCHAT_EDGE'] = $presented;
            $_ENV['HTTP_X_FUNNELCHAT_EDGE'] = $presented;
        }

        return $this->withHeaders($headers)->getJson('http://'.self::INTERNAL_HOST.'/api/v1/groups');
    }

    private function assertNothingSeenAtLog(string $secret): void
    {
        $this->assertNotEmpty($this->seenAtLog, 'No se logueó nada: no hay qué revisar.');

        // `assertFalse` y no `assertStringNotContainsString`: al fallar, ésa imprime lo que vio,
        // y lo que vio es `$_SERVER` entero, con las variables de entorno de quien corre esto.
        foreach ($this->seenAtLog as $i => $places) {
            foreach ($places as $place => $json) {
                $this->assertFalse(
                    str_contains($json, $secret),
                    "El log «{$this->logged[$i]['message']}» salió con el secreto todavía en {$place}.",
                );
            }
        }
    }

    private function countLogged(string $fragment): int
    {
        return count(array_filter(
            $this->logged,
            static fn (array $record): bool => str_contains($record['message'], $fragment),
        ));
    }
}
