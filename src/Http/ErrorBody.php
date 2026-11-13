<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http;

use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le agrega `error` al cuerpo JSON de error que el dominio armó a mano (0.10, contrato
 * `1113-errores-y-limites`, punto 1): `{"message": "…"}` pasa a `{"error": "…", "message": "…"}`.
 *
 * - `error` sale de `code` si el cuerpo trae uno (un string no vacío) y no trae `error`: es como
 *   accounts arma hoy sus 403 de workspace. Si no, del status (`ErrorCode`).
 * - **Nunca se quita ni se cambia nada de lo que ya viene.** Un cuerpo que ya trae `error` —con
 *   el valor que sea— no se toca. `error` se inserta como primera clave y el resto del cuerpo
 *   queda byte por byte como estaba: nada se decodifica y se vuelve a codificar.
 * - Sólo un objeto JSON con status 4xx o 5xx. Una lista, un JSON roto, un cuerpo que no es JSON
 *   o una descarga salen como estaban.
 *
 * Lo llama el portero sobre la respuesta de una llamada con key. A quien no es key no se le toca.
 */
final class ErrorBody
{
    public static function complete(Response $response): Response
    {
        $status = $response->getStatusCode();

        if ($status < 400 || ! self::isJson($response)) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return $response;
        }

        try {
            $body = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $response;
        }

        if (! $body instanceof stdClass || property_exists($body, 'error')) {
            return $response;
        }

        $code = isset($body->code) && is_string($body->code) && $body->code !== ''
            ? $body->code
            : ErrorCode::forStatus($status);

        // Decodificó como objeto: lo primero que no es espacio es la llave que lo abre.
        $open = strpos($content, '{');
        $entry = '"error":'.json_encode($code, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = substr($content, 0, $open + 1)
            .$entry.(get_object_vars($body) === [] ? '' : ',')
            .substr($content, $open + 1);

        if ($response instanceof JsonResponse) {
            $response->setJson($json);
        } else {
            $response->setContent($json);
            $response->headers->remove('Content-Length');
        }

        return $response;
    }

    private static function isJson(Response $response): bool
    {
        return $response instanceof JsonResponse
            || str_contains(strtolower((string) $response->headers->get('Content-Type')), 'json');
    }
}
