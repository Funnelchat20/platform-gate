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
 * Y arriba de eso, dos listas explícitas que el dominio declara:
 *
 *   send ..... rutas que producen egress a WhatsApp → {dominio}:send
 *   denied ... superficie amplificada → NINGÚN permiso la habilita
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
    /**
     * @param string   $domain  El dominio que monta el portero: `conversations`, `accounts`, …
     * @param string[] $send    Patrones `METHOD uri` o `uri` que requieren `{domain}:send`.
     * @param string[] $denied  Patrones que ningún permiso habilita.
     */
    public function __construct(
        private string $domain,
        private array $send = [],
        private array $denied = [],
    ) {
    }

    /**
     * @throws RouteNotAvailable si la ruta cae en la superficie amplificada.
     * @throws GateUnavailable   si no hay ruta resuelta y por lo tanto no se puede clasificar.
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

        if ($this->matchesAny($this->send, $method, $uri)) {
            return "{$this->domain}:send";
        }

        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            ? "{$this->domain}:read"
            : "{$this->domain}:write";
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
