<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\OpenApi;

use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Combined\AllOf;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Funnelchat\PlatformGate\Http\Middleware\ResolvePublicIds;
use Funnelchat\PlatformGate\PublicIds\PublicIdCodec;
use Funnelchat\PlatformGate\PublicIds\PublicIdSettings;
use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Que el OpenAPI del dominio muestre los ids como lo que son (0.12.0, contrato `1411-ids-opacos`):
 * con `public_ids.enabled` prendido, un id sale `string`, con su prefijo en el patrón y un
 * ejemplo (`ctc_A…`). Es un transformador de documento, como `AddErrorCode`:
 *
 *     Scramble::afterOpenApiGenerated(new \Funnelchat\PlatformGate\OpenApi\DocumentPublicIds());
 *
 * Qué es un id, sin adivinar más que por el nombre:
 *
 *   - **Parámetros de ruta:** los que la ruta declara en el middleware `public-ids`. Los mismos
 *     que se decodifican.
 *   - **Campos y parámetros de consulta:** `<tipo>_id` (y `<tipo>_ids`, una lista) para cada tipo
 *     registrado (`contact_id`, `device_id`), más lo que el dominio declara en
 *     `public_ids.document`: `'ContactResource.id' => 'contact'` para el `id` de un esquema,
 *     `'number_id' => 'device'` para un nombre en cualquier lado, `'plan_id' => null` para decir
 *     que ese nombre no es un id.
 *
 * Sólo se reescribe lo que hoy se documenta como un id podría documentarse: un entero, un texto
 * sin formato, o la unión de los dos que Scramble deduce de `PublicIds::forResponse()`. Un objeto
 * o una lista de objetos con ese nombre no se tocan.
 *
 * **Apagado**, la unión que deduce Scramble de `forResponse()` vuelve a `integer` en lo que se
 * reconoce como id: la documentación queda como antes de 0.12.0. Lo que no se reconoce queda
 * como `integer | string`; declararlo en `public_ids.document` lo resuelve.
 *
 * El ejemplo tiene la forma de un id, pero no es uno: no sale de la clave, así que no
 * decodifica a nada.
 */
final class DocumentPublicIds
{
    private PublicIdSettings $settings;

    /** @var array<string, string|null> nombre (o `Esquema.nombre`) => tipo */
    private array $names = [];

    public function __invoke(OpenApi $document, mixed $context = null): void
    {
        $this->settings = Container::getInstance()->make(PublicIdSettings::class);
        $this->names = $this->names();
        $routes = $this->routeParameters();

        foreach ($document->components->schemas ?? [] as $name => $schema) {
            $this->walkSchema($schema, (string) $name);
        }

        foreach ($document->components->responses ?? [] as $response) {
            $this->walkResponse($response);
        }

        foreach ($document->paths as $path) {
            foreach ($path->operations as $method => $operation) {
                $declared = $this->declaredFor((string) $method, (string) $path->path, $routes);

                foreach ($operation->parameters ?? [] as $parameter) {
                    if ($parameter instanceof Parameter) {
                        $this->parameter($parameter, $declared);
                    }
                }

                $body = $operation->requestBodyObject ?? null;

                if ($body instanceof RequestBodyObject) {
                    foreach ($body->content as $content) {
                        $this->walkSchema($content instanceof MediaType ? $content->schema : $content, null);
                    }
                }

                foreach ($operation->responses ?? [] as $response) {
                    $this->walkResponse($response);
                }
            }
        }
    }

    /** @param array<string, string> $declared parámetro de ruta => tipo */
    private function parameter(Parameter $parameter, array $declared): void
    {
        if (! $parameter->schema instanceof Schema) {
            return;
        }

        // En la ruta vale sólo lo declarado: es lo único que se decodifica.
        $type = $parameter->in === 'path'
            ? ($declared[$parameter->name] ?? null)
            : $this->typeFor(null, $parameter->name);

        $replacement = $type === null ? null : $this->replacement($parameter->schema->type, $type);

        if ($replacement !== null) {
            $parameter->schema = Schema::fromType($replacement);
        }
    }

    private function walkResponse(mixed $response): void
    {
        if ($response instanceof Response) {
            foreach ($response->content ?? [] as $content) {
                $this->walkSchema($content instanceof MediaType ? $content->schema : $content, null);
            }
        }
    }

    private function walkSchema(mixed $schema, ?string $schemaName): void
    {
        if ($schema instanceof Schema) {
            $this->walk($schema->type, $schemaName);
        }
    }

    private function walk(mixed $type, ?string $schemaName): void
    {
        if ($type instanceof ObjectType) {
            foreach ($type->properties as $name => $property) {
                $target = $this->typeFor($schemaName, (string) $name);

                $replacement = match (true) {
                    $target === null => null,
                    $property instanceof ArrayType => $this->replaceItems($property, $target),
                    default => $this->replacement($property, $target),
                };

                if ($replacement !== null) {
                    $type->properties[$name] = $replacement;
                } else {
                    $this->walk($property, null);
                }
            }

            return;
        }

        if ($type instanceof ArrayType) {
            $this->walk($type->items, null);

            return;
        }

        if ($type instanceof AnyOf || $type instanceof AllOf) {
            foreach ($type->items as $item) {
                $this->walk($item, null);
            }
        }
    }

