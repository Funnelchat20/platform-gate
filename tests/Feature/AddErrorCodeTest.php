<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\ScrambleServiceProvider;
use Funnelchat\PlatformGate\OpenApi\AddErrorCode;
use Funnelchat\PlatformGate\PlatformGateServiceProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Orchestra\Testbench\TestCase;

/**
 * El OpenAPI que genera Scramble muestra `error` en las respuestas de error (0.10): las
 * `*Exception` de `components.responses` y lo que el dominio arma a mano.
 */
final class AddErrorCodeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScrambleServiceProvider::class, PlatformGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('platform-gate.domain', 'communities');
    }

    protected function defineRoutes($router): void
    {
        $router->post('api/v1/contacts', [ContactsController::class, 'store']);
        $router->get('api/v1/contacts/{id}', [ContactsController::class, 'show']);
        $router->get('api/v1/workspace', [ContactsController::class, 'workspace']);
    }

    public function test_el_openapi_muestra_error_en_las_respuestas_de_error(): void
    {
        Scramble::afterOpenApiGenerated(new AddErrorCode());

        $document = $this->app->make(Generator::class)(Scramble::configure());

        $validation = $document['components']['responses']['ValidationException']['content']['application/json']['schema'];
        $this->assertSame(['error', 'message', 'errors'], array_keys($validation['properties']));
        $this->assertSame('string', $validation['properties']['error']['type']);
        $this->assertContains('validation_failed', $this->examplesOf($validation['properties']['error']));
        $this->assertContains('error', $validation['required']);

        $notFound = $document['components']['responses']['ModelNotFoundException']['content']['application/json']['schema'];
        $this->assertContains('not_found', $this->examplesOf($notFound['properties']['error']));
        $this->assertContains('error', $notFound['required']);

        // Armada a mano con `code`: `error` lleva lo mismo.
        $workspace = $document['paths']['/v1/workspace']['get']['responses'][403]['content']['application/json']['schema'];
        $this->assertSame('error', array_key_first($workspace['properties']));
        $this->assertSame($workspace['properties']['code']['type'], $workspace['properties']['error']['type']);

        // Lo que no es error no se toca.
        $ok = $document['paths']['/v1/contacts/{id}']['get']['responses'][200]['content']['application/json']['schema'] ?? [];
        $this->assertArrayNotHasKey('error', $ok['properties'] ?? []);
    }

    /** @param array<string, mixed> $property @return list<mixed> */
    private function examplesOf(array $property): array
    {
        return [...($property['examples'] ?? []), ...(array_key_exists('example', $property) ? [$property['example']] : [])];
    }
}

final class ContactsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string']);

        return new JsonResponse(['id' => 1], 201);
    }

    public function show(string $id): JsonResponse
    {
        return new JsonResponse(['id' => User::query()->findOrFail($id)->getKey()]);
    }

    public function workspace(): JsonResponse
    {
        return response()->json(['message' => 'Esta API key no puede usar ese workspace.', 'code' => 'workspace_forbidden'], 403);
    }
}
