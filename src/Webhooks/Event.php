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
 * `account_id`, ni `owner_id`. La suscripción ya está atada a una cuenta: el número no
 * agrega nada y sí compromete. Hay una migración a `workspace_id` en curso, y el día que
 * un cliente lea uno de esos números de un payload, las opciones pasan a ser romperle el
 * código, mantener dos campos para siempre, o no migrar. Cuesta cero evitarlo hoy, así
 * que está prohibido acá y no en una guía de estilo.
 *
 * `version` es del TIPO de evento, no del sobre: permite que `message.received` evolucione
 * sin arrastrar a los demás.
 */
final readonly class Event
{
    private const FORBIDDEN_KEYS = ['user_id', 'account_id', 'owner_id', 'tokenable_id'];

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

    /** @param array<string,mixed> $data */
    private static function assertNoAccountIdentifiers(array $data, string $path = 'data'): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException(
                    "El payload de un webhook no puede llevar `{$key}` (en `{$path}`). La suscripción "
                    .'ya está atada a una cuenta. Si hace falta un identificador, va opaco y '
                    .'documentado como opaco desde el primer día.'
                );
            }

            if (is_array($value)) {
                self::assertNoAccountIdentifiers($value, $path.'.'.$key);
            }
        }
    }
}
