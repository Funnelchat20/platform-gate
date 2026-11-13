<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\OpenApi;

use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Funnelchat\PlatformGate\Http\ErrorCode;

/**
 * Que el OpenAPI que genera el dominio con Scramble muestre `error` en las respuestas de error
 * (0.10, contrato `1113-errores-y-limites`, punto 6). Sin esto, la especificación publicada
 * documenta el 403, el 404 y el 422 sólo con `message` —lo que Scramble deduce de las
 * excepciones de Laravel—, aunque con `ErrorResponse` la API ya devuelva `error`.
 *
 * Es un transformador de documento: corre cuando Scramble terminó de armar el documento. El
 * dominio lo engancha en un service provider:
 *
 *     use Dedoc\Scramble\Scramble;
 *
 *     Scramble::afterOpenApiGenerated(new \Funnelchat\PlatformGate\OpenApi\AddErrorCode());
 *
 * Qué hace: a cada respuesta 4xx o 5xx con cuerpo `application/json` que sea un objeto sin
 * `error` —en `components.responses` (las `*Exception` de Scramble) y en cada operación— le
 * agrega `error` como primera propiedad, obligatoria, de tipo string, con el código genérico de
 * su status como ejemplo. Si el cuerpo trae `code` (string), `error` lleva lo mismo que `code`:
 * es lo que hace `ErrorBody` en la respuesta real. Lo que ya trae `error`, un cuerpo que es una
 * referencia a un esquema, una lista o algo que no es JSON no se toca.
 *
 * El documento describe la API con key: el panel, que no recibe `error`, no se documenta acá.
 *
 * No depende de Scramble para cargarse —no implementa ninguna interfaz suya—: el paquete no lo
 * exige, y sólo se usa en un dominio que lo tiene. Probado con Scramble 0.12 y 0.13.
 */
final class AddErrorCode
{
    /** Los códigos del portero que pueden salir con el mismo status que el genérico. */
    private const GATE_CODES = [
        403 => ['permission_denied', 'route_not_available', 'use_public_endpoint'],
        429 => ['daily_cap_exceeded'],
        500 => ['gate_misconfigured'],
        503 => ['gate_unavailable'],
    ];

    public function __invoke(OpenApi $document, mixed $context = null): void
    {
        foreach ($document->components->responses as $response) {
            $this->complete($response);
        }

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                // Una `Reference` a `components.responses` ya se completó arriba.
                foreach ($operation->responses ?? [] as $response) {
                    $this->complete($response);
                }
            }
        }
    }

    private function complete(mixed $response): void
    {
        if (! $response instanceof Response || ! is_numeric($response->code) || (int) $response->code < 400) {
            return;
        }

        $content = $response->content['application/json'] ?? null;
        $schema = $content instanceof MediaType ? $content->schema : $content;

        if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType || $schema->type->hasProperty('error')) {
            return;
        }

        $body = $schema->type;
        $status = (int) $response->code;
        $code = $body->properties['code'] ?? null;

        $error = $code instanceof StringType
            ? clone $code
            : (new StringType())->example(ErrorCode::forStatus($status));

        $error->setDescription($this->description($status));

        $body->properties = ['error' => $error] + $body->properties;
        $body->setRequired(array_values(array_unique(['error', ...$body->required])));
    }

    private function description(int $status): string
    {
        $description = 'Código estable del error: es lo que se compara. `message` es para personas y puede cambiar.';

        if (isset(self::GATE_CODES[$status])) {
            $codes = implode(', ', array_map(static fn (string $c): string => "`{$c}`", self::GATE_CODES[$status]));
            $description .= " Con este status, el portero también puede devolver {$codes}.";
        }

        return $description;
    }
}
