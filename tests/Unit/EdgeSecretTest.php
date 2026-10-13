<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Edge\EdgeSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EdgeSecretTest extends TestCase
{
    private const CURRENT = 'actual-3f9a1c7e5b2d4f6081a3c5e7b9d1f3a5';

    private const NEXT = 'siguiente-8e2b4d6f0a1c3e5b7d9f1a3c5e7b9d1f';

    public function test_valen_el_actual_y_el_siguiente(): void
    {
        $secret = new EdgeSecret(['actual' => self::CURRENT, 'siguiente' => self::NEXT]);

        $this->assertTrue($secret->matches(self::CURRENT));
        $this->assertTrue($secret->matches(self::NEXT));
    }

    /** @return iterable<string, array{?string}> */
    public static function notTheSecret(): iterable
    {
        yield 'ausente' => [null];
        yield 'vacío' => [''];
        yield 'espacios' => ['   '];
        yield 'otro valor' => ['cualquier-otro-valor-7c1e3a5b9d2f4e6a8b0c'];
        yield 'el actual con un caracter de más' => [self::CURRENT.'x'];
        yield 'el actual con uno de menos' => [substr(self::CURRENT, 0, -1)];
        yield 'el actual en mayúsculas' => [strtoupper(self::CURRENT)];
        yield 'los dos pegados' => [self::CURRENT.self::NEXT];
    }

    #[DataProvider('notTheSecret')]
    public function test_lo_demas_no_vale(?string $presented): void
    {
        $this->assertFalse((new EdgeSecret(['actual' => self::CURRENT, 'siguiente' => self::NEXT]))->matches($presented));
    }

    public function test_sin_secretos_nada_vale_ni_siquiera_vacio(): void
    {
        $secret = new EdgeSecret(['actual' => null, 'siguiente' => '']);

        $this->assertFalse($secret->isConfigured());
        $this->assertFalse($secret->matches(''));
        $this->assertFalse($secret->matches(null));
        $this->assertSame([], $secret->rejected);
    }

    public function test_el_siguiente_vacio_no_abre_nada(): void
    {
        $secret = new EdgeSecret(['actual' => self::CURRENT, 'siguiente' => '']);

        $this->assertTrue($secret->isConfigured());
        $this->assertFalse($secret->matches(''));
    }

    public function test_un_secreto_corto_o_que_no_es_texto_se_descarta_y_se_nombra(): void
    {
        $secret = new EdgeSecret(['actual' => 'changeme', 'siguiente' => true, 'otro' => self::NEXT]);

        $this->assertSame(['actual', 'siguiente'], $secret->rejected);
        $this->assertFalse($secret->matches('changeme'));
        $this->assertTrue($secret->matches(self::NEXT));
    }

    public function test_el_largo_minimo_es_exacto(): void
    {
        $justEnough = str_repeat('a', EdgeSecret::MIN_LENGTH);
        $short = str_repeat('a', EdgeSecret::MIN_LENGTH - 1);

        $this->assertTrue((new EdgeSecret(['actual' => $justEnough]))->matches($justEnough));
        $this->assertSame(['actual'], (new EdgeSecret(['actual' => $short]))->rejected);
    }

    public function test_los_espacios_alrededor_no_cuentan(): void
    {
        // Un valor pegado en el panel de Vapor con un salto de línea al final.
        $secret = new EdgeSecret(['actual' => self::CURRENT."\n"]);

        $this->assertTrue($secret->matches(self::CURRENT));
        $this->assertTrue($secret->matches(' '.self::CURRENT));
    }
}
