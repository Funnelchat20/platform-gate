<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Caller\Caller;
use PHPUnit\Framework\TestCase;

final class CallerTest extends TestCase
{
    public function test_una_key_lleva_su_workspace_y_lo_conserva_al_ir_a_un_job(): void
    {
        $caller = Caller::apiKey('key_abc', 4, true, ['communities:write'], 7);

        $this->assertSame(7, $caller->workspaceId());
        $this->assertSame(7, $caller->toArray()['workspace_id']);

        $back = Caller::fromArray($caller->toArray());

        $this->assertEquals($caller, $back);
        $this->assertSame(7, $back->workspaceId());
    }

    public function test_una_key_sin_workspace_va_y_vuelve_con_null(): void
    {
        $caller = Caller::apiKey('key_abc', 4, true, ['communities:write']);

        $this->assertNull($caller->workspaceId());
        $this->assertArrayHasKey('workspace_id', $caller->toArray());
        $this->assertNull(Caller::fromArray($caller->toArray())->workspaceId());
    }

    public function test_un_job_encolado_con_0_8_se_lee_con_null(): void
    {
        // Lo que dejaba `toArray()` en 0.8.x: sin `workspace_id`. Un job encolado antes de subir
        // tiene que poder leerse después, y es del por defecto.
        $caller = Caller::fromArray([
            'kind' => 'api_key',
            'key_id' => 'key_abc',
            'account_id' => 4,
            'account_is_owner' => true,
            'permissions' => ['communities:write'],
        ]);

        $this->assertNull($caller->workspaceId());
        $this->assertSame('key_abc', $caller->keyId);
        $this->assertSame(['communities:write'], $caller->permissions);
    }

    public function test_lo_que_no_es_key_no_tiene_workspace(): void
    {
        $this->assertNull(Caller::web(4, true)->workspaceId());
        $this->assertNull(Caller::unverified()->workspaceId());
        $this->assertNull(Caller::web(4, true)->toArray()['workspace_id']);
    }

    public function test_un_array_de_web_con_workspace_se_lee_sin_workspace(): void
    {
        // Un array armado a mano o adulterado no le da workspace a lo que no es key.
        $web = Caller::fromArray(['kind' => 'web', 'account_id' => 4, 'account_is_owner' => true, 'workspace_id' => 7]);
        $unverified = Caller::fromArray(['kind' => 'unverified', 'workspace_id' => 7]);

        $this->assertNull($web->workspaceId());
        $this->assertNull($unverified->workspaceId());
    }
}
