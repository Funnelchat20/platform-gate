<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Caller;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * ¿La columna `kind` existe en la tabla de tokens?
 *
 * Existe para separar dos cosas que se veían iguales desde el middleware y se deciden
 * al revés:
 *
 *   a) La columna **no existe**: accounts, su dueño, todavía no desplegó la migración de
 *      C1. Es la ventana de rollout — el paquete se instala en los cuatro dominios antes
 *      que la columna — y en esa ventana no hay ninguna key emitida, así que no hay nada
 *      que proteger. **Dejar pasar.** Cortar acá sería 503 en cada request del front.
 *
 *   b) La columna **existe** pero el atributo llega vacío: el dominio no la está
 *      seleccionando. conversations sobreescribe `findToken()` con una lista fija de
 *      columnas. El portero queda decorativo sin dar ninguna señal. **Cortar.**
 *
 * Antes las dos caían en "dejar pasar" y la única defensa era un flag que alguien se
 * tenía que acordar de prender. Ahora la respuesta sale del esquema.
 *
 * Se resuelve una vez por proceso. Si el esquema no se puede consultar, la respuesta es
 * `null` — no sabemos — y el llamador decide; nunca se toma como "no existe", que sería
 * la lectura optimista.
 */
final class KindColumnProbe
{
    /** @var array<string,bool|null> */
    private array $cache = [];

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function existsFor(object $token): ?bool
    {
        $connection = method_exists($token, 'getConnectionName') ? $token->getConnectionName() : null;
        $table = method_exists($token, 'getTable') ? $token->getTable() : 'personal_access_tokens';
        $key = ($connection ?? 'default').'.'.$table;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            return $this->cache[$key] = $this->db->connection($connection)
                ->getSchemaBuilder()
                ->hasColumn($table, 'kind');
        } catch (Throwable) {
            return $this->cache[$key] = null;
        }
    }
}
