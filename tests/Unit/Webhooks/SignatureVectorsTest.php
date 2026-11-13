<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit\Webhooks;

use Funnelchat\PlatformGate\Webhooks\Signature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los vectores compartidos de la firma (0.11.0): `tests/vectors/webhook-signature.json`.
 *
 * Son un contrato con lo que no es PHP —los snippets de verificación del portal, cualquier otra
 * implementación—: este test prueba que `Signature` los cumple, y los demás prueban contra el
 * mismo archivo. Los valores esperados se calcularon fuera de esta librería, con el HMAC de la
 * biblioteca estándar de Python. Cambian sólo con una versión nueva y una nota en el README.
 */
final class SignatureVectorsTest extends TestCase
{
    private const FILE = __DIR__.'/../../vectors/webhook-signature.json';

    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function signCases(): iterable
    {
        foreach (self::vectors()['sign'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function verifyCases(): iterable
    {
        foreach (self::vectors()['verify'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('signCases')]
    public function test_firma_como_dicen_los_vectores(array $case): void
    {
        $this->assertSame($case['header'], (new Signature($case['secret']))->for($case['payload'], $case['timestamp']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('verifyCases')]
    public function test_verifica_como_dicen_los_vectores(array $case): void
    {
        $this->assertSame(
            $case['valid'],
            (new Signature($case['secret']))->verify($case['payload'], $case['header'], $case['tolerance'], $case['now']),
        );
    }

    public function test_el_archivo_describe_lo_que_hace_la_libreria(): void
    {
        $vectors = self::vectors();

        $this->assertSame(Signature::HEADER, $vectors['header_name']);
        $this->assertSame(Signature::DEFAULT_TOLERANCE, $vectors['default_tolerance_seconds']);

        // Un archivo vacío o recortado no puede pasar como contrato cumplido.
        $this->assertGreaterThanOrEqual(4, count($vectors['sign']));
        $this->assertGreaterThanOrEqual(10, count($vectors['verify']));
        $this->assertContains(true, array_column($vectors['verify'], 'valid'));
        $this->assertContains(false, array_column($vectors['verify'], 'valid'));
    }
}
