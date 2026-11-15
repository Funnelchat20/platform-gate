<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\PublicIds;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Funnelchat\PlatformGate\Exceptions\GateMisconfigured;
use InvalidArgumentException;

/**
 * Los ajustes de los ids públicos (0.12.0), leídos una vez y sin tirar nunca al leerlos: lo que
 * no se puede usar queda como `problem()`, y se dice recién cuando hace falta.
 *
 *   - `enabled` — apagado, nada cambia: los ids salen y entran enteros, como antes de 0.12.0.
 *   - `keys` — `A=<64 hex>,B=<64 hex>`: la variable `PLATFORM_GATE_PUBLIC_ID_KEYS`, **la misma
 *     en los tres dominios**.
 *   - `current_key` — la versión con la que se codifica. Sin definir, la más alta. Sirve para
 *     rotar sin cortar: primero se agrega la clave nueva en todos los dominios, y recién después
 *     se pasa a codificar con ella.
 *   - `accept_legacy_until` — hasta cuándo un entero en un pedido se sigue aceptando.
 *   - `types` — los tipos propios del dominio, además de la tabla base.
 *
 * Prendido y con algo de esto ilegible, el portero corta las llamadas con key con 500
 * `gate_misconfigured`: nunca deja salir un entero ni acepta uno que no debería (falla cerrado).
 */
final class PublicIdSettings
{
    private const KEY_ENTRY = '/^([A-Za-z0-9])=([0-9A-Fa-f]{64})$/D';

    private ?PublicIdCodec $codec = null;

    private ?string $problem = null;

    private ?DateTimeImmutable $legacyUntil = null;

    private ?PublicIdTypes $types = null;

    public function __construct(
        public readonly bool $enabled,
        #[\SensitiveParameter] mixed $keys,
        mixed $currentKey,
        mixed $acceptLegacyUntil,
        mixed $types,
    ) {
        try {
            $this->types = PublicIdTypes::with($types);
            $this->legacyUntil = self::parseDate($acceptLegacyUntil);
            $parsed = self::parseKeys($keys);
            $this->codec = new PublicIdCodec($this->types, $parsed, self::currentVersion($parsed, $currentKey));
        } catch (InvalidArgumentException $e) {
            // El texto no lleva claves: lo arman los `parse*` de acá, que nunca las citan.
            $this->problem = $e->getMessage();
        }
    }

    /** @param array<string, mixed> $config `platform-gate.public_ids` */
    public static function fromConfig(mixed $config): self
    {
        $config = is_array($config) ? $config : [];

        return new self(
            (bool) ($config['enabled'] ?? false),
            $config['keys'] ?? null,
            $config['current_key'] ?? null,
            $config['accept_legacy_until'] ?? null,
            $config['types'] ?? [],
        );
    }

    /** Qué no se puede usar, sin secretos; `null` si todo se puede usar. */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * El codificador. Con la configuración ilegible tira `GateMisconfigured`: quien pide un id
     * público y no lo puede tener no recibe un entero en su lugar.
     */
    public function codec(): PublicIdCodec
    {
        return $this->codec ?? throw new GateMisconfigured('public_ids');
    }

    /** El registro de tipos; con `types` ilegible, la tabla base. */
    public function types(): PublicIdTypes
    {
        return $this->types ?? PublicIdTypes::with([]);
    }

    /** ¿Un entero en un pedido se acepta todavía? Sin fecha, nunca. */
    public function acceptsLegacy(?DateTimeImmutable $now = null): bool
    {
        return $this->legacyUntil !== null && ($now ?? new DateTimeImmutable('now')) < $this->legacyUntil;
    }

    public function legacyUntil(): ?DateTimeImmutable
    {
        return $this->legacyUntil;
    }

    /**
     * Las cabeceras de una respuesta a un pedido que usó un entero mientras rige la ventana:
     *
     *   - `Deprecation` (RFC 9745): `@<segundos>`, el momento de la respuesta. El entero ya está
     *     en desuso cuando se contesta; la librería no guarda desde cuándo.
     *   - `Sunset` (RFC 8594): la fecha HTTP de `accept_legacy_until`, cuando deja de aceptarse.
     *
     * @return array<string, string>
     */
    public function legacyHeaders(): array
    {
        if ($this->legacyUntil === null) {
            return [];
        }

        return [
            'Deprecation' => '@'.time(),
            'Sunset' => $this->legacyUntil->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T'),
        ];
    }

    /** @return array<string, string> versión => 32 bytes */
    private static function parseKeys(#[\SensitiveParameter] mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException('Faltan las claves de los ids públicos (`PLATFORM_GATE_PUBLIC_ID_KEYS`).');
        }

        $keys = [];

        foreach (explode(',', $raw) as $position => $entry) {
            if (preg_match(self::KEY_ENTRY, trim($entry), $match) !== 1) {
                throw new InvalidArgumentException(
                    '`PLATFORM_GATE_PUBLIC_ID_KEYS`: la entrada '.($position + 1).' no tiene la forma '
                    .'`<versión>=<64 caracteres hex>` (generala con `openssl rand -hex 32`).'
                );
            }

            if (isset($keys[$match[1]])) {
                throw new InvalidArgumentException("`PLATFORM_GATE_PUBLIC_ID_KEYS`: la versión `{$match[1]}` está dos veces.");
            }

            $keys[$match[1]] = (string) hex2bin($match[2]);
        }

        return $keys;
    }

    /** @param array<string, string> $keys */
    private static function currentVersion(array $keys, mixed $configured): string
    {
        if ($configured === null || $configured === '') {
            // La más alta en el orden del alfabeto: `A` < `B` < … < `z` < `0` < … < `9`.
            $versions = array_keys($keys);
            usort($versions, static fn (string $a, string $b): int => strpos(PublicIdCodec::ALPHABET, $a) <=> strpos(PublicIdCodec::ALPHABET, $b));

            return (string) end($versions);
        }

        if (! is_string($configured) || ! isset($keys[$configured])) {
            throw new InvalidArgumentException('`PLATFORM_GATE_PUBLIC_ID_CURRENT_KEY` no es una de las versiones de `PLATFORM_GATE_PUBLIC_ID_KEYS`.');
        }

        return $configured;
    }

    /**
     * `2026-12-14` (las 00:00 UTC de ese día) o una fecha con hora y zona
     * (`2026-12-14T15:00:00Z`, `2026-12-14T12:00:00-03:00`). Nada más: una fecha que se puede
     * leer de dos formas no es una fecha.
     */
    private static function parseDate(mixed $raw): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($raw);
        }

        if (is_string($raw)) {
            $text = trim($raw);
            $utc = new DateTimeZone('UTC');

            foreach (['!Y-m-d' => $text, DateTimeInterface::ATOM => preg_replace('/Z$/D', '+00:00', $text)] as $format => $value) {
                $date = DateTimeImmutable::createFromFormat($format, (string) $value, $utc);

                // Lo que PHP "corrige" en silencio (un 31 de febrero) tampoco es una fecha.
                if ($date !== false && $date->format($format === '!Y-m-d' ? 'Y-m-d' : DateTimeInterface::ATOM) === $value) {
                    return $date;
                }
            }
        }

        throw new InvalidArgumentException(
            '`public_ids.accept_legacy_until` no es una fecha: va `2026-12-14` (las 00:00 UTC) o `2026-12-14T15:00:00Z`.'
        );
    }
}
