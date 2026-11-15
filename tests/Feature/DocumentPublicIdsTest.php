<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\ScrambleServiceProvider;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Funnelchat\PlatformGate\OpenApi\DocumentPublicIds;
use Funnelchat\PlatformGate\PlatformGateServiceProvider;
use Funnelchat\PlatformGate\PublicIds\PublicIds;
use Funnelchat\PlatformGate\PublicIds\PublicIdSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use Orchestra\Testbench\TestCase;

/**
 * El OpenAPI muestra los ids como texto con prefijo y ejemplo cuando el ajuste está prendido, y
 * queda como antes cuando está apagado (0.12.0).
 */
final class DocumentPublicIdsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScrambleServiceProvider::class, PlatformGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('platform-gate.domain', 'communities');
        $app['config']->set('platform-gate.public_ids.keys', 'A=000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f');
        $app['config']->set('platform-gate.public_ids.document', ['PublicIdContactResource.id' => 'contact']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('api/v1/contacts/{contact}', [PublicIdContactsController::class, 'show'])->middleware('public-ids:contact');
        $router->get('api/v1/devices/{id}', [PublicIdContactsController::class, 'device'])->middleware('public-ids:id=device');
        $router->get('api/v1/plain/{id}', [PublicIdContactsController::class, 'device']);
        $router->post('api/v1/messages', [PublicIdContactsController::class, 'store']);
    }

    public function test_prendido_los_ids_son_texto_con_prefijo_y_ejemplo(): void
    {
        $document = $this->document(enabled: true);

        $contact = $this->pathParameter($document, '/v1/contacts/{contact}', 'contact');
        $this->assertPublicId('ctc', $contact);
        // Tiene la forma, pero no es un id de verdad.
        $this->assertNull(PublicIds::decode($this->exampleOf($contact), 'contact'));

        $this->assertPublicId('dev', $this->pathParameter($document, '/v1/devices/{id}', 'id'));

        // Lo que la ruta no declara no se decodifica, y no se documenta como id.
        $this->assertSame('integer', $this->pathParameter($document, '/v1/plain/{id}', 'id')['type']);

        $resource = $document['components']['schemas']['PublicIdContactResource']['properties'];
        $this->assertPublicId('ctc', $resource['id']);
        $this->assertPublicId('dev', $resource['device_id'], nullable: true);

        $body = $document['paths']['/v1/messages']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $this->assertPublicId('dev', $body['device_id']);
        $this->assertPublicId('tag', $body['tag_ids']['items']);
        $this->assertSame('string', $body['name']['type']);
        $this->assertStringNotContainsString('Id opaco', $body['name']['description'] ?? '');
    }

    /**
     * `string`, con un ejemplo del prefijo y el prefijo en la descripción. El `pattern`, sólo
     * donde Scramble lo sabe escribir (0.13).
     *
     * @param array<string, mixed> $schema
     */
    private function assertPublicId(string $prefix, array $schema, bool $nullable = false): void
    {
        $this->assertSame($nullable ? ['string', 'null'] : 'string', $schema['type']);
        $this->assertMatchesRegularExpression("/^{$prefix}_A[A-Za-z0-9]{22}$/", $this->exampleOf($schema));
        $this->assertStringContainsString("`{$prefix}_`", $schema['description']);

        if (method_exists(StringType::class, 'pattern')) {
            $this->assertSame("^{$prefix}_[A-Za-z0-9]{23}$", $schema['pattern']);
        }
    }

    /** @param array<string, mixed> $schema */
    private function exampleOf(array $schema): string
    {
        return (string) ($schema['example'] ?? $schema['examples'][0] ?? '');
    }

    public function test_apagado_queda_como_antes(): void
    {
        $document = $this->document(enabled: false);

        $this->assertSame('integer', $this->pathParameter($document, '/v1/contacts/{contact}', 'contact')['type']);

        // Lo que deduce Scramble de `forResponse()` vuelve a ser un entero.
        $resource = $document['components']['schemas']['PublicIdContactResource']['properties'];
        $this->assertSame('integer', $resource['id']['type']);
        $this->assertSame(['integer', 'null'], $resource['device_id']['type']);

        $body = $document['paths']['/v1/messages']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $this->assertSame('integer', $body['device_id']['type']);
        $this->assertArrayNotHasKey('pattern', $body['device_id']);
        $this->assertStringNotContainsString('Id opaco', $body['device_id']['description'] ?? '');
    }

    /** @return array<string, mixed> */
    private function document(bool $enabled): array
    {
        $this->app['config']->set('platform-gate.public_ids.enabled', $enabled);
        $this->app->forgetInstance(PublicIdSettings::class);

        Scramble::afterOpenApiGenerated(new DocumentPublicIds());

        return $this->app->make(Generator::class)(Scramble::configure());
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function pathParameter(array $document, string $path, string $name): array
    {
        foreach ($document['paths'][$path]['get']['parameters'] as $parameter) {
            if ($parameter['name'] === $name && $parameter['in'] === 'path') {
                // La descripción y el ejemplo pueden quedar en el parámetro en vez del esquema.
                return $parameter['schema'] + array_filter([
                    'example' => $parameter['example'] ?? null,
                    'description' => $parameter['description'] ?? null,
                ]);
            }
        }

        $this->fail("No está el parámetro `{$name}` de `{$path}`.");
    }
}

final class PublicIdContactsController extends Controller
{
    public function show(int $contact): PublicIdContactResource
    {
        return new PublicIdContactResource(['id' => $contact, 'device_id' => 3]);
    }

    public function device(int $id): array
    {
        return ['ok' => true];
    }

    public function store(Request $request): array
    {
        $request->validate([
            'device_id' => 'required|integer',
            'tag_ids' => 'array',
            'tag_ids.*' => 'integer',
            'name' => 'required|string',
        ]);

        return ['ok' => true];
    }
}

/** @property array{id: int, device_id: ?int} $resource */
final class PublicIdContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => PublicIds::forResponse('contact', $this->resource['id']),
            'device_id' => PublicIds::forResponseOrNull('device', $this->resource['device_id']),
        ];
    }
}
