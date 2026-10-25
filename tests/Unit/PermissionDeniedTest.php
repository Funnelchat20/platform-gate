<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Exceptions\PermissionDenied;
use PHPUnit\Framework\TestCase;

final class PermissionDeniedTest extends TestCase
{
    public function test_con_un_permiso_del_panel_lo_nombra_como_el_panel(): void
    {
        $e = new PermissionDenied('conversations:send');

        $this->assertSame(
            'A esta API key le falta el permiso «Contactos y conversaciones → Enviar». '
            .'Agregalo desde Desarrolladores, en el panel.',
            $e->getMessage(),
        );
        $this->assertStringNotContainsString('conversations:send', $e->getMessage());
    }

    public function test_con_un_permiso_que_el_panel_no_nombra_el_mensaje_es_generico(): void
    {
        $e = new PermissionDenied('accounts:send');

        $this->assertSame(
            'A esta API key le falta un permiso que esta ruta pide. Revisá sus permisos en Desarrolladores, en el panel.',
            $e->getMessage(),
        );
        $this->assertSame(PermissionDenied::MESSAGE_GENERIC, $e->getMessage());
        $this->assertStringNotContainsString('accounts:send', $e->getMessage());
    }

    public function test_el_literal_queda_para_el_codigo_y_el_contrato_no_cambia(): void
    {
        foreach (['accounts:operate', 'accounts:send'] as $permission) {
            $e = new PermissionDenied($permission);

            $this->assertSame($permission, $e->requiredPermission);
            $this->assertSame(403, $e->status());
            $this->assertSame('permission_denied', $e->errorCode());
            $this->assertNull($e->retryAfter());
        }
    }
}
