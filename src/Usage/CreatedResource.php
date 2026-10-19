<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
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
 * Sigue sin PII ni contenido (D12): el id es la clave interna del recurso, nunca un dato de
 * una persona. Sólo tres formas, las de una clave primaria: un entero positivo, un UUID o un
 * ULID. Cualquier otra cosa —un teléfono con `+`, un email, un texto, un `msg_…`, un `0`— se
 * descarta. Un teléfono sin `+` no se distingue de un entero: eso lo cuida quien declara,
 * pasando la clave del recurso y no un campo suyo.
 *
 * Una declaración inválida NO rompe la llamada: cuando el controlador declara, el recurso ya
 * existe, y un 500 ahí haría que el cliente reintente y lo cree dos veces. No se guarda,
 * queda un warning en el log —sin el valor— y la fila sale sin recurso.
 */
final readonly class CreatedResource
{
    /** `message`, `contact`, `tag`, `scheduled_message`… Minúsculas, hasta 32. */
    private const TYPE = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** Un entero positivo, como lo guarda un `BIGINT UNSIGNED`: hasta 20 dígitos, sin ceros adelante. */
    private const POSITIVE_INTEGER = '/^[1-9][0-9]{0,19}$/D';

    /** Crockford base32, 26 caracteres; el primero no pasa de 7 (128 bits). */
    private const ULID = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/Di';

    private function __construct(
        public string $type,
        public string $id,
    ) {
    }

    public static function declare(string $type, int|string $id): void
    {
        if (preg_match(self::TYPE, $type) !== 1) {
            self::warn('type', null);

            return;
        }

        $id = self::normalizeId($id);

        if ($id === null) {
            self::warn('id', $type);

            return;
        }

        Container::getInstance()->make(CreatedResourceContext::class)->set(new self($type, $id));
    }

    /** El id como se guarda —UUID en minúsculas, ULID en mayúsculas—, o `null` si no es ninguna de las tres formas. */
    private static function normalizeId(int|string $id): ?string
    {
        if (is_int($id)) {
            return $id > 0 ? (string) $id : null;
        }

        return match (true) {
            preg_match(self::POSITIVE_INTEGER, $id) === 1 => $id,
            Str::isUuid($id) => strtolower($id),
            preg_match(self::ULID, $id) === 1 => strtoupper($id),
            default => null,
        };
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
                .'El tipo va en minúsculas (`message`, `scheduled_message`) y el id es la clave '
                .'interna del recurso: un entero positivo, un UUID o un ULID.',
                ['invalid' => $invalid, 'type' => $type],
            );
        } catch (Throwable) {
            // Sin logger no hay a quién decírselo; la llamada sigue igual.
        }
    }
}
