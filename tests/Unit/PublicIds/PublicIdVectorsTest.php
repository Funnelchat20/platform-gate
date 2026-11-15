<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit\PublicIds;

use Funnelchat\PlatformGate\PublicIds\PublicIdCodec;
use Funnelchat\PlatformGate\PublicIds\PublicIdTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El codificador contra los vectores compartidos (0.12.0): `tests/vectors/public-ids.json`.
 *
 * Son un contrato con otras implementaciones (el gate en Python). Los valores esperados se
 * calcularon fuera de esta librería: el bloque con `openssl_encrypt` directo y el base62 con GMP.
 * Cubren lo que pide el contrato `1411-ids-opacos`: ida y vuelta, determinismo, otro tipo,
 * alterado y versión de clave desconocida.
 */
final class PublicIdVectorsTest extends TestCase
{
    private const FILE = __DIR__.'/../../vectors/public-ids.json';

    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function codec(string $current = 'A'): PublicIdCodec
    {
        $keys = array_map(static fn (string $hex): string => (string) hex2bin($hex), self::vectors()['keys']);

        return new PublicIdCodec(PublicIdTypes::with([]), $keys, $current);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function encodeCases(): iterable
    {
        foreach (self::vectors()['encode'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCases(): iterable
    {
        foreach (self::vectors()['decode_invalid'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('encodeCases')]
    public function test_codifica_como_dicen_los_vectores_y_vuelve(array $case): void
    {
        $codec = self::codec($case['version']);

        // Determinista: el mismo entero del mismo tipo da el mismo id, con otra instancia también.
        $this->assertSame($case['public_id'], $codec->encode($case['type'], $case['id']));
        $this->assertSame($case['public_id'], self::codec($case['version'])->encode($case['type'], $case['id']));

        // Ida y vuelta, también con la otra versión como vigente: decodificar no depende de cuál codifica.
        $this->assertSame($case['id'], $codec->decode($case['public_id'], $case['type']));
        $this->assertSame($case['id'], self::codec($case['version'] === 'A' ? 'B' : 'A')->decode($case['public_id'], $case['type']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('invalidCases')]
    public function test_lo_invalido_decodifica_a_null(array $case): void
    {
        $this->assertNull(self::codec()->decode($case['public_id'], $case['type']));
    }

    public function test_una_version_que_se_saco_de_la_lista_deja_de_decodificar(): void
    {
        $vectors = self::vectors();
        $onlyB = new PublicIdCodec(PublicIdTypes::with([]), ['B' => (string) hex2bin($vectors['keys']['B'])], 'B');

        $this->assertNull($onlyB->decode('ctc_ABsza6Ialu1BCUyPFZ8WJvV', 'contact'));
        $this->assertSame(1, $onlyB->decode('ctc_BEsnMskeIo11XEMBlGzprQQ', 'contact'));
    }

    public function test_cada_caracter_cambiado_del_cuerpo_da_null(): void
    {
        $codec = self::codec();
        $id = $codec->encode('contact', 104816);

        for ($i = 5; $i < strlen($id); $i++) {
            $other = PublicIdCodec::ALPHABET[(strpos(PublicIdCodec::ALPHABET, $id[$i]) + 1) % 62];

            $this->assertNull($codec->decode(substr_replace($id, $other, $i, 1), 'contact'), "posición {$i}");
        }
    }
}
