<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\Caller;
use Funnelchat\PlatformGate\Caller\CallerContext;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\PlatformGateServiceProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Orchestra\Testbench\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * El portero entero, montado en una aplicación Laravel y con el modelo de token de
 * Sanctum tal como viene: sin `getAbilities()` y con `abilities` casteado a JSON.
 *
 * Lo que importa probar acá es la decisión de punta a punta —qué status sale y qué
 * llamador queda en el contexto—, no cada pieza por separado.
 */
abstract class GateTestCase extends TestCase
{
    /** @var list<array{level: string, message: string}> */
    protected array $logged = [];

    protected function getPackageProviders($app): array
    {
        return [PlatformGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('platform-gate.domain', 'communities');
        $app['config']->set('platform-gate.allowed', ['api/v1/*']);
        $app['config']->set('platform-gate.cache_store', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $logged = &$this->logged;

        $this->app->instance(LoggerInterface::class, new class($logged) extends AbstractLogger
        {
            /** @param list<array{level: string, message: string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    protected function token(array $attributes): PersonalAccessToken
    {
        return (new PersonalAccessToken())->forceFill(['id' => 42] + $attributes);
    }

    protected function send(string $method, string $uri, ?object $token): Response
    {
        $user = (new class extends User
        {
            use HasApiTokens;
        })->forceFill(['id' => 7]);

        if ($token !== null) {
            $user->withAccessToken($token);
        }

        $request = Request::create('/'.str_replace(['{', '}'], '', $uri), $method);
        $route = new Route([$method], $uri, static fn () => null);
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn () => $user);

        return $this->app->make(PlatformGate::class)
            ->handle($request, static fn () => new Response('ok'));
    }

    protected function caller(): Caller
    {
        return $this->app->make(CallerContext::class)->get();
    }

    protected function assertRejected(Response $response, int $status, string $error): void
    {
        $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($error, json_decode((string) $response->getContent(), true)['error'] ?? null);
    }

    protected function assertLoggedError(string $fragment): void
    {
        foreach ($this->logged as $record) {
            if ($record['level'] === 'error' && str_contains($record['message'], $fragment)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail("No se logueó ningún error que contenga `{$fragment}`.");
    }
}
