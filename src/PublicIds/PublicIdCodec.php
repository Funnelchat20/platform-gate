<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\PublicIds;

use InvalidArgumentException;

/**
 * El codificador de ids públicos (0.12.0, contrato `1411-ids-opacos`):
 *
 *     ctc_ A 3kTMd9qX0bVwYJ2pLr8NzQ
 *     ───  ─ ──────────────────────
 *      │   │  cuerpo: el bloque cifrado, en base62, siempre 22 caracteres
 *      │   versión de la clave: un carácter base62 (`A` = la primera)
 *      prefijo del tipo: tres letras
 *
 * El bloque es uno de AES-256 (16 bytes), cifrado con `openssl` sin modo de encadenamiento —un
 * solo bloque no tiene nada que encadenar—:
 *
 *     bytes 0-2    el prefijo, en ASCII: el código del tipo
 *     bytes 3-10   el entero, sin signo, big-endian
 *     bytes 11-15  ceros: el control
 *
 * Es **determinista**: el mismo entero del mismo tipo con la misma clave da siempre el mismo id,
 * en cualquier dominio. Cambiar un carácter del cuerpo cambia el bloque entero al descifrar, y
 * el resultado tiene que traer el prefijo esperado y los cinco ceros: uno al azar los trae una
 * vez en 2^64. Por eso `decode()` puede devolver `null` ante cualquier cosa que no salió de acá.
 *
 * El alfabeto base62 es `A-Z a-z 0-9`, con `A` = 0: el mismo para la versión y para el cuerpo,
 * así la primera versión es `A` y el cuerpo se completa con `A` a la izquierda. Los vectores de
 * prueba están en `tests/vectors/public-ids.json`: otra implementación (la del gate en Python)
 * tiene que dar exactamente lo mismo.
 *
 * Este objeto codifica y decodifica siempre: no mira `public_ids.enabled` ni quién llama. Eso lo
 * hace `PublicIds`, que es lo que usa el dominio.
 */
final class PublicIdCodec
{
    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** 62^22 > 2^128 > 62^21: 22 caracteres alcanzan para cualquier bloque y no sobra ninguno. */
    public const BODY_LENGTH = 22;

    private const CIPHER = 'aes-256-ecb';

    private const CONTROL = "\0\0\0\0\0";

    /**
     * @param array<string, string> $keys versión (un carácter del alfabeto) => clave de 32 bytes
     * @param string $current la versión con la que se codifica; las demás sólo decodifican
     */
    public function __construct(
        private readonly PublicIdTypes $types,
        #[\SensitiveParameter] private readonly array $keys,
        private readonly string $current,
    ) {
        if (! isset($keys[$current])) {
            throw new InvalidArgumentException('La versión de clave con la que se codifica no está entre las claves.');
        }
    }

    /**
     * @throws InvalidArgumentException si el tipo no está registrado o el entero no es un id (menor que 1)
     */
    public function encode(string $type, int $id): string
    {
        $prefix = $this->types->prefixOf($type);

        if ($id < 1) {
            throw new InvalidArgumentException('Un id público sólo se arma con un entero positivo.');
        }

        $block = openssl_encrypt(
            $prefix.pack('J', $id).self::CONTROL,
            self::CIPHER,
            $this->keys[$this->current],
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );

        if (! is_string($block) || strlen($block) !== 16) {
            // Sin `openssl` funcionando no hay id que dar, y un entero sería una fuga.
            throw new \RuntimeException('platform-gate: openssl no pudo cifrar el id público.');
        }

        return $prefix.'_'.$this->current.self::toBase62($block);
    }

    /**
     * El entero, o `null` si el texto no es un id de ese tipo hecho con una de las claves: mal
     * formado, de otro tipo, alterado, o con una versión de clave que no existe.
     *
     * @throws InvalidArgumentException si el tipo esperado no está registrado: es un error del código, no del pedido
     */
    public function decode(mixed $publicId, string $expectedType): ?int
    {
        $prefix = $this->types->prefixOf($expectedType);

        if (! is_string($publicId) || strlen($publicId) !== 5 + self::BODY_LENGTH || ! str_starts_with($publicId, $prefix.'_')) {
            return null;
        }

        $key = $this->keys[$publicId[4]] ?? null;
        $block = self::fromBase62(substr($publicId, 5));

        if ($key === null || $block === null) {
            return null;
        }

        $plain = openssl_decrypt($block, self::CIPHER, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

        if (! is_string($plain) || strlen($plain) !== 16
            || ! hash_equals($prefix, substr($plain, 0, 3))
            || ! hash_equals(self::CONTROL, substr($plain, 11))) {
            return null;
        }

        // `J` lee sin signo, pero PHP no tiene enteros sin signo: un bit alto da negativo.
        $id = unpack('J', substr($plain, 3, 8))[1];

        return $id >= 1 ? $id : null;
    }

    /** 16 bytes → 22 caracteres. División larga sobre los bytes: sin `gmp` ni `bcmath`. */
    private static function toBase62(string $bytes): string
    {
        $digits = array_values(unpack('C*', $bytes));
        $out = '';

        for ($i = 0; $i < self::BODY_LENGTH; $i++) {
            $remainder = 0;

            foreach ($digits as $k => $byte) {
                $acc = ($remainder << 8) | $byte;
                $digits[$k] = intdiv($acc, 62);
                $remainder = $acc % 62;
            }

            $out = self::ALPHABET[$remainder].$out;
        }

        return $out;
    }

    /** 22 caracteres → 16 bytes, o `null` si hay un carácter de otro alfabeto o el número no entra en 128 bits. */
    private static function fromBase62(string $text): ?string
    {
        if (strlen($text) !== self::BODY_LENGTH || strspn($text, self::ALPHABET) !== self::BODY_LENGTH) {
            return null;
        }

        $bytes = array_fill(0, 16, 0);

        foreach (str_split($text) as $char) {
            $carry = strpos(self::ALPHABET, $char);

            for ($k = 15; $k >= 0; $k--) {
                $acc = $bytes[$k] * 62 + $carry;
                $bytes[$k] = $acc & 0xFF;
                $carry = $acc >> 8;
            }

            // Más de 128 bits: no lo pudo haber hecho `toBase62()`. Rechazarlo es lo que hace que
            // cada bloque tenga un solo texto posible.
            if ($carry !== 0) {
                return null;
            }
        }

        return pack('C*', ...$bytes);
    }
}
