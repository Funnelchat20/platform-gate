<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\CallerContext;
use Funnelchat\PlatformGate\Caller\KeyIdentity;
use Funnelchat\PlatformGate\Caller\KindColumnProbe;
use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\Http\Middleware\TrustEdge;
use Funnelchat\PlatformGate\Http\RequestId;
use Funnelchat\PlatformGate\Limits\DailyCallCap;
use Funnelchat\PlatformGate\Permissions\PermissionChecker;
use Funnelchat\PlatformGate\Permissions\RoutePermissions;
use Funnelchat\PlatformGate\Usage\ApiCallRecorder;
use Funnelchat\PlatformGate\Usage\CreatedResource;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los constructores de 0.7.1 siguen andando en 0.8: lo nuevo va al final y es opcional, y lo que
 * no se pasa sale del contenedor —el mismo singleton de `RequestId`, el mismo contexto del
 * request—.
 */
final class ConstructorCompatTest extends GateTestCase
{
    use UsageTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateUsage();
    }

    /** Los argumentos de 0.7.1 hasta `EdgeTrust` incluido. */
    private function gateArgumentsOf071(): array
    {
        return [
            $this->app->make(CallerContext::class),
            $this->app->make(RoutePermissions::class),
            $this->app->make(PermissionChecker::class),
            $this->app->make(DailyCallCap::class),
            $this->app->make(ApiCallRecorder::class),
            $this->app->make(KeyIdentity::class),
            $this->app->make(KindColumnProbe::class),
            $this->app->make(LoggerInterface::class),
            static fn ($user): array => [$user?->getKey(), false],
            $this->app->make(EdgeTrust::class),
        ];
    }

    private function runGate(PlatformGate $gate): Response
    {
        $request = $this->request('POST', 'api/v1/messages', $this->token(['kind' => 'api_key', 'abilities' => ['communities:write']]));

        $response = $gate->handle($request, static function (): Response {
            CreatedResource::declare('message', 981);

            return new Response('creado', 201);
        });
        $gate->terminate($request, $response);

        $this->assertSame($this->app->make(RequestId::class)->for($request), $response->headers->get(RequestId::RESPONSE_HEADER));

        return $response;
    }

    public function test_new_platform_gate_con_edge_y_un_bool_como_en_0_7_1(): void
    {
        $gate = new PlatformGate(...[...$this->gateArgumentsOf071(), true]);

        $this->assertSame(201, $this->runGate($gate)->getStatusCode());

        $row = $this->onlyRow();
        $this->assertSame('message', $row['resource_type']);
        $this->assertSame('981', $row['resource_id']);
        $this->assertNotNull($row['request_id']);
    }

    public function test_new_platform_gate_con_todos_los_argumentos_de_0_7_1(): void
    {
        $gate = new PlatformGate(...[...$this->gateArgumentsOf071(), false, false, 'https://api.funnelchat.com']);

        $this->assertSame(201, $this->runGate($gate)->getStatusCode());
        $this->assertSame('981', $this->onlyRow()['resource_id']);
    }

    public function test_new_platform_gate_con_lo_justo_de_0_7_1(): void
    {
        $gate = new PlatformGate(...$this->gateArgumentsOf071());

        $this->assertSame(201, $this->runGate($gate)->getStatusCode());
    }

    public function test_new_trust_edge_con_solo_el_edge_como_en_0_7_1(): void
    {
        $middleware = new TrustEdge($this->app->make(EdgeTrust::class));

        $request = $this->request('GET', 'panel/groups', null);
        $response = $middleware->handle($request, static fn () => new Response('ok'));
        $middleware->terminate($request, $response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->app->make(RequestId::class)->for($request), $response->headers->get(RequestId::RESPONSE_HEADER));
    }
}
