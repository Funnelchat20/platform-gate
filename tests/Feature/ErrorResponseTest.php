<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Closure;
use Funnelchat\PlatformGate\Http\ErrorCode;
use Funnelchat\PlatformGate\Http\ErrorResponse;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\PlatformGateServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\SanctumServiceProvider;

/**
 * El formato de error de las llamadas con key (0.10, contrato `1113-errores-y-limites`): con
 * key, `error` en todo; sin key —el panel—, exactamente lo de antes.
 *
 * Con Sanctum cargado, como en los dominios: el llamador sale del guard `sanctum`.
 */
final class ErrorResponseTest extends GateTestCase
{
    protected function getPackageProviders($app): array
    {
        return [SanctumServiceProvider::class, PlatformGateServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // El 404 de Laravel con debug trae la traza: lo que se compara es lo de producción.
        $app['config']->set('app.debug', false);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(PlatformGate::class)->get('api/v1/contacts/{id}', static function (): never {
            throw (new ModelNotFoundException())->setModel(User::class, [5]);
        });

        $router->middleware(PlatformGate::class)->post('api/v1/contacts', static function (Request $request): array {
            return Validator::make($request->all(), ['name' => 'required'])->validate();
        });

        $router->middleware(PlatformGate::class)->get('api/v1/forbidden', static function (): never {
            throw new AuthorizationException();
        });

        // El `throttle` de Laravel corre antes que el portero (prioridad de middlewares): el
        // portero no llega a clasificar.
        $router->middleware([ThrottleBeforeGate::class, PlatformGate::class])->get('api/v1/limited', static fn () => 'ok');

        $router->middleware(PlatformGate::class)->get('api/v1/workspace', static fn () => response()->json(
            ['message' => 'Esta API key no puede usar ese workspace.', 'code' => 'workspace_forbidden'],
            403,
        ));

        $router->middleware(PlatformGate::class)->delete('api/v1/contacts/{id}', static fn () => response()->json(
            ['message' => 'El contacto está en una campaña activa.'],
            409,
        ));
    }

    private function hook(): void
    {
        ErrorResponse::register(new Exceptions($this->app->make(ExceptionHandler::class)));
    }

    private function actingWith(string $kind): void
    {
        $user = (new class extends User
        {
            use HasApiTokens;
        })->forceFill(['id' => 7]);

        $user->withAccessToken($this->token(['kind' => $kind, 'abilities' => ['communities:write']]));

        $this->actingAs($user, 'sanctum');
    }

    public function test_con_key_el_404_422_403_y_429_traen_error(): void
    {
        $this->hook();
        $this->actingWith('api_key');

        // Primero, mientras el portero no clasificó nada en este proceso: el 429 sale antes de
        // que corra, y la key se reconoce por el token que ya resolvió el guard.
        $this->getJson('api/v1/limited')
            ->assertStatus(429)
            ->assertExactJson(['error' => 'rate_limited', 'message' => ErrorCode::message('rate_limited')])
            ->assertHeader('Retry-After', '30');

        // Sin el nombre de la clase del modelo, que es lo que Laravel pone en el texto.
        $this->getJson('api/v1/contacts/5')
            ->assertStatus(404)
            ->assertExactJson(['error' => 'not_found', 'message' => ErrorCode::message('not_found')]);

        $this->postJson('api/v1/contacts', [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['error', 'message', 'errors' => ['name']]);

        $this->getJson('api/v1/forbidden')
            ->assertStatus(403)
            ->assertExactJson(['error' => 'forbidden', 'message' => ErrorCode::message('forbidden')]);
    }

    public function test_una_ruta_que_no_existe_se_decide_por_el_kind_del_bearer(): void
    {
        // Sin `actingAs` ni portero: lo único que hay es el `Bearer`. Sin pedir JSON, además: a
        // una key no se le contesta con HTML.
        $this->hook();
        $this->tokensTable();

        $this->get('api/v1/no-existe', ['Authorization' => 'Bearer '.$this->storedToken('api_key', 'texto-de-la-key')])
            ->assertStatus(404)
            ->assertExactJson(['error' => 'not_found', 'message' => ErrorCode::message('not_found')]);

        // El token `web` del panel tiene la misma forma: lo que decide es `kind`.
        $this->getJson('api/v1/no-existe', ['Authorization' => 'Bearer '.$this->storedToken('web', 'texto-del-panel')])
            ->assertStatus(404)
            ->assertJsonMissingPath('error');
    }

    public function test_lo_que_el_dominio_arma_a_mano_gana_error_sin_perder_nada(): void
    {
        $this->actingWith('api_key');

        $this->getJson('api/v1/workspace')
            ->assertStatus(403)
            ->assertExactJson([
                'error' => 'workspace_forbidden',
                'message' => 'Esta API key no puede usar ese workspace.',
                'code' => 'workspace_forbidden',
            ]);

        $this->deleteJson('api/v1/contacts/5')
            ->assertStatus(409)
            ->assertExactJson(['error' => 'conflict', 'message' => 'El contacto está en una campaña activa.']);
    }

    public function test_sin_key_todo_igual_que_antes(): void
    {
        $this->actingWith('web');

        $before = $this->panelResponses();
        $this->hook();
        $after = $this->panelResponses();

        $this->assertSame($before, $after);
        $this->assertSame([429, 404, 422, 403, 403, 404], array_column($after, 0));
        $this->assertStringNotContainsString('"error"', implode('', array_column($after, 1)));
    }

    /** @return array<string, array{0: int, 1: string, 2: ?string}> */
    private function panelResponses(): array
    {
        $calls = [
            'GET api/v1/limited' => fn () => $this->getJson('api/v1/limited'),
            'GET api/v1/contacts/5' => fn () => $this->getJson('api/v1/contacts/5'),
            'POST api/v1/contacts' => fn () => $this->postJson('api/v1/contacts', []),
            'GET api/v1/forbidden' => fn () => $this->getJson('api/v1/forbidden'),
            'GET api/v1/workspace' => fn () => $this->getJson('api/v1/workspace'),
            'GET api/v1/no-existe' => fn () => $this->getJson('api/v1/no-existe'),
        ];

        return array_map(static function (Closure $call): array {
            $response = $call();

            return [$response->getStatusCode(), (string) $response->getContent(), $response->headers->get('Retry-After')];
        }, $calls);
    }

    /** Un token guardado de verdad, como lo busca Sanctum: `{id}|{texto}`. */
    private function storedToken(string $kind, string $plain): string
    {
        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => User::class,
            'tokenable_id' => 7,
            'name' => 'integración',
            'token' => hash('sha256', $plain),
            'abilities' => ['communities:read'],
            'kind' => $kind,
        ]);

        return $token->getKey().'|'.$plain;
    }

    private function tokensTable(): void
    {
        Schema::create('personal_access_tokens', static function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('kind')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}

final class ThrottleBeforeGate
{
    public function handle(Request $request, Closure $next): never
    {
        throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30, 'X-RateLimit-Limit' => 60]);
    }
}
