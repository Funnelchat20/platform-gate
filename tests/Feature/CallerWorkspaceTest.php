<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\CallerKind;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * El workspace de una key (0.9, sección P del contrato `1026-workspaces-api`): sale del token,
 * llega al dominio en `Caller::workspaceId()` y en `X-Workspace-Id`, y el que manda el cliente
 * no cuenta. Una sesión web no cambia.
 */
final class CallerWorkspaceTest extends GateTestCase
{
    /** Lo que el dominio vio de `X-Workspace-Id`, en la cabecera y en `server`. */
    private ?string $seenHeader = null;

    private ?string $seenServer = null;

    private bool $reachedDomain = false;

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function gateSees(?object $token, array $headers = []): Response
    {
        $request = $this->request('POST', 'api/v1/groups', $token, $headers);

        return $this->app->make(PlatformGate::class)->handle($request, function (Request $request): Response {
            $this->reachedDomain = true;
            $this->seenHeader = $request->header('X-Workspace-Id');
            $this->seenServer = $request->server('HTTP_X_WORKSPACE_ID');

            return new Response('ok');
        });
    }

    /** @param array<string, mixed> $attributes */
    private function key(array $attributes = []): PersonalAccessToken
    {
        return $this->token(['kind' => 'api_key', 'abilities' => ['communities:write']] + $attributes);
    }

    private function createTokensTable(bool $withWorkspace): void
    {
        Schema::create('personal_access_tokens', static function (Blueprint $table) use ($withWorkspace): void {
            $table->id();
            $table->string('kind')->nullable();

            if ($withWorkspace) {
                $table->unsignedBigInteger('workspace_id')->nullable();
            }
        });
    }

    public function test_una_key_de_workspace_7_da_7(): void
    {
        $this->assertSame(200, $this->gateSees($this->key(['workspace_id' => 7]))->getStatusCode());

        $this->assertSame(CallerKind::ApiKey, $this->caller()->kind);
        $this->assertSame(7, $this->caller()->workspaceId());
    }

