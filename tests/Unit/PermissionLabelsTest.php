<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Permissions\PermissionLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermissionLabelsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function panel(): iterable
    {
        // La tabla del panel, fila por fila: si una casilla cambia de nombre, esto lo marca.
        yield 'conversations:read' => ['conversations:read', 'Contactos y conversaciones → Ver'];
        yield 'conversations:write' => ['conversations:write', 'Contactos y conversaciones → Editar'];
        yield 'conversations:send' => ['conversations:send', 'Contactos y conversaciones → Enviar'];
        yield 'communities:read' => ['communities:read', 'Grupos y campañas → Ver'];
        yield 'communities:write' => ['communities:write', 'Grupos y campañas → Editar'];
        yield 'communities:send' => ['communities:send', 'Grupos y campañas → Enviar'];
        yield 'communities:operate' => ['communities:operate', 'Grupos y campañas → Administrar grupos'];
        yield 'accounts:read' => ['accounts:read', 'Números de WhatsApp → Ver'];
        yield 'accounts:write' => ['accounts:write', 'Números de WhatsApp → Editar'];
        yield 'accounts:operate' => ['accounts:operate', 'Números de WhatsApp → Crear, conectar y borrar'];
        yield 'webhooks:read' => ['webhooks:read', 'Avisos (webhooks) → Ver'];
        yield 'webhooks:write' => ['webhooks:write', 'Avisos (webhooks) → Administrar'];
    }

    #[DataProvider('panel')]
    public function test_nombra_el_permiso_como_el_panel(string $permission, string $label): void
    {
        $this->assertSame($label, PermissionLabels::for($permission));
    }

    /** @return iterable<string, array{string}> */
    public static function unknown(): iterable
    {
        // Permisos que el panel no ofrece: no se inventa un nombre.
        yield 'accounts no envía' => ['accounts:send'];
        yield 'webhooks no envía' => ['webhooks:send'];
        yield 'conversations no opera' => ['conversations:operate'];
        yield 'dominio fuera de la tabla' => ['wha-link:read'];
        yield 'el comodín de Sanctum' => ['*'];
        yield 'vacío' => [''];

        // El literal se busca tal cual.
        yield 'mayúsculas' => ['Accounts:Operate'];
        yield 'con espacios' => [' accounts:operate'];
        yield 'sin grado' => ['accounts'];
    }

    #[DataProvider('unknown')]
    public function test_lo_que_el_panel_no_nombra_es_null(string $permission): void
    {
        $this->assertNull(PermissionLabels::for($permission));
    }
}
