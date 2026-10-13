<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Permissions;

use Funnelchat\PlatformGate\Exceptions\GateUnavailable;
use Funnelchat\PlatformGate\Exceptions\RouteNotAvailable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Qué permiso pide cada ruta.
 *
 * Por qué por config y no por parámetro de middleware: el piloto NO escribe rutas nuevas
 * (D7). La key entra por las ~123 rutas que ya existen en conversations, y tocarlas una
 * por una para colgarles un scope es exactamente el trabajo que D7 decidió no hacer.
 *
 * La regla por default sale del método HTTP, que es la señal que ya está ahí:
 *
 *   GET/HEAD/OPTIONS ......... {dominio}:read
 *   todo lo demás ............ {dominio}:write
 *
 * Y alrededor de eso, listas explícitas que el dominio declara:
 *
 *   allowed .. la superficie de las keys: lo no listado queda denegado. Vacía = nada.
 *   send ..... rutas que producen egress a WhatsApp → {dominio}:send
 *   operate .. rutas que mutan el recurso externo de forma irreversible → {dominio}:operate
 *   denied ... superficie amplificada → NINGÚN permiso la habilita
 *
 * Esas listas deciden el GRADO. El PREFIJO es el dominio, salvo que la ruta declare uno propio
 * en `permission_prefix` (Q48): `'api/v1/webhooks*' => 'webhooks'` hace que esas rutas pidan
 * `webhooks:read` / `webhooks:write` —o `webhooks:send`, `webhooks:operate` si además están
 * en esas listas— en vez de `{dominio}:{grado}`. Se declara el prefijo y no el permiso
 * entero a propósito: el grado sigue saliendo de donde sale siempre, así que declarar una
 * ruta no puede bajarle el grado a una de envío ni olvidarse de un método.
 *
 * La lista `denied` no es un permiso más estricto: es la superficie donde una llamada
 * produce N mensajes con N no acotado por el request. En conversations son diez rutas, y
 * tres de ellas ni siquiera parecen de envío — agregarle una etiqueta a un contacto
 * dispara un flujo que manda mensajes. Esa es la razón por la que la lista existe: desde
 * la tabla de rutas no se pueden enumerar los caminos de envío.
 *
 * Los patrones se machean contra el URI DEFINIDO de la ruta (`api/v1/contacts/{contact}`),
 * no contra el path del request, así que los ids no ensucian el patrón.
 *
 * Y el NOMBRE del placeholder se ignora: `{contact}`, `{contact_id}` y `{id}` son todos
 * lo mismo para el matcheo. No es comodidad, es una defensa. En conversations el mismo
 * recurso está registrado como `{contact}` en unas rutas y `{contact_id}` en otras, y la
 * ruta de envío de plantilla es `{template_id}` y no `{template}`. Un patrón escrito con
 * el nombre equivocado no falla ruidosamente: cae en la regla por método HTTP y clasifica
 * una ruta de ENVÍO como `write` — o sea, una key sin permiso de envío mandando mensajes.
 * Silencioso y exactamente al revés de lo que este archivo existe para lograr.
 */
