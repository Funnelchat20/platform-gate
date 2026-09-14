<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Exceptions\PermissionDenied;
use Funnelchat\PlatformGate\Permissions\PermissionChecker;
use Laravel\Sanctum\Contracts\HasAbilities;
use PHPUnit\Framework\TestCase;

final class PermissionCheckerTest extends TestCase
{
    public function test_send_no_lo_habilita_write(): void
    {
        // Es la razón entera por la que existe el tercer grado (D10): escribir un
        // contacto y mandarle un mensaje a una persona real no son el mismo riesgo.
        $token = $this->tokenWith(['conversations:write']);

        $this->assertFalse((new PermissionChecker())->allows($token, 'conversations:send'));
    }

    public function test_send_habilita_write_y_read(): void
    {
        $token = $this->tokenWith(['conversations:send']);
        $checker = new PermissionChecker();

        $this->assertTrue($checker->allows($token, 'conversations:write'));
        $this->assertTrue($checker->allows($token, 'conversations:read'));
    }

    public function test_write_habilita_read_pero_no_al_reves(): void
    {
        $checker = new PermissionChecker();

        $this->assertTrue($checker->allows($this->tokenWith(['conversations:write']), 'conversations:read'));
        $this->assertFalse($checker->allows($this->tokenWith(['conversations:read']), 'conversations:write'));
    }

    public function test_un_permiso_de_otro_dominio_no_sirve(): void
    {
        $token = $this->tokenWith(['communities:send']);

        $this->assertFalse((new PermissionChecker())->allows($token, 'conversations:send'));
    }

    public function test_operate_es_un_eje_aparte_y_no_un_peldano(): void
    {
        $checker = new PermissionChecker();

        // Quien puede mandar un mensaje NO puede reestructurarle los grupos a la cuenta…
        $this->assertFalse($checker->allows($this->tokenWith(['communities:send']), 'communities:operate'));
        $this->assertFalse($checker->allows($this->tokenWith(['communities:write']), 'communities:operate'));

        // …y quien puede operar la sesión no obtiene envío de regalo.
        $this->assertFalse($checker->allows($this->tokenWith(['communities:operate']), 'communities:send'));
        $this->assertFalse($checker->allows($this->tokenWith(['communities:operate']), 'communities:write'));

        // Se satisface a sí mismo, y sólo a sí mismo.
        $this->assertTrue($checker->allows($this->tokenWith(['communities:operate']), 'communities:operate'));
    }

    public function test_el_comodin_no_habilita_una_key(): void
    {
        // `["*"]` es lo que lleva un token WEB. Si una key se cuela con el comodín, no
        // puede pasar por encima de los diez permisos ni del tercer grado.
        $token = $this->tokenWith(['*']);

        $checker = new PermissionChecker();

        $this->assertFalse($checker->allows($token, 'conversations:send'));
        $this->assertFalse($checker->allows($token, 'conversations:read'));
    }

    public function test_sin_el_permiso_tira_denied_con_el_que_faltaba(): void
    {
        $this->expectException(PermissionDenied::class);

        try {
            (new PermissionChecker())->assert($this->tokenWith(['conversations:read']), 'conversations:send');
        } catch (PermissionDenied $e) {
            $this->assertSame('conversations:send', $e->requiredPermission);
            $this->assertSame(403, $e->status());
            throw $e;
        }
    }

    /** @param string[] $abilities */
    private function tokenWith(array $abilities): HasAbilities
    {
        return new class($abilities) implements HasAbilities
        {
            /** @param string[] $abilities */
            public function __construct(private array $abilities)
            {
            }

            public function can($ability): bool
            {
                return in_array($ability, $this->abilities, true);
            }

            public function cant($ability): bool
            {
                return ! $this->can($ability);
            }

            /** @return string[] */
            public function getAbilities(): array
            {
                return $this->abilities;
            }
        };
    }
}
