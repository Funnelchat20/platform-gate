<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\Http\RequestId;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * El id del pedido (0.8, contrato 1019-registro-llamadas, L1): de dónde sale, que vuelve en
 * `X-Request-Id` —también en los rechazos— y que es el mismo que queda en la fila y en el log.
 *
 * De punta a punta con el kernel HTTP —`TrustEdge` global, un 500 del dominio— está en
 * `RequestIdHttpTest`.
 */
final class RequestIdTest extends GateTestCase
{
    use UsageTable;

    private const SECRET = 'actual-3f9a1c7e5b2d4f6081a3c5e7b9d1f3a5';

    private const EDGE_ID = '0b8f5d2e-6c41-4a7e-9f3d-2a1b7c9e4d60';

    private const V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('platform-gate.edge_secret', self::SECRET);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateUsage();
    }

    private function key(array $abilities = ['communities:read', 'communities:write']): object
    {
        return $this->token(['kind' => 'api_key', 'abilities' => $abilities]);
    }

    public function test_del_borde_con_un_uuid_valido_se_usa_ese_en_la_respuesta_y_en_la_fila(): void
    {
        $response = $this->gateCall('GET', 'api/v1/groups', $this->key(), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::EDGE_ID, $response->headers->get(RequestId::RESPONSE_HEADER));
        $this->assertSame(self::EDGE_ID, $this->onlyRow()['request_id']);
    }

    public function test_del_borde_en_mayusculas_se_guarda_en_minusculas(): void
    {
        $response = $this->gateCall('GET', 'api/v1/groups', $this->key(), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => strtoupper(self::EDGE_ID),
        ]);

        $this->assertSame(self::EDGE_ID, $response->headers->get(RequestId::RESPONSE_HEADER));
        $this->assertSame(self::EDGE_ID, $this->onlyRow()['request_id']);
    }

    /** @return iterable<string, array{string|list<string>}> */
    public static function invalidEdgeIds(): iterable
    {
        yield 'no es un uuid' => ['pedido-123'];
        yield 'vacío' => [''];
        yield 'un caracter de más' => [self::EDGE_ID.'0'];
        yield 'sin guiones' => [str_replace('-', '', self::EDGE_ID)];
        yield 'con llaves' => ['{'.self::EDGE_ID.'}'];
        yield 'con algo atrás' => [self::EDGE_ID."\nX-Otro: 1"];
        yield 'dos cabeceras' => [[self::EDGE_ID, 'c5a1e3f7-2b4d-4f6a-8c0e-1a3b5c7d9e2f']];
    }

    /** @param string|list<string> $presented */
    #[DataProvider('invalidEdgeIds')]
    public function test_del_borde_uno_invalido_se_descarta_y_se_genera_otro(string|array $presented): void
    {
        $response = $this->gateCall('GET', 'api/v1/groups', $this->key(), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => $presented,
        ]);

        $id = $response->headers->get(RequestId::RESPONSE_HEADER);

        $this->assertMatchesRegularExpression(self::V4, (string) $id);
        $this->assertNotSame(self::EDGE_ID, $id);
        $this->assertSame($id, $this->onlyRow()['request_id']);
        $this->assertLogged('warning', 'no es un UUID');

        // El valor no se sabe qué es: no va al log.
        foreach ((array) $presented as $value) {
            if ($value !== '') {
                $this->assertNeverLogged($value);
            }
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function withoutEdge(): iterable
    {
        yield 'sin secreto' => [null];
        yield 'con un secreto que no coincide' => ['cualquier-otro-valor-7c1e3a5b9d2f4e6a8b0c'];
    }

    #[DataProvider('withoutEdge')]
    public function test_sin_el_secreto_del_borde_un_uuid_valido_se_descarta(?string $secret): void
    {
        // Cualquiera puede mandar la cabecera directo a un host interno: no se le cree.
        $headers = [RequestId::EDGE_HEADER => self::EDGE_ID];

        if ($secret !== null) {
            $headers[EdgeTrust::SECRET_HEADER] = $secret;
        }

        $response = $this->gateCall('GET', 'api/v1/groups', $this->key(), $headers);

        $id = $response->headers->get(RequestId::RESPONSE_HEADER);

        $this->assertMatchesRegularExpression(self::V4, (string) $id);
        $this->assertNotSame(self::EDGE_ID, $id);
        $this->assertSame($id, $this->onlyRow()['request_id']);
    }

    public function test_sin_cabecera_se_genera_un_uuid_v4_distinto_por_pedido(): void
    {
        $first = $this->gateCall('GET', 'api/v1/groups', $this->key())->headers->get(RequestId::RESPONSE_HEADER);
        $this->app->forgetScopedInstances();
        $second = $this->gateCall('GET', 'api/v1/groups', $this->key())->headers->get(RequestId::RESPONSE_HEADER);

        $this->assertMatchesRegularExpression(self::V4, (string) $first);
        $this->assertMatchesRegularExpression(self::V4, (string) $second);
        $this->assertNotSame($first, $second);
        $this->assertSame([$first, $second], array_column($this->rows(), 'request_id'));
    }

    /** @return iterable<string, array{callable(self): void, ?array<string, mixed>, int, string}> */
    public static function rejections(): iterable
    {
        yield '403 permission_denied' => [static fn () => null, ['kind' => 'api_key', 'abilities' => ['communities:read']], 403, 'permission_denied'];
        yield '403 route_not_available' => [static fn () => config(['platform-gate.allowed' => []]), null, 403, 'route_not_available'];
        yield '403 use_public_endpoint' => [static fn () => config(['platform-gate.require_edge' => true]), null, 403, 'use_public_endpoint'];
        yield '503 gate_unavailable' => [static fn () => null, ['kind' => 'otra-cosa', 'abilities' => []], 503, 'gate_unavailable'];
    }

    /**
     * @param callable(): void $configure
     * @param array<string, mixed>|null $token
     */
    #[DataProvider('rejections')]
    public function test_un_rechazo_del_portero_tambien_devuelve_el_id(callable $configure, ?array $token, int $status, string $error): void
    {
        $configure();
        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(\Funnelchat\PlatformGate\Limits\DailyCallCap::class);
        $this->app->forgetInstance(\Funnelchat\PlatformGate\Permissions\RoutePermissions::class);

        $response = $this->gateCall('POST', 'api/v1/groups', $this->token($token ?? ['kind' => 'api_key', 'abilities' => ['communities:write']]), [
            EdgeTrust::SECRET_HEADER => 'cualquier-otro-valor-7c1e3a5b9d2f4e6a8b0c',
        ]);

        $this->assertRejected($response, $status, $error);

        $id = $response->headers->get(RequestId::RESPONSE_HEADER);
        $this->assertMatchesRegularExpression(self::V4, (string) $id);

        // Una llamada de key rechazada también queda registrada, y con el mismo id. El 503 de
        // `kind` desconocido no llegó a saber que era una key: no tiene fila.
        $rows = $this->rows();

        if ($error === 'gate_unavailable') {
            $this->assertSame([], $rows);
        } else {
            $this->assertCount(1, $rows);
            $this->assertSame($id, $rows[0]['request_id']);
            $this->assertSame($status, (int) $rows[0]['status_code']);
        }
    }

    public function test_el_429_del_tope_tambien_devuelve_el_id(): void
    {
        config(['platform-gate.daily_call_cap' => 1]);
        $this->app->forgetInstance(\Funnelchat\PlatformGate\Limits\DailyCallCap::class);
        $this->app->forgetScopedInstances();

        $this->gateCall('GET', 'api/v1/groups', $this->key());
        $this->app->forgetScopedInstances();
        $response = $this->gateCall('GET', 'api/v1/groups', $this->key());

        $this->assertRejected($response, 429, 'daily_cap_exceeded');

        $id = $response->headers->get(RequestId::RESPONSE_HEADER);
        $this->assertMatchesRegularExpression(self::V4, (string) $id);
        $this->assertSame($id, $this->rows()[1]['request_id']);
        $this->assertSame(429, (int) $this->rows()[1]['status_code']);
    }

    public function test_el_rechazo_del_borde_lleva_el_id_que_mando_el_borde(): void
    {
        // Con secreto válido y una key sin permiso: 403, con el id del borde, igual en la fila.
        $response = $this->gateCall('POST', 'api/v1/groups', $this->key(['communities:read']), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ]);

        $this->assertRejected($response, 403, 'permission_denied');
        $this->assertSame(self::EDGE_ID, $response->headers->get(RequestId::RESPONSE_HEADER));
        $this->assertSame(self::EDGE_ID, $this->onlyRow()['request_id']);
    }

    /** @return iterable<string, array{?object}> */
    public static function notKeys(): iterable
    {
        yield 'web (sesión)' => [new TransientToken()];
        yield 'sin token' => [null];
    }

    #[DataProvider('notKeys')]
    public function test_todos_los_llamadores_reciben_el_id_y_solo_las_keys_tienen_fila(?object $token): void
    {
        $response = $this->gateCall('GET', 'api/v1/groups', $token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertMatchesRegularExpression(self::V4, (string) $response->headers->get(RequestId::RESPONSE_HEADER));
        $this->assertSame([], $this->rows());
    }

    public function test_el_id_del_portero_gana_sobre_uno_que_puso_el_dominio(): void
    {
        $response = $this->gateCall('GET', 'api/v1/groups', $this->key(), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ], static fn () => new Response('ok', 200, [RequestId::RESPONSE_HEADER => 'otro-del-dominio']));

        $this->assertSame(self::EDGE_ID, $response->headers->get(RequestId::RESPONSE_HEADER));
    }

    public function test_el_id_va_al_contexto_compartido_del_log_antes_de_que_el_dominio_corra(): void
    {
        $shared = [];

        $logger = new class($shared) extends AbstractLogger
        {
            /** @param array<string, mixed> $shared */
            public function __construct(private array &$shared)
            {
            }

            /** @param array<string, mixed> $context */
            public function shareContext(array $context): static
            {
                $this->shared = array_merge($this->shared, $context);

                return $this;
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };

        $this->rebindLogger($logger);

        $seenByDomain = null;

        $response = $this->gateCall('GET', 'api/v1/groups', $this->key(), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
            RequestId::EDGE_HEADER => self::EDGE_ID,
        ], static function () use (&$shared, &$seenByDomain) {
            $seenByDomain = $shared['request_id'] ?? null;

            return new Response('ok');
        });

        $this->assertSame(self::EDGE_ID, $seenByDomain);
        $this->assertSame(self::EDGE_ID, $response->headers->get(RequestId::RESPONSE_HEADER));
    }

    public function test_con_el_log_de_laravel_el_id_esta_durante_el_pedido_y_sale_al_terminar(): void
    {
        $log = $this->app->make('log');
        $this->rebindLogger($log);

        // Algo que el dominio comparte por su cuenta, y un canal ya abierto.
        $log->shareContext(['service' => 'communities']);
        $channel = $log->channel();

        $during = null;
        $channelDuring = null;

        $response = $this->gateCall('GET', 'api/v1/groups', $this->key(), [], static function () use ($log, $channel, &$during, &$channelDuring) {
            $during = $log->sharedContext();
            $channelDuring = (fn () => $this->context)->call($channel);

            return new Response('ok');
        });

        $id = $response->headers->get(RequestId::RESPONSE_HEADER);

        $this->assertSame($id, $during['request_id'] ?? null);
        $this->assertSame($id, $channelDuring['request_id'] ?? null);

        // Terminó: sale `request_id`, y lo del dominio sigue.
        $this->assertSame(['service' => 'communities'], $log->sharedContext());
        $this->assertSame(['service' => 'communities'], (fn () => $this->context)->call($channel));
    }

    public function test_un_request_id_que_puso_el_dominio_no_se_toca_al_terminar(): void
    {
        $log = $this->app->make('log');
        $this->rebindLogger($log);
        $channel = $log->channel();

        // El dominio pisa `request_id` con el suyo durante el pedido.
        $this->gateCall('GET', 'api/v1/groups', $this->key(), [], static function () use ($log) {
            $log->shareContext(['request_id' => 'id-del-dominio']);

            return new Response('ok');
        });

        $this->assertSame('id-del-dominio', $log->sharedContext()['request_id'] ?? null);
        $this->assertSame('id-del-dominio', (fn () => $this->context)->call($channel)['request_id'] ?? null);
    }

    public function test_al_terminar_un_pedido_que_no_es_de_key_tambien_sale_del_log(): void
    {
        $log = $this->app->make('log');
        $this->rebindLogger($log);

        $this->gateCall('GET', 'api/v1/groups', new TransientToken());

        $this->assertArrayNotHasKey('request_id', $log->sharedContext());
    }

    public function test_un_log_sin_contexto_compartido_no_rompe_nada(): void
    {
        // El logger de `GateTestCase` es PSR-3 pelado: no tiene `shareContext()`.
        $response = $this->gateCall('GET', 'api/v1/groups', $this->key());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($response->headers->get(RequestId::RESPONSE_HEADER), $this->onlyRow()['request_id']);
    }

    public function test_se_decide_una_vez_por_pedido(): void
    {
        $request = $this->request('GET', 'api/v1/groups', $this->key(), [
            EdgeTrust::SECRET_HEADER => self::SECRET,
        ]);

        $ids = $this->app->make(RequestId::class);

        $first = $ids->for($request);

        $this->assertSame($first, $ids->for($request));
        $this->assertSame($first, RequestId::of($request));
    }

    private function rebindLogger(LoggerInterface $logger): void
    {
        $this->app->instance(LoggerInterface::class, $logger);
        $this->app->forgetInstance(EdgeTrust::class);
        $this->app->forgetInstance(RequestId::class);
        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(PlatformGate::class);
    }
}
