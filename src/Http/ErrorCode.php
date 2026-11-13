<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http;

/**
 * Los códigos genéricos de error de la API pública (0.10, contrato `1113-errores-y-limites`,
 * punto 2): los que salen cuando el dominio no tiene uno más preciso.
 *
 * `error` es lo que quien integra compara: estable, en `snake_case`. `message` es para personas
 * y puede cambiar. Los códigos propios del portero (`permission_denied`, `daily_cap_exceeded`…)
 * no salen de acá: los da cada `GateException`.
 *
 * Un status que no está en la tabla cae en el de su familia —`bad_request` los 4xx,
 * `internal_error` los 5xx—, nunca en un código inventado: lo que el cliente machea tiene que
 * ser uno de los que el portal documenta.
 */
final class ErrorCode
{
    /** @var array<int, string> */
    public const BY_STATUS = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        402 => 'payment_required',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        422 => 'validation_failed',
        429 => 'rate_limited',
        500 => 'internal_error',
        503 => 'unavailable',
    ];

    /** @var array<string, string> */
    private const MESSAGES = [
        'bad_request' => 'El pedido no es válido.',
        'unauthenticated' => UnauthenticatedResponse::MESSAGE,
        'payment_required' => 'Esta llamada necesita que la cuenta tenga el pago al día.',
        'forbidden' => 'Esta acción no está permitida.',
        'not_found' => 'No se encontró lo que se pidió.',
        'method_not_allowed' => 'Esta ruta no acepta ese método.',
        'conflict' => 'El pedido choca con el estado actual del recurso.',
        'payload_too_large' => 'El pedido es demasiado grande.',
        'validation_failed' => 'Los datos enviados no son válidos.',
        'rate_limited' => 'Demasiadas llamadas en poco tiempo. Reintentá cuando pasen los segundos que indica Retry-After.',
        'internal_error' => 'Algo falló de nuestro lado. Reintentá en unos minutos.',
        'unavailable' => 'El servicio no está disponible en este momento. Reintentá en unos segundos.',
    ];

    public static function forStatus(int $status): string
    {
        return self::BY_STATUS[$status] ?? ($status >= 500 ? 'internal_error' : 'bad_request');
    }

    /** El texto genérico, en español, de un código de la tabla. Un código que no está, el de `bad_request`. */
    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['bad_request'];
    }
}
