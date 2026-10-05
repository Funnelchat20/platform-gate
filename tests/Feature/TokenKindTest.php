<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\CallerKind;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\DataProvider;

final class TokenKindTest extends GateTestCase
{
    /** @return iterable<string, array{string}> */
    public static function unknownKinds(): iterable
    {
        yield 'un valor que la librería no conoce' => ['partner'];
        yield 'otro valor que la librería no conoce' => ['internal'];
        yield 'el estado del portero, que no es un valor de la columna' => ['unverified'];
        yield 'mayúsculas' => ['WEB'];
        yield 'vacío' => [''];
    }

    #[DataProvider('unknownKinds')]
    public function test_un_kind_desconocido_no_es_web_y_corta(string $kind): void
    {
        // Sólo `api_key` y `web` son valores de token. Cualquier otro es un llamador que el
        // portero no sabe clasificar: "no pude evaluar", 503 reintentable y error en el log.
        // Ni `web` (que no se chequea) ni una key inventada.
        $token = $this->token(['kind' => $kind, 'abilities' => ['*']]);

        $response = $this->send('POST', 'api/v1/groups', $token);

        $this->assertRejected($response, 503, 'gate_unavailable');
        $this->assertSame('5', $response->headers->get('Retry-After'));
        $this->assertSame(CallerKind::Unverified, $this->caller()->kind);
        $this->assertLoggedError('`kind` desconocido');
    }

    public function test_web_sigue_siendo_web(): void
    {
        $token = $this->token(['kind' => 'web', 'abilities' => ['*']]);

        $this->assertSame(200, $this->send('POST', 'api/v1/groups', $token)->getStatusCode());
        $this->assertSame(CallerKind::Web, $this->caller()->kind);
    }

    public function test_una_sesion_del_front_sigue_siendo_web(): void
    {
        $this->assertSame(200, $this->send('POST', 'api/v1/groups', new TransientToken())->getStatusCode());
        $this->assertSame(CallerKind::Web, $this->caller()->kind);
    }

    public function test_api_key_sigue_siendo_maquina(): void
    {
        $token = $this->token(['kind' => 'api_key', 'abilities' => ['communities:write']]);

        $this->assertSame(200, $this->send('POST', 'api/v1/groups', $token)->getStatusCode());
        $this->assertSame(CallerKind::ApiKey, $this->caller()->kind);
    }

    public function test_sin_token_sigue_sin_verificar(): void
    {
        $this->assertSame(200, $this->send('GET', 'api/v1/groups', null)->getStatusCode());
        $this->assertSame(CallerKind::Unverified, $this->caller()->kind);
    }
}