final readonly class RoutePermissions
{
    /** Como el nombre de un dominio: minúsculas, dígitos y guiones (`wha-link`). Sin `:`. */
    private const PREFIX = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    private bool $permissionPrefixIsReadable;

    /**
     * @param string   $domain  El dominio que monta el portero: `conversations`, `accounts`, …
     * @param string[] $send    Patrones `METHOD uri` o `uri` que requieren `{domain}:send`.
     * @param string[] $operate Patrones que requieren `{domain}:operate`.
     * @param string[] $denied  Patrones que ningún permiso habilita.
     * @param string[] $allowed Los únicos patrones alcanzables por una key; todo lo demás se
     *                          rechaza como `denied`. Vacía = ninguna ruta. `['*']` abre toda la
     *                          superficie, y es una decisión que el dominio tiene que escribir.
     * @param array<string,string> $permissionPrefix Patrón => prefijo propio de permiso, en lugar
     *                          del dominio. No abre superficie: la ruta igual tiene que estar
     *                          en `allowed`.
     */
    public function __construct(
        private string $domain,
        private array $send = [],
        private array $operate = [],
        private array $denied = [],
        private array $allowed = [],
        private array $permissionPrefix = [],
    ) {
        $this->permissionPrefixIsReadable = self::isReadablePrefixMap($permissionPrefix);
    }

    /**
     * @throws RouteNotAvailable si la ruta cae en la superficie amplificada.
     * @throws GateUnavailable   si no hay ruta resuelta y por lo tanto no se puede clasificar, o
     *                           si `permission_prefix` no se puede leer o le da dos prefijos.
     */
    public function requiredFor(Request $request): string
    {
        $route = $request->route();

        if ($route === null) {
            // Sin ruta resuelta no hay URI canónico, sólo el path con ids adentro
            // (`api/v1/contacts/123`), que NO machea ningún patrón: todas las listas
            // dejarían de aplicar en silencio y la superficie amplificada quedaría
            // alcanzable con `write`. Es el peor fallo abierto posible acá, así que se
            // corta. No debería pasar —el portero corre después del routing— y si pasa,
            // es un problema de montaje que hay que ver, no tapar.
            throw new GateUnavailable('unresolved_route');
        }

        $uri = $route->uri();
        $method = strtoupper($request->method());

        if ($this->matchesAny($this->denied, $method, $uri)) {
            throw new RouteNotAvailable();
        }

        // Lo que no está en `allowed` no existe para una key — y una lista vacía no es "sin
        // restricción", es "nada declarado": no deja nada alcanzable. Con un default
        // abierto, cada ruta nueva queda alcanzable el día que alguien la agrega, y nadie
        // se entera hasta que la usan; un dominio que monta el portero y no declara su
        // superficie expone todo sin haberlo decidido. Acá una ruta nueva nace denegada,
        // y abrir todo (`['*']`) es algo que hay que escribir.
        if (! $this->matchesAny($this->allowed, $method, $uri)) {
            throw new RouteNotAvailable();
        }

        $prefix = $this->prefixFor($method, $uri);

        if ($this->matchesAny($this->send, $method, $uri)) {
            return "{$prefix}:send";
        }

        // `operate` va DESPUÉS de `send` y antes del fallback por método: es un eje
        // aparte, no un peldaño más alto. Una ruta que muta el recurso externo de forma
        // irreversible —resetear una sesión, purgar una cola— no puede viajar en el
        // mismo permiso que editar un nombre, aunque las dos sean POST.
        if ($this->matchesAny($this->operate, $method, $uri)) {
            return "{$prefix}:operate";
        }

        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            ? "{$prefix}:read"
            : "{$prefix}:write";
    }

    /**
     * El prefijo del permiso: el que la ruta declara en `permission_prefix`, o el dominio.
     *
     * Cierra en los dos casos donde no se puede saber cuál es. Un mapa ilegible —una lista en
     * vez de un mapa, un valor con `:` porque alguien escribió el permiso entero, un prefijo
     * vacío— no dice qué rutas quería cubrir, así que ninguna ruta se puede clasificar con
     * certeza: caer al dominio sería pedir `{dominio}:{grado}` justo en las rutas que alguien
     * quiso sacar de ahí. Y dos patrones que le dan a la misma ruta prefijos distintos no se
     * resuelven por orden de declaración: eso haría que reordenar la config cambie permisos.
     *
     * @throws GateUnavailable
     */
    private function prefixFor(string $method, string $uri): string
    {
        if (! $this->permissionPrefixIsReadable) {
            throw new GateUnavailable('unreadable_permission_prefix');
        }

        $prefixes = [];

        foreach ($this->permissionPrefix as $pattern => $prefix) {
            if ($this->matches($pattern, $method, $uri)) {
                $prefixes[$prefix] = true;
            }
        }

        if (count($prefixes) > 1) {
            throw new GateUnavailable('ambiguous_permission_prefix');
        }

        return (string) (array_key_first($prefixes) ?? $this->domain);
    }

    /** @param array<mixed> $map */
    private static function isReadablePrefixMap(array $map): bool
    {
        foreach ($map as $pattern => $prefix) {
            if (! is_string($pattern) || trim($pattern) === '') {
                return false;
            }

            if (! is_string($prefix) || preg_match(self::PREFIX, $prefix) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @param string[] $patterns */
    private function matchesAny(array $patterns, string $method, string $uri): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($pattern, $method, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $pattern, string $method, string $uri): bool
    {
        $pattern = trim($pattern);

        // "POST api/v1/broadcasts/{id}/execute" — con método.
        if (str_contains($pattern, ' ')) {
            [$patternMethod, $patternUri] = explode(' ', $pattern, 2);

            return strtoupper(trim($patternMethod)) === $method
                && Str::is($this->normalize(trim($patternUri)), $this->normalize($uri));
        }

        // "api/v1/broadcasts/*" — cualquier método.
        return Str::is($this->normalize($pattern), $this->normalize($uri));
    }

    /**
     * Deja el URI comparable: sin barra inicial y con todos los placeholders reducidos a
     * `{}`, para que el nombre del parámetro no decida si un freno se aplica o no.
     */
    private function normalize(string $uri): string
    {
        // Minúsculas: las rutas de Laravel machean sin distinguir mayúsculas, así que un
        // patrón no puede depender de cómo se tipeó la declaración.
        return strtolower((string) preg_replace('/\{[^}]*\}/', '{}', ltrim($uri, '/')));
    }
}