    public function test_el_id_que_devuelve_el_driver_como_string_tambien_vale(): void
    {
        $this->assertSame(200, $this->gateSees($this->key(['workspace_id' => '7']))->getStatusCode());

        $this->assertSame(7, $this->caller()->workspaceId());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function keysWithoutWorkspace(): iterable
    {
        yield 'con null' => [['workspace_id' => null]];
        yield 'vacío' => [['workspace_id' => '']];
        yield 'sin el atributo, y sin la columna en la tabla' => [[]];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('keysWithoutWorkspace')]
    public function test_una_key_sin_workspace_da_null(array $attributes): void
    {
        $this->assertSame(200, $this->gateSees($this->key($attributes))->getStatusCode());

        $this->assertSame(CallerKind::ApiKey, $this->caller()->kind);
        $this->assertNull($this->caller()->workspaceId());
    }

    public function test_sin_el_atributo_y_con_una_tabla_que_no_tiene_la_columna_da_null(): void
    {
        // La ventana de rollout: el dominio subió a 0.9 y accounts todavía no creó la columna.
        $this->createTokensTable(withWorkspace: false);

        $this->assertSame(200, $this->gateSees($this->key())->getStatusCode());
        $this->assertNull($this->caller()->workspaceId());
    }

    public function test_una_sesion_web_no_tiene_workspace_aunque_el_token_lo_traiga(): void
    {
        $token = $this->token(['kind' => 'web', 'abilities' => ['*'], 'workspace_id' => 7]);

        $this->assertSame(200, $this->gateSees($token)->getStatusCode());

        $this->assertSame(CallerKind::Web, $this->caller()->kind);
        $this->assertNull($this->caller()->workspaceId());
    }

    public function test_la_key_de_workspace_7_que_manda_999_llega_al_dominio_con_7(): void
    {
        $this->assertSame(200, $this->gateSees($this->key(['workspace_id' => 7]), ['X-Workspace-Id' => '999'])->getStatusCode());

        $this->assertSame('7', $this->seenHeader);
        $this->assertSame('7', $this->seenServer);
    }

    public function test_la_key_de_workspace_7_que_no_manda_header_llega_con_7(): void
    {
        $this->gateSees($this->key(['workspace_id' => 7]));

        $this->assertSame('7', $this->seenHeader);
        $this->assertSame('7', $this->seenServer);
    }

    public function test_una_cabecera_repetida_tampoco_elige(): void
    {
        $this->gateSees($this->key(['workspace_id' => 7]), ['X-Workspace-Id' => ['999', '7']]);

        $this->assertSame(['7'], $this->lastRequest?->headers->all('x-workspace-id'));
        $this->assertSame('7', $this->seenServer);
    }

    public function test_la_key_sin_workspace_que_manda_el_header_llega_sin_header(): void
    {
        $this->assertSame(200, $this->gateSees($this->key(['workspace_id' => null]), ['X-Workspace-Id' => '999'])->getStatusCode());

        $this->assertTrue($this->reachedDomain);
        $this->assertNull($this->seenHeader);
        $this->assertNull($this->seenServer);
        $this->assertFalse($this->lastRequest?->headers->has('X-Workspace-Id'));
    }

    public function test_un_token_web_que_manda_el_header_llega_con_su_header(): void
    {
        $token = $this->token(['kind' => 'web', 'abilities' => ['*'], 'workspace_id' => 7]);

        $this->gateSees($token, ['X-Workspace-Id' => '999']);

        $this->assertSame('999', $this->seenHeader);
        $this->assertSame('999', $this->seenServer);
    }

    public function test_una_sesion_del_front_que_manda_el_header_llega_con_su_header(): void
    {
        $this->gateSees(new TransientToken(), ['X-Workspace-Id' => '999']);

        $this->assertSame(CallerKind::Web, $this->caller()->kind);
        $this->assertSame('999', $this->seenHeader);
    }

    public function test_sin_token_no_se_toca_el_header(): void
    {
        // Sin token el 401 lo da `auth:sanctum` del dominio; el portero no decide nada.
        $this->gateSees(null, ['X-Workspace-Id' => '999']);

        $this->assertSame('999', $this->seenHeader);
    }

    public function test_una_key_rechazada_no_llega_al_dominio(): void
    {
        $token = $this->token(['kind' => 'api_key', 'abilities' => ['communities:read'], 'workspace_id' => 7]);

        $this->assertRejected($this->gateSees($token, ['X-Workspace-Id' => '999']), 403, 'permission_denied');
        $this->assertFalse($this->reachedDomain);
    }

    public function test_la_columna_existe_y_el_token_no_la_trae_corta(): void
    {
        // El dominio no selecciona `workspace_id` (`findToken()` con columnas fijas). Leerlo como
        // `null` mandaría una key de otro workspace al por defecto, sin ninguna señal.
        $this->createTokensTable(withWorkspace: true);

        $response = $this->gateSees($this->key(), ['X-Workspace-Id' => '999']);

        $this->assertRejected($response, 503, 'gate_unavailable');
        $this->assertSame('5', $response->headers->get('Retry-After'));
        $this->assertFalse($this->reachedDomain);
        $this->assertSame(CallerKind::Unverified, $this->caller()->kind);
        $this->assertLoggedError('no trae `workspace_id`');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableWorkspaces(): iterable
    {
        yield 'cero' => [0];
        yield 'negativo' => [-3];
        yield 'texto' => ['abc'];
        yield 'decimal' => [7.5];
        yield 'más grande que un int' => ['99999999999999999999'];
        yield 'una lista' => [[7]];
    }

    #[DataProvider('unreadableWorkspaces')]
    public function test_un_workspace_que_no_es_un_id_corta(mixed $value): void
    {
        $response = $this->gateSees($this->key(['workspace_id' => $value]), ['X-Workspace-Id' => '999']);

        $this->assertRejected($response, 503, 'gate_unavailable');
        $this->assertFalse($this->reachedDomain);
        $this->assertLoggedError('no es un id');
    }

    public function test_un_503_por_el_workspace_no_gasta_el_tope(): void
    {
        config(['platform-gate.daily_call_cap' => 1]);
        $this->app->forgetInstance(\Funnelchat\PlatformGate\Limits\DailyCallCap::class);
        $this->app->forgetScopedInstances();

        $this->assertRejected($this->gateSees($this->key(['workspace_id' => 'abc'])), 503, 'gate_unavailable');
        $this->app->forgetScopedInstances();
        $this->assertSame(200, $this->gateSees($this->key(['workspace_id' => 7]))->getStatusCode());
        $this->app->forgetScopedInstances();
        $this->assertRejected($this->gateSees($this->key(['workspace_id' => 7])), 429, 'daily_cap_exceeded');
    }
}
