<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Caller;

/**
 * Quién está llamando. Enum CERRADO a propósito (pedido P1 de conversations).
 *
 * Un valor nuevo — `internal`, `partner` — NO puede caer por default en ninguno de los
 * existentes: tiene que ser una rama explícita que el dominio esté obligado a escribir.
 * Por eso los dominios deben consumir esto con `match` sin rama `default`: cuando acá
 * aparezca un caso nuevo, ese `match` va a tirar `\UnhandledMatchError` en los tests del
 * dominio en vez de elegir en silencio.
 *
 * `Unverified` no es `Web`. "El portero no corrió" y "el portero corrió y dijo que es el
 * front" son dos hechos distintos y se deciden distinto.
 */
enum CallerKind: string
{
    /** Token Sanctum con `kind = api_key`. Máquina. */
    case ApiKey = 'api_key';

    /** Token Sanctum con `kind = web`. El front, con un humano del otro lado. */
    case Web = 'web';

    /**
     * El portero no pudo clasificar: no corrió sobre esta ruta, no había token,
     * o la columna `kind` todavía no existe en este ambiente.
     */
    case Unverified = 'unverified';

    public function isMachine(): bool
    {
        return $this === self::ApiKey;
    }

    /**
     * Lee el `kind` tal como viene del modelo del dominio, que no siempre es un string.
     *
     * accounts castea la columna a un PHP enum propio; los otros tres la dejan cruda. Un
     * `(string) $enum` lanza `Error: Object of class … could not be converted to string`,
     * o sea **500 en cada request autenticado** del dominio que castee. Lo encontró
     * accounts leyendo esta librería antes de instalarla, no en producción.
     *
     * Devuelve `null` cuando no hay nada que leer o cuando el valor no es representable:
     * el llamador tiene que tratar eso como "no pude clasificar", nunca como "es el front".
     */
    public static function fromAttribute(mixed $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        if ($raw instanceof self) {
            return $raw;
        }

        if ($raw instanceof \BackedEnum) {
            $raw = $raw->value;
        } elseif ($raw instanceof \UnitEnum) {
            $raw = $raw->name;
        }

        if (! is_scalar($raw) && ! $raw instanceof \Stringable) {
            return null;
        }

        return self::tryFrom((string) $raw);
    }
}
