<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Http\ErrorCode;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\PlatformGateServiceProvider;
use Funnelchat\PlatformGate\PublicIds\PublicIds;
use Funnelchat\PlatformGate\PublicIds\PublicIdSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Los ids públicos de punta a punta (0.12.0, contrato `1411-ids-opacos`), por el kernel HTTP:
 * la ruta, el cuerpo, la respuesta y el webhook, con el ajuste apagado y prendido, con key y con
 * el panel, y la ventana de los enteros.
 */
final class PublicIdsTest extends GateTestCase
{
    /** Las claves de prueba de `tests/vectors/public-ids.json`. */
    private const KEYS = 'A=000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    /** `contact` 5 y `device` 3 con la clave A de los vectores. */
    private string $contact5;

    private string $device3;

    protected function getPackageProviders($app): array
    {
        return [SanctumServiceProvider::class, PlatformGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.debug', false);
        $app['config']->set('platform-gate.public_ids.keys', self::KEYS);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware([PlatformGate::class, 'public-ids:contact'])
            ->get('api/v1/contacts/{contact}', static fn (mixed $contact): array => [
                'received' => $contact,
                'id' => PublicIds::forResponse('contact', 5),
                'device_id' => PublicIds::forResponse('device', 3),
            ]);

        // Con el binding de Laravel en la ruta: tiene que ver el entero, no `ctc_…`.
        $router->bind('bound', static fn (mixed $value): array => is_int($value)
            ? ['id' => $value]
            : throw (new ModelNotFoundException())->setModel(User::class, [$value]));

        $router->middleware([SubstituteBindings::class, PlatformGate::class, 'public-ids:bound=contact'])
            ->get('api/v1/bound/{bound}', static fn (Request $request): array => $request->route('bound'));

        $router->middleware(PlatformGate::class)
            ->post('api/v1/messages', static fn (Request $request): array => [
                'device_id' => PublicIds::fromRequest('device', $request->input('device_id')),
            ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact5 = $this->encodeWithKeys('contact', 5);
        $this->device3 = $this->encodeWithKeys('device', 3);
    }

    public function test_apagado_nada_cambia(): void
    {
        $this->actingWith('api_key');

        $response = $this->getJson('api/v1/contacts/5')
            ->assertOk()
            ->assertExactJson(['received' => '5', 'id' => 5, 'device_id' => 3]);

        $this->assertFalse($response->headers->has('Deprecation'));
        $this->assertFalse($response->headers->has('Sunset'));

        // Un `ctc_…` no se toca: llega como llegó, igual que antes de 0.12.0.
        $this->getJson("api/v1/contacts/{$this->contact5}")->assertOk()->assertJsonPath('received', $this->contact5);

        $this->postJson('api/v1/messages', ['device_id' => 3])->assertExactJson(['device_id' => 3]);
        $this->assertSame(3, PublicIds::forWebhook('device', 3));
    }

    public function test_prendido_una_key_ve_y_manda_ids_opacos(): void
    {
        $this->enable();
        $this->actingWith('api_key');

        $this->getJson("api/v1/contacts/{$this->contact5}")
            ->assertOk()
            ->assertExactJson(['received' => 5, 'id' => $this->contact5, 'device_id' => $this->device3]);

        // El binding de modelos ve el entero: `ResolvePublicIds` corre antes que `SubstituteBindings`.
        $this->getJson("api/v1/bound/{$this->contact5}")->assertOk()->assertExactJson(['id' => 5]);

        $this->postJson('api/v1/messages', ['device_id' => $this->device3])->assertExactJson(['device_id' => 3]);

        // Un id que no es de ese tipo en el cuerpo es uno que no existe: 0, no `null`.
        $this->postJson('api/v1/messages', ['device_id' => $this->contact5])
            ->assertExactJson(['device_id' => PublicIds::NONEXISTENT]);

        // El webhook no tiene llamador: opaco igual.
        $this->assertSame($this->device3, PublicIds::forWebhook('device', 3));
    }

    public function test_prendido_un_id_invalido_en_la_ruta_es_404_not_found(): void
    {
        $this->enable();
        $this->actingWith('api_key');

        $altered = substr($this->contact5, 0, -1).($this->contact5[-1] === 'A' ? 'B' : 'A');

        foreach ([$this->device3, $altered, 'ctc_C'.substr($this->contact5, 5), '5', 'abc'] as $invalid) {
            $response = $this->getJson("api/v1/contacts/{$invalid}")
                ->assertNotFound()
                ->assertExactJson(['error' => 'not_found', 'message' => ErrorCode::message('not_found')]);

            $this->assertTrue($response->headers->has('X-Request-Id'), $invalid);
        }
    }

    public function test_prendido_el_panel_sigue_con_enteros(): void
    {
        $this->enable();
        $this->actingWith('web');

        $this->getJson('api/v1/contacts/5')->assertOk()->assertExactJson(['received' => '5', 'id' => 5, 'device_id' => 3]);
        $this->postJson('api/v1/messages', ['device_id' => 3])->assertExactJson(['device_id' => 3]);
    }

    public function test_un_entero_se_acepta_mientras_rige_la_ventana_con_deprecation_y_sunset(): void
    {
        $this->enable(['accept_legacy_until' => '2999-01-01']);
        $this->actingWith('api_key');

        $response = $this->getJson('api/v1/contacts/5')->assertOk()->assertJsonPath('received', 5);

        $this->assertMatchesRegularExpression('/^@\d+$/', (string) $response->headers->get('Deprecation'));
        $this->assertSame(gmdate('D, d M Y H:i:s \G\M\T', gmmktime(0, 0, 0, 1, 1, 2999)), $response->headers->get('Sunset'));

        // En el cuerpo también; la respuesta la sigue diciendo en opaco.
        $body = $this->postJson('api/v1/messages', ['device_id' => '3'])->assertExactJson(['device_id' => 3]);
        $this->assertTrue($body->headers->has('Deprecation'));
        $this->assertTrue($body->headers->has('Sunset'));

        // Con un id opaco, nada de eso.
        $opaque = $this->getJson("api/v1/contacts/{$this->contact5}")->assertOk();
        $this->assertFalse($opaque->headers->has('Deprecation'));
    }

    /** @return iterable<string, array{?string}> */
    public static function closedWindows(): iterable
    {
        yield 'la fecha ya pasó' => ['2000-01-01'];
        yield 'sin fecha' => [null];
    }

    #[DataProvider('closedWindows')]
    public function test_un_entero_se_rechaza_cuando_la_ventana_paso_o_no_existe(?string $until): void
    {
        $this->enable(['accept_legacy_until' => $until]);
        $this->actingWith('api_key');

        $response = $this->getJson('api/v1/contacts/5')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->assertFalse($response->headers->has('Deprecation'));

        $this->postJson('api/v1/messages', ['device_id' => 3])->assertExactJson(['device_id' => PublicIds::NONEXISTENT]);
    }

    public function test_prendido_con_la_configuracion_ilegible_corta_las_keys_y_no_al_panel(): void
    {
        $this->enable(['keys' => 'A=no-es-una-clave']);

        $this->actingWith('api_key');
        $this->getJson('api/v1/contacts/5')->assertStatus(500)->assertJsonPath('error', 'gate_misconfigured');
        $this->postJson('api/v1/messages', ['device_id' => 3])->assertStatus(500)->assertJsonPath('error', 'gate_misconfigured');
        $this->assertNeverLogged('no-es-una-clave');

        $this->actingWith('web');
        $this->getJson('api/v1/contacts/5')->assertOk()->assertJsonPath('id', 5);
    }

    /** @param array<string, mixed> $settings */
    private function enable(array $settings = []): void
    {
        $this->app['config']->set('platform-gate.public_ids', ['enabled' => true, 'keys' => self::KEYS, ...$settings]);
        $this->app->forgetInstance(PublicIdSettings::class);
    }

    private function encodeWithKeys(string $type, int $id): string
    {
        return PublicIdSettings::fromConfig(['keys' => self::KEYS])->codec()->encode($type, $id);
    }

    private function actingWith(string $kind): void
    {
        $user = (new class extends User
        {
            use HasApiTokens;
        })->forceFill(['id' => 7]);

        $user->withAccessToken($this->token(['kind' => $kind, 'abilities' => ['communities:write']]));

        // Lo del pedido anterior (quién llamó) no sigue en el siguiente, como en producción, donde
        // cada pedido empieza con los `scoped` de cero.
        $this->app->forgetScopedInstances();

        $this->actingAs($user, 'sanctum');
    }
}