    private function replaceItems(ArrayType $list, string $target): ?ArrayType
    {
        $items = $list->items instanceof Type ? $this->replacement($list->items, $target) : null;

        if ($items === null) {
            return null;
        }

        $list->setItems($items);

        return $list;
    }

    /**
     * Lo que va en lugar de `$current` si es algo que un id podría ser; `null` si no.
     *
     * Prendido: `string` con el patrón y un ejemplo. Apagado: sólo la unión que deduce Scramble
     * de `forResponse()` vuelve a `integer`; un entero o un texto quedan como estaban.
     */
    private function replacement(mixed $current, string $target): ?Type
    {
        if (! $current instanceof Type || ! $this->settings->types()->has($target)) {
            return null;
        }

        $nullable = $current->nullable;
        $isUnion = false;

        if ($current instanceof AnyOf) {
            foreach ($current->items as $item) {
                if ($item instanceof NullType) {
                    $nullable = true;
                } elseif (! $item instanceof IntegerType && ! $this->isPlainString($item)) {
                    return null;
                }
            }

            $isUnion = true;
        } elseif (! $current instanceof IntegerType && ! $this->isPlainString($current)) {
            return null;
        }

        if (! $this->settings->enabled) {
            return $isUnion
                ? (new IntegerType())->nullable($nullable)->setDescription($current->description)
                : null;
        }

        $prefix = $this->settings->types()->prefixOf($target);
        $description = trim($current->description.' Id opaco: un texto que empieza con `'.$prefix.'_`. Se usa tal como llega; no se arma ni se interpreta.');

        $id = (new StringType())
            ->example($this->example($prefix))
            ->nullable($nullable)
            ->setDescription($description);

        // `pattern` existe desde Scramble 0.13. En 0.12 el prefijo queda en el ejemplo y en la descripción.
        if (method_exists($id, 'pattern')) {
            $id->pattern('^'.$prefix.'_[A-Za-z0-9]{'.(1 + PublicIdCodec::BODY_LENGTH).'}$');
        }

        return $id;
    }

    private function isPlainString(mixed $type): bool
    {
        return $type instanceof StringType && $type->format === '' && $type->enum === [];
    }

    /** Con la forma de un id, sin salir de la clave: no decodifica a nada. */
    private function example(string $prefix): string
    {
        $digits = array_values(unpack('C*', substr(hash('sha256', 'platform-gate example '.$prefix, true), 0, PublicIdCodec::BODY_LENGTH)));
        $body = implode('', array_map(static fn (int $byte): string => PublicIdCodec::ALPHABET[$byte % 62], $digits));

        return $prefix.'_A'.$body;
    }

    private function typeFor(?string $schemaName, string $name): ?string
    {
        if ($schemaName !== null && array_key_exists($schemaName.'.'.$name, $this->names)) {
            return $this->names[$schemaName.'.'.$name];
        }

        return $this->names[$name] ?? null;
    }

    /** @return array<string, string|null> */
    private function names(): array
    {
        $names = [];

        foreach (array_keys($this->settings->types()->all()) as $type) {
            $names[$type.'_id'] = $type;
            $names[$type.'_ids'] = $type;
        }

        $declared = Container::getInstance()->make('config')->get('platform-gate.public_ids.document', []);

        foreach (is_array($declared) ? $declared : [] as $name => $type) {
            if (is_string($name) && ($type === null || is_string($type))) {
                $names[$name] = $type;
            }
        }

        return $names;
    }

    /**
     * Las rutas, con lo que declaran en `public-ids` (vacío si nada).
     *
     * @return list<array{methods: list<string>, uri: string, parameters: array<string, string>}>
     */
    private function routeParameters(): array
    {
        $routes = [];

        foreach (Container::getInstance()->make(Router::class)->getRoutes()->getRoutes() as $route) {
            $parameters = [];

            foreach ($route->middleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                [$name, $arguments] = array_pad(explode(':', $middleware, 2), 2, '');

                if ($name !== 'public-ids' && $name !== ResolvePublicIds::class) {
                    continue;
                }

                foreach (array_filter(explode(',', $arguments)) as $entry) {
                    [$parameter, $type] = str_contains($entry, '=') ? explode('=', $entry, 2) : [$entry, $entry];
                    $parameters[$parameter] = $type;
                }
            }

            // También las que no declaran nada: si no, una que termina igual y sí declara se
            // quedaría con una operación que no es suya.
            $routes[] = [
                'methods' => array_map('strtolower', $route->methods()),
                'uri' => '/'.ltrim($route->uri(), '/'),
                'parameters' => $parameters,
            ];
        }

        return $routes;
    }

    /**
     * Lo declarado por la ruta de esta operación. El camino del documento es el URI de la ruta
     * sin el prefijo de la API (`api`), así que la ruta es la de ese método cuyo URI termina en
     * ese camino; si hay más de una, la de URI más corto.
     *
     * @param list<array{methods: list<string>, uri: string, parameters: array<string, string>}> $routes
     * @return array<string, string>
     */
    private function declaredFor(string $method, string $path, array $routes): array
    {
        $path = '/'.ltrim($path, '/');
        $match = null;

        foreach ($routes as $route) {
            if (in_array(strtolower($method), $route['methods'], true)
                && str_ends_with($route['uri'], $path)
                && ($match === null || strlen($route['uri']) < strlen($match['uri']))) {
                $match = $route;
            }
        }

        return $match['parameters'] ?? [];
    }
}
