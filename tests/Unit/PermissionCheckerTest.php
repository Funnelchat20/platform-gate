<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Exceptions\GateUnavailable;
use Funnelchat\PlatformGate\Exceptions\PermissionDenied;
use Funnelchat\PlatformGate\Permissions\PermissionChecker;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_con_el_modelo_de_sanctum_el_comodin_tampoco_habilita_una_key(): void
    {
        // El modelo de Sanctum no expone `getAbilities()`: la lista vive en el atributo
        // `abilities`. Ahí también `*` es un string más, no un comodín.
        $token = (new PersonalAccessToken())->forceFill(['abilities' => ['*']]);

        $checker = new PermissionChecker();

        $this->assertFalse($checker->allows($token, 'conversations:send'));
        $this->assertFalse($checker->allows($token, 'conversations:read'));
        $this->assertFalse($checker->allows($token, 'communities:operate'));
    }

    public function test_con_el_modelo_de_sanctum_compara_literal_contra_el_atributo(): void
    {
        $token = (new PersonalAccessToken())->forceFill(['abilities' => ['conversations:send']]);

        $checker = new PermissionChecker();

        $this->assertTrue($checker->allows($token, 'conversations:send'));
        $this->assertTrue($checker->allows($token, 'conversations:write'));
        $this->assertTrue($checker->allows($token, 'conversations:read'));
        $this->assertFalse($checker->allows($token, 'conversations:operate'));
    }

    public function test_nunca_le_pregunta_a_can_del_token(): void
    {
        // `can()` es la interpretación de Sanctum, no la del portero. Un token que diría
        // que sí a todo no cambia nada: manda la lista.
        $token = $this->tokenWithoutGetAbilities(['conversations:read'], canAnswers: true);

        $checker = new PermissionChecker();

        $this->assertTrue($checker->allows($token, 'conversations:read'));
        $this->assertFalse($checker->allows($token, 'conversations:write'));
    }

    public function test_lee_el_atributo_aunque_llegue_como_json_crudo(): void
    {
        // Un modelo del dominio que redefine `$casts` pierde el cast de `abilities` y
        // el atributo llega como el texto de la columna. Sigue siendo legible.
        $checker = new PermissionChecker();

        $this->assertTrue($checker->allows($this->tokenWithoutGetAbilities('["conversations:write"]'), 'conversations:read'));
        $this->assertFalse($checker->allows($this->tokenWithoutGetAbilities('["*"]'), 'conversations:read'));
    }

    public function test_la_lista_que_ve_el_dominio_sale_del_mismo_atributo(): void
    {
        $token = (new PersonalAccessToken())->forceFill(['abilities' => ['communities:read', 'communities:operate']]);

        $this->assertSame(['communities:read', 'communities:operate'], (new PermissionChecker())->abilitiesOf($token));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableAbilities(): iterable
    {
        yield 'sin el atributo (el dominio no selecciona la columna)' => [null];
        yield 'texto que no es json' => ['conversations:read'];
        yield 'json que no es una lista' => ['"conversations:read"'];
        yield 'un escalar' => [42];
    }

    #[DataProvider('unreadableAbilities')]
    public function test_si_no_puede_leer_las_abilities_corta_en_vez_de_decidir(mixed $raw): void
    {
        // "No pude evaluar" no es "evalué y está denegado" (P7): 503 reintentable, nunca
        // un 403 que el cliente lea como terminal — y nunca dejar pasar.
        $this->assertUnavailable(fn () => (new PermissionChecker())->allows(
            $this->tokenWithoutGetAbilities($raw, canAnswers: true),
            'conversations:read',
        ));
    }

    public function test_si_leer_el_atributo_tira_corta_en_vez_de_romper(): void
    {
        $token = new class implements HasAbilities
        {
            public function __get(string $name): mixed
            {
                throw new \LogicException("missing attribute [{$name}]");
            }

            public function __isset(string $name): bool
            {
                throw new \LogicException("missing attribute [{$name}]");
            }

            public function can($ability): bool
            {
                return true;
            }

            public function cant($ability): bool
            {
                return false;
            }
        };

        $this->assertUnavailable(fn () => (new PermissionChecker())->assert($token, 'conversations:read'));
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

    private function assertUnavailable(callable $evaluate): void
    {
        try {
            $evaluate();
        } catch (GateUnavailable $e) {
            $this->assertSame('unreadable_abilities', $e->stage);
            $this->assertSame(503, $e->status());

            return;
        }

        $this->fail('Se esperaba GateUnavailable: sin la lista de permisos no se puede evaluar.');
    }

    /**
     * Un token como el de Sanctum: la lista en el atributo `abilities`, sin `getAbilities()`.
     */
    private function tokenWithoutGetAbilities(mixed $abilities, bool $canAnswers = false): HasAbilities
    {
        return new class($abilities, $canAnswers) implements HasAbilities
        {
            public function __construct(public mixed $abilities, private bool $canAnswers)
            {
            }

            public function can($ability): bool
            {
                return $this->canAnswers;
            }

            public function cant($ability): bool
            {
                return ! $this->can($ability);
            }
        };
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
