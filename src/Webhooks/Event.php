<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Webhooks;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * El sobre: la forma que tiene TODO evento, en los cuatro dominios.
 *
 *   {
 *     "id":          "evt_9f2c…",
 *     "type":        "message.received",
 *     "version":     1,
 *     "occurred_at": "2026-09-11T17:48:29Z",
 *     "data":        { … }
 *   }
 *
 * Lo que el dominio decide es `type` y `data`. Todo lo demás es igual para todos, porque
 * el que lo recibe es un workflow de n8n que no sabe ni le importa qué dominio nuestro lo
 * emitió.
 *
 * **`data` no puede llevar identificadores internos de cuenta** — ni `user_id`, ni
 * `account_id`, ni `owner_id`, escritos como sea (`userId`, `OWNER-ID`) y dentro de lo que sea
 * (un array, un objeto, un Resource). La suscripción ya está atada a una cuenta: el número no
 * agrega nada y sí compromete. Hay una migración a `workspace_id` en curso, y el día que
 * un cliente lea uno de esos números de un payload, las opciones pasan a ser romperle el
 * código, mantener dos campos para siempre, o no migrar. Cuesta cero evitarlo hoy, así
 * que está prohibido acá y no en una guía de estilo.
 *
 * **`workspace_id` sí puede ir** (0.9.1). En 0.9.0 estaba prohibido: la Q7 de workspaces
 * (26-10) decía que el aviso no dice de qué workspace viene. El 04-11 la Q35 la cambió: los
 * avisos públicos dicen de qué workspace vienen —el workspace al que se rutea el aviso—, y
 * accounts lo pone en todos. La suscripción sigue siendo de un workspace y sigue recibiendo
 * sólo los suyos; el campo informa, no elige. Es el identificador al que se está migrando,
 * no uno de los internos de cuenta de arriba.
 *
 * `version` es del TIPO de evento, no del sobre: permite que `message.received` evolucione
 * sin arrastrar a los demás.
 */
final readonly class Event
{
    private const FORBIDDEN_KEYS = ['user_id', 'account_id', 'owner_id', 'tokenable_id'];

    /** Cuánto se baja al buscar: más que esto en un payload es un error, no un aviso. */
    private const MAX_DEPTH = 512;

    public function __construct(
        public string $id,
        public EventName $type,
        public int $version,
        public DateTimeImmutable $occurredAt,
        /** @var array<string,mixed> */
        public array $data,
    ) {
        self::assertNoAccountIdentifiers($data);
    }

    /** @param array<string,mixed> $data */
    public static function make(string $type, array $data, ?DateTimeImmutable $occurredAt = null, int $version = 1): self
    {
        return new self(
            id: 'evt_'.bin2hex(random_bytes(12)),
            type: EventName::from($type),
            version: $version,
            occurredAt: $occurredAt ?? new DateTimeImmutable('now'),
            data: $data,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'data' => $this->data,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Se revisa lo que va a salir en el JSON, no la estructura de PHP (0.11.0). Hasta 0.10.0 se
     * recorrían sólo los arrays y se comparaba el nombre exacto en `snake_case`, así que pasaban
     * `userId`, `accountId` y cualquier objeto —un `stdClass`, un `JsonSerializable`, un Resource
     * de Laravel— con un `user_id` adentro, que `toJson()` igual mandaba al cliente.
     *
     * Por eso se serializa primero, como lo hace `toJson()`, y se busca en eso: un objeto llega al
     * cliente con sus claves igual que un array. Y el nombre se compara sin lo que no lo cambia:
     * mayúsculas, guiones, espacios o `camelCase` (`userId`, `USER-ID`, `user id` son `user_id`).
     *
     * Lo que no se puede serializar no se rechaza por eso —un texto con UTF-8 roto no es una
     * fuga—: se busca en lo que sí. Lo que no se puede revisar —más de `MAX_DEPTH` niveles— tira.
     *
     * @param array<string,mixed> $data
     */
    private static function assertNoAccountIdentifiers(array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            self::MAX_DEPTH,
        );

        $wire = $json === false ? null : json_decode($json, true, self::MAX_DEPTH + 1);

        if (! is_array($wire)) {
            throw new InvalidArgumentException(
                'No se pudo revisar el payload del webhook: tiene más de '.self::MAX_DEPTH.' niveles.'
            );
        }

        self::assertNoForbiddenKey($wire, 'data');
    }

    /** @param array<array-key,mixed> $data lo que sale en el JSON, ya decodificado */
    private static function assertNoForbiddenKey(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            $forbidden = is_string($key) ? self::forbiddenNameOf($key) : null;

            if ($forbidden !== null) {
                $spelling = $key === $forbidden ? '' : " —es `{$forbidden}` escrito de otra forma—";

                throw new InvalidArgumentException(
                    "El payload de un webhook no puede llevar `{$key}` (en `{$path}`){$spelling}. La "
                    .'suscripción ya está atada a una cuenta. Si hace falta un identificador, va opaco y '
                    .'documentado como opaco desde el primer día.'
                );
            }

            if (is_array($value)) {
                self::assertNoForbiddenKey($value, $path.'.'.$key);
            }
        }
    }

    /** El nombre prohibido que `$key` escribe de otra forma, o `null`. */
    private static function forbiddenNameOf(string $key): ?string
    {
        $bare = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if ($bare === str_replace('_', '', $forbidden)) {
                return $forbidden;
            }
        }

        return null;
    }
}
