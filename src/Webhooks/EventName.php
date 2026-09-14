<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Webhooks;

use InvalidArgumentException;

/**
 * Cómo se llama un evento. Una sola convención para los cuatro dominios.
 *
 * `recurso.hecho_en_pasado`, todo en minúsculas: `message.received`,
 * `message.status_changed`, `broadcast.finished`, `flow.completed`,
 * `group.participant_joined`.
 *
 * Por qué está validado y no sugerido: si no se fija antes de que se escriba el primer
 * evento, conversations manda `message.received` y communities `GroupParticipantJoined`, y
 * el usuario de n8n aprende dos convenciones para una sola API. Una vez publicados, los
 * nombres no se pueden cambiar sin romperle el workflow a alguien — es de las poquísimas
 * cosas de este piloto que no admiten "después lo arreglamos".
 *
 * El tiempo pasado es deliberado: un webhook avisa algo que YA pasó. `message.send` sonaría
 * a una orden; `message.sent` es un hecho.
 */
final readonly class EventName
{
    private const PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    private function __construct(public string $value)
    {
    }

    public static function from(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Nombre de evento inválido: `{$value}`. La convención es `recurso.hecho_en_pasado`, "
                .'todo en minúsculas y separado por un punto — por ejemplo `message.received` o '
                .'`group.participant_joined`.'
            );
        }

        return new self($value);
    }

    public function resource(): string
    {
        return explode('.', $this->value)[0];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
