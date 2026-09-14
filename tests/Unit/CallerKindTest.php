<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Caller\CallerKind;
use PHPUnit\Framework\TestCase;
use Stringable;

enum DomainKindEnum: string
{
    case Web = 'web';
    case ApiKey = 'api_key';
}

enum PlainKind
{
    case web;
}

final class CallerKindTest extends TestCase
{
    public function test_lee_un_string_crudo(): void
    {
        $this->assertSame(CallerKind::ApiKey, CallerKind::fromAttribute('api_key'));
        $this->assertSame(CallerKind::Web, CallerKind::fromAttribute('web'));
    }

    public function test_lee_un_backed_enum_del_dominio(): void
    {
        // Regresión del bug que encontró accounts: su modelo castea `kind` a un PHP enum
        // propio, y la versión anterior hacía `(string) $raw`, que lanza
        // `Error: Object of class … could not be converted to string` — 500 en CADA
        // request autenticado de ese dominio apenas instalara la librería.
        $this->assertSame(CallerKind::ApiKey, CallerKind::fromAttribute(DomainKindEnum::ApiKey));
        $this->assertSame(CallerKind::Web, CallerKind::fromAttribute(DomainKindEnum::Web));
    }

    public function test_lee_un_enum_sin_backing(): void
    {
        $this->assertSame(CallerKind::Web, CallerKind::fromAttribute(PlainKind::web));
    }

    public function test_lee_un_stringable(): void
    {
        $value = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'api_key';
            }
        };

        $this->assertSame(CallerKind::ApiKey, CallerKind::fromAttribute($value));
    }

    public function test_null_y_valores_no_representables_dan_null(): void
    {
        // `null` significa "no pude clasificar". El llamador NUNCA debe leerlo como "web".
        $this->assertNull(CallerKind::fromAttribute(null));
        $this->assertNull(CallerKind::fromAttribute(new \stdClass()));
        $this->assertNull(CallerKind::fromAttribute(['web']));
    }

    public function test_un_valor_desconocido_da_null_y_no_inventa_un_permiso(): void
    {
        $this->assertNull(CallerKind::fromAttribute('partner'));
    }

    public function test_un_callerkind_pasa_derecho(): void
    {
        $this->assertSame(CallerKind::ApiKey, CallerKind::fromAttribute(CallerKind::ApiKey));
    }
}
