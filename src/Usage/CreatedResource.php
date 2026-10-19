<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lo que creó la llamada: un tipo corto y su id. El controlador del dominio lo declara y el
 * portero lo guarda en la fila de `api_call_log` al terminar el pedido (contrato
 * 1019-registro-llamadas, L1). Así soporte va de la llamada a lo que produjo sin adivinar.
 *
 *     CreatedResource::declare('message', $message->id);
 *
 * No recibe el pedido: queda en `CreatedResourceContext`, que es del pedido en curso, así que
 * se puede declarar desde el controlador —con o sin FormRequest— o desde un servicio.
 *
 * Un recurso por llamada: si se declara dos veces, queda la última. Sin declaración, la fila
 * queda sin recurso, como en 0.7.
 *
 * Sigue sin PII ni contenido (D12): el id es un id INTERNO —un entero, un UUID, un
 * `msg_…`—, nunca un teléfono, un email ni un texto. Por eso el formato es estrecho: un `+`,
 * una `@` o un espacio no pasan. Un número de teléfono sin `+` no se distingue de un id
 * entero: eso lo cuida quien declara.
 *
 * Una declaración inválida NO rompe la llamada: cuando el controlador declara, el recurso ya
 * existe, y un 500 ahí haría que el cliente reintente y lo cree dos veces. No se guarda,
 * queda un warning en el log —sin el valor— y la fila sale sin recurso.
 */
final readonly class CreatedResource
{
    /** `message`, `contact`, `tag`, `scheduled_message`… Minúsculas, hasta 32. */
    private const TYPE = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** Enteros, UUID, ULID, `msg_…`. Hasta 64. */
    private const ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    private function __construct(
        public string $type,
        public string $id,
    ) {
    }

    public static function declare(string $type, int|string $id): void
    {
        $id = (string) $id;

        $invalid = match (true) {
            preg_match(self::TYPE, $type) !== 1 => 'type',
            preg_match(self::ID, $id) !== 1 => 'id',
            default => null,
        };

        if ($invalid !== null) {
            self::warn($invalid, $invalid === 'id' ? $type : null);

            return;
        }

        Container::getInstance()->make(CreatedResourceContext::class)->set(new self($type, $id));
    }

    /** Lo declarado en el pedido en curso, o `null`. */
    public static function current(): ?self
    {
        return Container::getInstance()->make(CreatedResourceContext::class)->get();
    }

    private static function warn(string $invalid, ?string $type): void
    {
        try {
            Container::getInstance()->make(LoggerInterface::class)->warning(
                'platform-gate: declaración de recurso inválida, la fila queda sin recurso. '
                .'El tipo va en minúsculas (`message`, `scheduled_message`) y el id es un id interno: '
                .'letras, números, `_` y `-`, hasta 64.',
                ['invalid' => $invalid, 'type' => $type],
            );
        } catch (Throwable) {
            // Sin logger no hay a quién decírselo; la llamada sigue igual.
        }
    }
}
