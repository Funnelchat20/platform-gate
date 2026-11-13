# platform-gate

Middleware de Laravel para API keys: clasifica al llamador, chequea permisos por ruta,
aplica un tope diario de llamadas y registra el uso. Paquete `funnelchat20/platform-gate`.

Cuatro preguntas en cada request: **¿quién sos?**, **¿podés?**, **¿te pasaste?**, **anotá**.

> Software propietario de Funnelchat. El código es legible; su uso no está licenciado.
> Ver `LICENSE`.

---

## Lo que hace, y lo que deliberadamente no

| Hace | No hace |
|---|---|
| Clasifica al llamador: `api_key`, `web` o `unverified` | Frenos de envío — ritmo, plantillas, ventanas, opt-out |
| Chequea el permiso que pide la ruta | Idempotencia |
| Aplica el tope diario de **llamadas** por key y por dominio, con reinicio a las 00:00 UTC | Autorización por cuenta (que el recurso sea de quien llama) |
| Le da el mismo formato de error a toda llamada con key: `{"error", "message"}` | Los códigos propios de cada ruta: los elige y los documenta el dominio |
| Escribe una fila por llamada, con su id de pedido y lo que creó, y la purga a los 90 días | Emitir, validar o revocar keys |
| Devuelve `X-Request-Id` en cada respuesta | Leer el registro: se consulta por SQL |
| Reconoce lo que llega por el borde (`api.funnelchat.com`) y le cree host e IP | Rutear entre dominios — eso es el Worker |
| Le dice al dominio de qué workspace es la key, y reemplaza el `X-Workspace-Id` que mande una key | Filtrar por workspace: eso lo hace cada dominio con lo que el portero le deja |

Los frenos de envío viven en la costura de salida de cada aplicación, no acá y no en la
ruta: desde la tabla de rutas no se pueden enumerar los caminos de envío, porque hay rutas
que llegan al emisor por adentro. Y la idempotencia es específica del recurso: el portero
no puede saber si dos llamadas produjeron el mismo efecto.

---

## Instalación

```bash
composer require funnelchat20/platform-gate
php artisan vendor:publish --tag=platform-gate-config
php artisan migrate            # crea `api_call_log`
```

Y la purga del registro, todos los días (ver «El registro de llamadas»):

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('platform-gate:purge-usage')->daily()->onOneServer()->withoutOverlapping();
```

`onOneServer()`: con varias instancias corriendo el scheduler, corre en una sola.
`withoutOverlapping()`: si la corrida anterior sigue —la primera, en una tabla que nunca se
purgó, puede tardar—, la nueva no arranca. Las dos usan un lock del store de cache por
defecto, que tiene que ser compartido y soportar locks (Redis, base de datos, Memcached o
DynamoDB); con `array` o `file` no protegen nada entre instancias.

El middleware va sobre el grupo de rutas de API que ya existe. **No crea rutas nuevas.**

```php
// bootstrap/app.php
use Illuminate\Http\Request;

->withMiddleware(function (Middleware $middleware) {
    // Sin X-Forwarded-Host ni X-Forwarded-Prefix: el host público lo fija el portero, y
    // sólo cuando el pedido trae el secreto del borde. Ver «El borde».
    $middleware->trustProxies(
        at: '*',
        headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT,
    );

    // Global y al final del stack global: así todo el pedido ve el host y la IP del borde.
    $middleware->append(\Funnelchat\PlatformGate\Http\Middleware\TrustEdge::class);

    $middleware->appendToGroup('api', \Funnelchat\PlatformGate\Http\Middleware\PlatformGate::class);
})
->withExceptions(function (Exceptions $exceptions) {
    // Tus propios `render`, si tenés, van antes: ganan sobre los de la librería.

    // El 401 de `api/*`, igual en todos los dominios.
    \Funnelchat\PlatformGate\Http\UnauthenticatedResponse::register($exceptions);

    // El formato de error de las llamadas con key (0.10). Ver «El formato de error».
    \Funnelchat\PlatformGate\Http\ErrorResponse::register($exceptions);
})
```

Y, si el dominio publica su OpenAPI con Scramble, en un service provider:

```php
use Dedoc\Scramble\Scramble;

Scramble::afterOpenApiGenerated(new \Funnelchat\PlatformGate\OpenApi\AddErrorCode());
```

`PlatformGate` tiene que correr **después** de `auth:sanctum` —necesita el token resuelto— y
**antes** de cualquier cosa de la aplicación que pregunte quién llama o lea `X-Workspace-Id`
(ver «El workspace de una key»).

**`PlatformGate` va como middleware de ruta** —en el grupo `api`, como arriba—, **nunca
global**. Lo global es `TrustEdge`. El portero necesita la ruta resuelta para saber qué permiso
pide; y en `terminate` Laravel corre primero los middleware de ruta y después los globales, así
que el portero escribe la fila y saca `request_id` del log antes de que `TrustEdge` termine.

Requiere PHP ^8.2, Laravel ^11.9 o ^12.0, y Sanctum ^4.0.

**Lo probado es Laravel 12.** La suite pasa con las versiones más bajas que Composer deja
instalar (`composer update --prefer-lowest`: `laravel/framework` 12.69.0, testbench 10.0.0) y
con las de siempre. Laravel 11 no se pudo probar: todas las 11.x, la última incluida, tienen
avisos de seguridad sin parche, y Composer 2.9 en adelante no las instala. Los tres dominios
están en Laravel 12.

---

## Configuración

```php
// config/platform-gate.php
'domain' => 'midominio',

// La superficie de las keys: lo único que una key puede alcanzar. Vacía = nada.
'allowed' => [
    'api/v1/recurso*',
],

// Rutas que exigen el permiso de envío.
'send' => [
    'POST api/v1/recurso/{id}/enviar',
],

// Superficie amplificada: una llamada, N efectos. Ningún permiso la habilita.
'denied' => [
    'POST api/v1/recurso/{id}/ejecutar',
],

// Rutas que piden un prefijo de permiso propio en vez del dominio.
'permission_prefix' => [
    'api/v1/webhooks*' => 'webhooks',
],
```

**`allowed` es obligatoria para que una key llegue a algo.** Lo que no machea ahí se
rechaza con 403 `route_not_available`, y una lista vacía no deja **ninguna** ruta
alcanzable: un dominio que no declara su superficie no expone nada. Así una ruta nueva nace
denegada. Abrir todo es `['*']`, y es algo que hay que escribir. Sólo
afecta a llamadores `api_key`: el front no pasa por esta lista. `denied` se evalúa antes,
para poder excluir algo que machearía un patrón amplio.

**Una ruta cerrada no es secreta.** No es «como si no existiera»: una ruta que no existe da el
404 del dominio y una cerrada, el 403 `route_not_available`, así que quien tiene una key puede
saber qué rutas hay —sus URI, no lo que devuelven—. Es a propósito: el portal documenta el 403,
que le dice a quien integra que no hay permiso que pedir. Lo que no tiene que conocerse no se
esconde sacándolo de `allowed`. Hasta 0.10.0 este README y la config prometían lo contrario.

**Dentro de `allowed`, el permiso sale del método HTTP** y no hay que declararlo:
`GET`/`HEAD`/`OPTIONS` piden `{dominio}:read`, todo lo demás `{dominio}:write`. Declarar
scope ruta por ruta no escala a cientos de rutas.

**La escalera va en un solo sentido:** `send` implica `write`, y `write` implica `read`. Al
revés no: `write` **no** habilita envío — ésa es toda la razón por la que existe el tercer
grado.

**`operate` no está en la escalera.** Es un eje aparte, para aplicaciones donde el riesgo no
es enviar sino mutar un recurso externo con efectos irreversibles. No implica nada y nada lo
implica: meterlo en la escalera regalaría uno de los dos permisos sin que nadie lo haya
decidido.

**El nombre del placeholder no importa**: `{contact}`, `{contact_id}` y `{id}` machean
igual. Es una defensa, no comodidad — con matcheo literal, un patrón con el nombre
equivocado no falla ruidosamente: cae en la regla por método HTTP y puede clasificar una
ruta de envío como escritura. Hay un test de regresión.

**Un patrón con método cubre el verbo hermano de la misma ruta** (0.11.0): `GET …` cubre el
`HEAD`, y `PUT …` y `PATCH …` se cubren entre sí. Laravel le agrega `HEAD` a toda ruta `GET`, y
`Route::resource` registra el update como `PUT|PATCH`: es una ruta con dos verbos, y declarar
uno alcanza. Hasta 0.10.0 la comparación era literal: `GET …` en `allowed` le daba 403 a un
`HEAD`, y `PUT …` en `send` u `operate` dejaba el `PATCH` de esa ruta en `write`. Sólo vale
dentro de la misma ruta: un `PATCH` registrado aparte con el mismo URI es otra ruta, y `PUT …`
no la cubre.

### Permiso por ruta: `permission_prefix`

Las listas de arriba deciden el **grado**; el **prefijo** del permiso es el dominio. Una
ruta puede declarar otro prefijo, y entonces pide `{prefijo}:{grado}`:

```php
// config/platform-gate.php de accounts
'permission_prefix' => [
    'api/v1/webhooks*' => 'webhooks',
],
```

| Ruta | Pide |
|---|---|
| `GET api/v1/webhooks` | `webhooks:read` |
| `POST api/v1/webhooks`, `DELETE api/v1/webhooks/{id}` | `webhooks:write` |
| `POST api/v1/webhooks/{id}/test`, si además está en `send` | `webhooks:send` |
| `GET api/v1/devices` (no declarada) | `accounts:read`, como en 0.6 |

- **La escalera vale dentro del prefijo:** `webhooks:send` ⇒ `webhooks:write` ⇒
  `webhooks:read`. Nunca cruza: `accounts:write` no habilita nada de `webhooks`, y
  `webhooks:write` no habilita nada de `accounts`.
- **Se declara el prefijo, no el permiso entero.** El grado sigue saliendo del método y de
  `send` / `operate`, así que declarar una ruta no le puede bajar el grado a una de envío, y
  no hay un método que quede afuera por olvido. `'GET api/v1/webhooks' => 'webhooks:read'`
  **no** es válido: corta, como se explica abajo.
- **No abre superficie:** la ruta igual tiene que estar en `allowed`, y `denied` gana.
- **Falla cerrado.** Si el mapa no se puede leer —un valor con `:`, vacío, con mayúsculas o
  espacios, o una lista en vez de un mapa— ninguna ruta se puede clasificar con certeza, y
  las llamadas de key a rutas de `allowed` reciben 500 `gate_misconfigured`. Lo mismo si dos
  patrones le dan prefijos distintos a la misma ruta: no se elige por orden de declaración.
  Lo que ya estaba denegado sigue en 403.

### Cómo se leen el `kind` y los permisos del token

**`kind`:** sólo hay dos valores de token, `api_key` y `web`. Cualquier otro —uno que esta
versión no conoce, vacío, o `unverified`, que es un estado del portero y no un valor de la
columna— es un llamador que el portero no sabe clasificar: responde 500
`gate_misconfigured` y lo deja en el log. No es `web` por descarte, ni una key sin permisos.

**Permisos de una key:** el permiso que pide la ruta se busca **literal** en la lista de
`abilities` del token. `*` es un string más: no habilita nada a una key. La lista sale de
`getAbilities()` si el modelo lo tiene y, si no, del atributo `abilities` —el
`PersonalAccessToken` de Sanctum no expone ningún método para leerla—. Si no se puede
leer (la columna no se selecciona, el valor no es una lista), responde 500
`gate_misconfigured`: no pudo evaluar, y no deja pasar. La misma lista es la que el dominio
ve en `Caller::$permissions`.

### Si tu aplicación restringe columnas en `findToken()`, incluí `kind`, `abilities` y `workspace_id`

Una aplicación puede sobreescribir `findToken()` en su modelo `PersonalAccessToken` para
traer sólo algunas columnas. Si `kind` no está en esa lista, el atributo llega `null` y el
portero no puede clasificar al llamador. Si falta `abilities`, no puede evaluar los
permisos de una key, y corta con 500 `gate_misconfigured`. Si falta `workspace_id` y la
columna existe en la tabla, no sabe de qué workspace es la key, y también corta con 500 (0.9;
hasta 0.10.0, los dos eran 503).

```php
protected static array $selectColumns = [
    'id', 'tokenable_type', 'tokenable_id', 'token', 'abilities', 'expires_at',
    'kind', 'workspace_id',
];
```

`workspace_id` va en la lista **recién cuando la columna existe**: la crea accounts. Pedir una
columna que no existe rompe la consulta de cada token.

El comportamiento cuando `kind` no se puede leer lo decide `PLATFORM_GATE_BLOCK_ON_UNREADABLE_KIND`.
En `true`, un token cuyo `kind` no se puede leer, con el esquema sin consultar, devuelve 503 en
vez de seguir (con la columna en la tabla corta siempre, con 500). Ponelo en
`true` apenas la columna esté desplegada en todos los ambientes.

### La resolución de cuenta hay que sobreescribirla

El default devuelve el id del usuario del token y declara **que no sabe** si es el dueño o
un asiento. Es a propósito: un límite llaveado por asiento se multiplica por la cantidad de
asientos.

```php
'account_resolver' => fn ($user) => [\App\Models\User::resolveOwnerId($user), true],
```

---

## Cómo se lee desde la aplicación

```php
use Funnelchat\PlatformGate\Caller\CallerContext;
use Funnelchat\PlatformGate\Caller\CallerKind;

$caller = app(CallerContext::class)->get();

// `match` SIN rama default, a propósito: cuando el enum crezca, esto tira
// UnhandledMatchError en tus tests en vez de elegir en silencio.
$failClosed = match ($caller->kind) {
    CallerKind::ApiKey     => true,
    CallerKind::Web        => false,
    CallerKind::Unverified => false,
};
```

`$caller->toArray()` / `Caller::fromArray()` para propagarlo a un job de cola: un job que
produce efectos externos tiene que poder saber que lo originó una key, y de qué workspace.

**Nada de esto se expone al cliente.** Ni el `kind`, ni el `key_id`, ni ningún
identificador de cuenta — ni en respuestas, ni en headers, ni en webhooks. Lo único que el
portero le agrega a la respuesta es `X-Request-Id`, un id al azar del pedido.

### El workspace de una key

Cada key es de un workspace, y el cliente no lo elige (0.9). Llega al dominio por dos lados,
que dicen lo mismo:

```php
$workspaceId = app(CallerContext::class)->get()->workspaceId(); // ?int
```

| Llamador | `workspaceId()` | `X-Workspace-Id` que ve el dominio |
|---|---|---|
| Key con workspace (`workspace_id = 7` en el token) | `7` | `7`, mande lo que mande el cliente |
| Key sin workspace (`null` o vacío en el token, o la columna todavía no existe) | `null` | ninguno: se quita el que mandó el cliente |
| Sesión web | `null` | el suyo, intacto |
| Sin token | `null` | el suyo, intacto (el 401 lo da `auth:sanctum`) |

**`null` en una key es el workspace por defecto de la cuenta.** El dominio lo resuelve como
lo resuelve el panel sin header. No es «todos los workspaces» ni «la unión de la cuenta».

**En la sesión web no significa nada:** su workspace lo sigue diciendo su header, como antes.
`workspaceId()` sólo habla de keys.

**El header se reemplaza dentro del portero, justo antes de pasarle el pedido al dominio**, y
sólo si la llamada pasó: un rechazo no llega al dominio. Lo que corrió **antes** del portero ya
leyó el header del cliente:

- un middleware **global** que lea `X-Workspace-Id` lo ve sin reemplazar, siempre: lo global
  corre antes que lo de ruta, y el portero es de ruta;
- uno del grupo `api` o de la ruta lo ve reemplazado sólo si queda **después** del portero. Un
  `appendToGroup('api', …)` posterior al del portero, o un middleware de ruta, quedan después;
  ojo con la lista de prioridad de Laravel, que puede reordenarlos;
- lo que se resuelva y se guarde antes —un singleton que lee el header al construirse, un
  service provider— también queda con el del cliente.

La prueba que lo confirma es del dominio, por el kernel HTTP de verdad: una key de workspace A
que manda `X-Workspace-Id: B` tiene que operar en A.

**En un job no hay header:** el workspace viaja en `toArray()` / `fromArray()`. Un array de 0.8.x
—un job encolado antes de subir— se lee con `null`, o sea el por defecto.

Si el token de una key no trae `workspace_id` y la columna **existe**, o trae algo que no es un
id, el portero no sabe de qué workspace es la key: responde 500 `gate_misconfigured` (503
`gate_unavailable` si además no pudo consultar el esquema) y lo deja en el log. No la manda al por defecto, porque una key de otro workspace operaría ahí sin ninguna
señal.

---

## El registro de llamadas

Cada llamada de key deja una fila en `api_call_log` (`PLATFORM_GATE_USAGE_TABLE`), en la
conexión `PLATFORM_GATE_USAGE_CONNECTION`. También las rechazadas: alguien golpeando una ruta
que no puede usar es una pregunta de producto. Nadie la lee desde el código; se consulta por
SQL.

**Nueve campos y ni uno más (D12).** Hasta 0.7 eran seis; el 17-10-2026 se sumaron tres,
porque con seis el registro no servía para rastrear un problema (ver «Actualizar desde 0.7»):

| Columna | Qué es |
|---|---|
| `key_id` | `key_` + HMAC del id del token. Nunca el token. |
| `route` | El URI **definido** (`contacts/{contact}/messages`), no el path real: así ningún id de persona entra por ahí. |
| `method`, `status_code`, `duration_ms` | Lo que dicen. |
| `occurred_at` | Cuándo empezó, en UTC. |
| `request_id` | El id del pedido: el mismo que el cliente recibe en `X-Request-Id`. Con índice. |
| `resource_type`, `resource_id` | Lo que la llamada creó, si el controlador lo declaró: el tipo y la clave interna del recurso (un entero positivo, un UUID o un ULID). Si no, `null`. |

Sin PII y sin contenido: ni teléfonos, ni cuerpos, ni texto escrito por personas. El
`resource_id` es un id **interno**. Una fila nunca se actualiza; lo único que la borra es la
purga.

### El id del pedido: `X-Request-Id`

Cada respuesta que pasa por `TrustEdge` o por el portero trae `X-Request-Id`: las que
contesta el dominio, un 500, y los rechazos del propio portero (403, 429, 500, 503). El 401 de
`UnauthenticatedResponse` también. Es el id que el integrador cita al reportar un problema, y
con el que soporte encuentra la fila y los logs.

- **Si el pedido viene del borde** —con el secreto válido— y trae `X-Funnelchat-Request-Id`
  con un UUID, se usa ése: el cliente ve el mismo id de punta a punta.
- **Si no**, el portero genera un UUID v4. Un `X-Funnelchat-Request-Id` que llega sin el
  secreto lo pudo mandar cualquiera, y no se le cree; uno del borde que no es un UUID se
  descarta y queda un warning en el log, sin el valor.
- **En lo que pasa por el portero, el del portero gana.** Si el dominio pone su propio
  `X-Request-Id` en la respuesta, se reemplaza: si no, el cliente citaría un id que no está en
  ninguna fila.
- **En el resto, el del dominio se respeta.** `TrustEdge` es global: en una respuesta que no
  pasó por el portero sólo pone `X-Request-Id` si no trae uno. Ahí no hay fila que citar, y el
  id propio del dominio es el que conocen sus logs.
- **Ojo: en ese caso el `request_id` del log no es el que ve el cliente.** `TrustEdge` ya lo
  puso en el contexto del log al principio del pedido, antes de saber que el dominio iba a
  contestar con su propio id. Sólo pasa si el dominio pone su propio `X-Request-Id`, y sólo
  fuera del portero: para encontrar ese pedido en el log, buscá por el id del dominio, no por
  `request_id`.
- **Va al log.** Se suma como `request_id` al contexto compartido del log del dominio
  (`Log::shareContext()`) en cuanto se decide: si `TrustEdge` está montado, desde el
  principio del pedido; si no, desde que corre el portero. Al terminar el pedido (el
  `terminate` del portero y de `TrustEdge`) se saca, y el resto del contexto del dominio queda
  como estaba: no depende de cómo maneje el proceso el estado entre pedidos. Lo que el dominio
  loguee en su propio `terminate` después del portero ya no lo lleva. Sólo se saca si el valor
  es el que puso el portero: si el dominio lo pisó con un `request_id` suyo, ése queda.

Desde el dominio, para un job que encola o un log propio:

```php
use Funnelchat\PlatformGate\Http\RequestId;

$id = app(RequestId::class)->for($request);   // decide si hace falta; siempre el mismo
$id = RequestId::of($request);                // el ya decidido, o null
```

### Lo que creó la llamada: `CreatedResource`

El controlador declara el recurso que creó —un tipo corto y su id— y el portero lo guarda en
la fila al terminar el pedido:

```php
use Funnelchat\PlatformGate\Usage\CreatedResource;

public function store(StoreMessageRequest $request): JsonResponse
{
    $message = $this->messages->send($request->validated());

    CreatedResource::declare('message', $message->id);

    return response()->json(new MessageResource($message), 201);
}
```

```php
CreatedResource::declare(string $type, int|string $id): void
CreatedResource::current(): ?CreatedResource   // lo declarado en este pedido
```

No recibe el pedido: la declaración queda en el contexto del request (`scoped`, como
`CallerContext`), no en los atributos del pedido. El FormRequest que recibe el controlador es
una copia del pedido, y lo que se escribe en sus atributos el portero no lo ve. Se puede
declarar desde el controlador o desde un servicio, con o sin FormRequest.

- **Tipo**: minúsculas, números y `_`, hasta 32, empezando con letra: `message`, `contact`,
  `tag`, `note`, `scheduled_message`, `webhook_subscription`.
- **Id**: la clave interna del recurso, **nunca un dato de una persona**. Sólo tres formas:
  un entero positivo (sin ceros adelante, hasta 18446744073709551615, el tope de un
  `BIGINT UNSIGNED`), un UUID o un ULID. Se guarda el
  UUID en minúsculas y el ULID en mayúsculas. Cualquier otra cosa —un teléfono con `+`, un
  email, un texto, un `msg_…`, un `0`— se descarta. Un teléfono sin `+` no se distingue de un
  entero: pasá siempre la clave primaria del recurso (`$message->id`), nunca un campo suyo.
- **Un recurso por llamada.** Si se declara dos veces, queda la última válida. El portero
  vacía la declaración al empezar y al terminar cada pedido: no queda pegada al siguiente.
- **Una declaración inválida no rompe la llamada.** Cuando el controlador declara, el recurso
  ya existe: un 500 ahí haría que el cliente reintente y lo cree dos veces. No se guarda, queda
  un warning en el log —sin el valor— y la fila sale sin recurso. Un test del dominio que
  mire la fila lo encuentra.
- **Sin declaración**, la fila sale sin recurso, como en 0.7. Las rutas que no crean nada no
  declaran.
- Sólo cuenta en las llamadas de key: el front no deja fila. Un rechazo del portero no llega al
  controlador, así que sale sin recurso.

### La purga: `platform-gate:purge-usage`

Borra las filas con `occurred_at` anterior a N días, en lotes de 1000. N es
`PLATFORM_GATE_USAGE_RETENTION_DAYS` (`platform-gate.usage_retention_days`), **90 por
defecto**. Borra lo estrictamente anterior al corte, y nada más.

- **Falla cerrado.** Con N menor que 1, o algo que no es un entero (`90d`, `1.5`, vacío), no
  borra nada, lo deja como error en el log y termina con código 1.
- Al log va sólo la cantidad borrada y el corte. Nada de las filas.
- **Sin índice nuevo:** borra por rango de la clave primaria, hasta la primera fila que se
  queda, sin dejar de filtrar por fecha. La fila se escribe cuando el pedido termina y
  `occurred_at` es cuándo empezó, así que una fila vieja de un pedido lento que quedó detrás
  de una nueva se borra en la corrida siguiente: la purga se equivoca hacia guardar, nunca
  hacia borrar.
- **Una fila con fecha futura no la traba.** Las de más de un día en el futuro (reloj
  adelantado, dato roto) no cuentan para el límite y no se borran; si hay alguna antes del
  límite, queda un warning con cuántas.
- No se programa sola: el dominio la agrega a su schedule (ver «Instalación»).

---

## El borde: la URL única de la API

La API pública tiene una sola dirección, `https://api.funnelchat.com` (staging:
`https://api-staging.funnelchat.com`): un Worker de Cloudflare que reparte a los dominios.
En cada pedido que reenvía borra lo que haya mandado el cliente y pone:

| Cabecera | Valor |
|---|---|
| `X-Funnelchat-Edge` | El secreto del borde. |
| `X-Forwarded-Host` | El host público del pedido. |
| `X-Funnelchat-Client-IP` | La IP del cliente (`CF-Connecting-IP`). |

**El portero le cree al pedido sólo con el secreto válido.** Lo compara en tiempo constante
contra dos valores, el actual y el siguiente, para poder rotar sin cortar. Con secreto
válido:

- toma `X-Forwarded-Host` si está en `PLATFORM_GATE_PUBLIC_HOSTS`, y lo fija como host del
  pedido: `$request->url()`, los links del paginador y `url()` / `route()` salen con el host
  público;
- toma `X-Funnelchat-Client-IP` como `X-Forwarded-For`, así `$request->ip()` —el log y los
  límites por IP del dominio— devuelve la IP del cliente.

Sin secreto válido no le cree ninguna de las dos. Para que eso alcance, el dominio tampoco
tiene que creerle `X-Forwarded-Host` en su `trustProxies`: si se lo cree, Laravel le cree al
cliente antes de que el portero pueda decir nada. Es lo que se vio en staging el 09-10.

**El secreto no sigue en el pedido.** Se saca apenas se lee, valga o no: de las cabeceras y
del `server` del pedido, y de `$_SERVER` y `$_ENV` (`HTTP_X_FUNNELCHAT_EDGE`). La librería no
loguea nada antes de sacarlo, ni siquiera sus errores de configuración. Lo que corre
después —logs, reportes de errores— no lo ve.

**Lo que esta librería no puede sacar.** Dos cosas: lo que se loguea **antes** de
`TrustEdge` —el dominio u otro paquete, desde un middleware global que corre primero—, y lo
que PHP guarda del pedido fuera de esos arrays. En PHP-FPM, `getallheaders()` devuelve las
cabeceras que mandó el servidor web, no las de `$_SERVER`: ahí la cabecera sigue durante
todo el pedido, y un reporte de errores que arma el pedido con esa función la encuentra
igual.

**Si usás Sentry**, el `before_send` es lo que cierra las dos. Ante un log o una excepción
anterior a su middleware, Sentry arma el pedido desde los globales de PHP. Si tu
`before_send` ya limpia cabeceras, sumá `X-Funnelchat-Edge`. Si no tenés uno, agregalo: que
saque `X-Funnelchat-Edge` de las cabeceras del pedido del evento, sin distinguir mayúsculas,
y devuelva el evento. Si mandás transacciones (performance), lo mismo en
`before_send_transaction`. Con otro servicio de errores, lo mismo en lo que use para limpiar
cabeceras.

**Un secreto de menos de 32 caracteres no cuenta.** Queda en el log como error y no se usa.
Generalo con `openssl rand -hex 32`.

**La exigencia** (`PLATFORM_GATE_REQUIRE_EDGE`), apagada por defecto. Encendida, una key que
no llega con el secreto válido recibe 403 `use_public_endpoint`, con la URL pública en el
mensaje (`PUBLIC_API_URL`). A la sesión del panel (`web`) nunca se le pide el secreto. Si la
exigencia está encendida y el dominio no tiene ningún secreto configurado, el portero no
tiene contra qué comparar: 500 `gate_misconfigured`, nunca dejar pasar.

**`TrustEdge` y el portero.** `TrustEdge` es el middleware global que hace la inspección
para todo el pedido. Si el dominio no lo monta, el portero la hace igual al correr —la
exigencia no depende de que alguien se acuerde—, pero lo que corre antes del portero ve el
host interno y la IP del Worker.

---

## Respuestas de rechazo

| Situación | Status | `error` | Terminal o reintentable |
|---|---|---|---|
| Sin credencial o credencial inválida en `api/*` (con `UnauthenticatedResponse` enganchado) | 401 | `unauthenticated` | Terminal |
| Le falta el permiso | 403 | `permission_denied` | Terminal |
| Ruta fuera de `allowed`, o de la superficie amplificada | 403 | `route_not_available` | Terminal — no hay permiso que pedir. Distinto del 404 de una ruta que no existe |
| Una key llegó directo a un host interno y el dominio exige el borde | 403 | `use_public_endpoint` | Terminal — hay que cambiar la URL |
| Se pasó del tope diario (por key y por dominio) | 429 + `Retry-After` | `daily_cap_exceeded` | Terminal hasta las 00:00 UTC |
| **No se pudo evaluar, por algo que se arregla solo** | 503 + `Retry-After` | `gate_unavailable` | **Reintentable** |
| **No se pudo evaluar, por un error de configuración nuestro** (0.11.0) | 500, sin `Retry-After` | `gate_misconfigured` | **No reintentable** — hasta que lo corrijamos |

Todas llevan `X-Request-Id` (0.8).

**El 403 `permission_denied` nombra el permiso como el panel** (0.8.1), nunca con el literal:

```json
{"error": "permission_denied", "message": "A esta API key le falta el permiso «Contactos y conversaciones → Enviar». Agregalo desde Desarrolladores, en el panel."}
```

Si el panel no nombra el permiso que pide la ruta, el mensaje es genérico: «A esta API key le
falta un permiso que esta ruta pide. Revisá sus permisos en Desarrolladores, en el panel.» El
literal sigue en `PermissionDenied::$requiredPermission`, para el código del dominio; no sale en
la respuesta. Lo que el cliente machea es `error`, no el texto.

La tabla está en un solo lugar, `PermissionLabels::for()`, para que un dominio que tenga sus
propios mensajes nombre los permisos igual, sin copiarla:

```php
use Funnelchat\PlatformGate\Permissions\PermissionLabels;

PermissionLabels::for('accounts:operate'); // 'Números de WhatsApp → Crear, conectar y borrar'
PermissionLabels::for('accounts:send');    // null: el panel no lo ofrece
```

| Permiso | En el panel |
|---|---|
| `conversations:read` / `write` / `send` | Contactos y conversaciones → Ver / Editar / Enviar |
| `communities:read` / `write` / `send` | Grupos y campañas → Ver / Editar / Enviar |
| `communities:operate` | Grupos y campañas → Administrar grupos |
| `accounts:read` / `write` | Números de WhatsApp → Ver / Editar |
| `accounts:operate` | Números de WhatsApp → Crear, conectar y borrar |
| `webhooks:read` / `write` | Avisos (webhooks) → Ver / Administrar |

Con `null`, el texto es genérico; nunca se cae al literal.

"No se pudo evaluar" son dos casos, con dos respuestas (0.11.0):

- **503 `gate_unavailable`, con `Retry-After: 5`:** lo que se arregla solo. El store del
  contador no responde; o falta `kind` o `workspace_id` en el token y el esquema de la base no
  se pudo consultar para saber por qué.
- **500 `gate_misconfigured`, sin `Retry-After`:** lo que no se arregla esperando, porque es la
  config o el código del dominio, o el dato guardado en el token. `kind` con un valor que esta
  versión no conoce, o que el modelo no selecciona (la columna existe); la lista de permisos de
  la key que no se puede leer; el `workspace_id` que el modelo no selecciona o que no es un id;
  `permission_prefix` ilegible o con dos prefijos para la misma ruta; la exigencia del borde
  encendida sin ningún secreto; el portero montado donde no hay ruta resuelta.

  ```json
  {"error": "gate_misconfigured", "message": "No pudimos validar esta llamada por un problema de configuración de nuestro lado. Reintentar no lo resuelve: escribile a soporte de Funnelchat con el X-Request-Id de esta respuesta."}
  ```

Hasta 0.10.0 todo salía como el primero, y un cliente que seguía la documentación reintentaba
cada cinco segundos para siempre. Es 500 y no 503 porque 503 dice «por ahora» y los clientes HTTP
lo reintentan solos. Las dos son `GateUnavailable` en el código —`GateMisconfigured` la
extiende—, así que el dominio que la atrapaba sigue atrapando las dos. Las dos quedan en el log
con su `stage`.

El 401 lo tira el `auth:sanctum` del dominio, antes de que corra el portero; por eso es algo
que el dominio engancha. Fuera de `api/*` no cambia nada.

En los dos casos, **corta, no deja pasar**: si el store se cae, también. Dejar pasar
cuando no se puede evaluar es el agujero clásico — el cliente automatizado se pasa del
límite justo cuando el control no responde. Y "no pude evaluar" nunca se colapsa con
"evalué y está denegado": son dos respuestas para el cliente y dos métricas para vos.

---

## El formato de error de las llamadas con key

Toda respuesta de error a una llamada con key trae lo mismo (0.10, contrato
`1113-errores-y-limites`):

```json
{"error": "not_found", "message": "No se encontró lo que se pidió."}
```

`error` es un código estable, en `snake_case`: es lo que quien integra compara. `message` es para
personas, en español, y puede cambiar. El 422 trae además `errors`, campo por campo. **Nunca se
quita una clave que la respuesta ya traía**: se agrega `error` donde falta.

**Sólo a las llamadas con key.** El panel usa las mismas rutas `api/*` con su sesión o su token
`web`, y recibe exactamente lo de antes: el mismo status, el mismo cuerpo, el mismo texto.

| Qué | Quién lo hace | Qué hace el dominio |
|---|---|---|
| Las excepciones de Laravel | `ErrorResponse`, en `bootstrap/app.php` | Engancharlo (una línea, ver «Instalación») |
| Las respuestas de error que el dominio arma a mano | El portero, al devolver la respuesta | Nada: viene con la versión |
| La especificación | `AddErrorCode`, un transformador de Scramble | Engancharlo, si publica con Scramble |

### Las excepciones de Laravel: `ErrorResponse`

| Excepción —o lo que Laravel convierte en ella— | Status | `error` | `message` |
|---|---|---|---|
| `ValidationException` | 422 | `validation_failed` | El del validador del dominio, en su idioma. Y `errors` |
| Modelo, registros o ruta que no existen | 404 | `not_found` | Genérico |
| `AuthorizationException` | 403 | `forbidden` | El de la policy (`Response::deny('…')`) si dio uno; si no, genérico |
| El `throttle` de Laravel | 429 + `Retry-After` | `rate_limited` | Genérico |
| Cualquier otra `HttpException` (`abort(…)`) | La suya | El de su status (abajo) | El del dominio si escribió uno; si no, genérico |

Se conservan las cabeceras de la excepción (`Retry-After`, `X-RateLimit-*`) y la respuesta lleva
`X-Request-Id`. Sale en JSON aunque el pedido no lo pida: a una key no se le contesta con una
página HTML.

**El texto de Laravel no sale.** «No query results for model [App\Models\Contact] 5.», «This
action is unauthorized.», «Too Many Attempts.», «The route … could not be found.»: en inglés, y el
primero con el nombre de una clase. En su lugar va el genérico del código. Se distingue sin
comparar textos: lo que Laravel convirtió desde otra excepción, su `throttle` y lo que corta el
router antes de resolver una ruta son de Laravel; un `abort()` o una `HttpException` que tira el
dominio, del dominio.

**El código según el status** (`ErrorCode`), cuando el dominio no tiene uno más preciso:

| Status | `error` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` |
| 402 | `payment_required` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` |
| 413 | `payload_too_large` |
| 422 | `validation_failed` |
| 429 | `rate_limited` |
| 500 | `internal_error` |
| 503 | `unavailable` |

Otro 4xx sale `bad_request`, y otro 5xx, `internal_error`: nunca un código que el portal no
documente. Los del portero (`permission_denied`, `daily_cap_exceeded`…) no cambian.

**Lo que no toca:** una `ValidationException` que trae su propia respuesta (un `FormRequest` con
su `failedValidation`), una `HttpResponseException`, una excepción con su propio `render()`, un
`render` del dominio registrado antes que éste —el primero que contesta gana— y un 500 que no es
una `HttpException`. Eso sale como lo arma Laravel o el dominio; si pasó por el portero, con
`error` agregado (abajo). El 401 es de `UnauthenticatedResponse`. Fuera de `api/*` no hace nada;
otro prefijo: `ErrorResponse::register($exceptions, 'v2/*')`.

### Cómo se sabe que la llamada es de una key

La excepción puede saltar **antes** de que corra el portero: una ruta que no existe —el router
corta antes de cualquier middleware de ruta—, o un `throttle` o un binding de modelo, que la
prioridad de middlewares de Laravel pone delante del portero. Por eso no alcanza con preguntarle
al portero (`Caller\ApiKeyRequest`):

1. Si el portero ya clasificó la llamada, lo que dijo: `api_key` sí, `web` no.
2. Si no, el `kind` del token, igual que lo mira el portero: el del token que ya resolvió
   `auth:sanctum`, o el del `Bearer` del pedido, buscado con el modelo de tokens del dominio
   (`findToken()`, el de Sanctum o el que el dominio sobreescriba). Es una consulta, sólo en el
   camino de error y sólo si el portero no clasificó.

La forma del token no alcanza: una key y el token `web` del panel son tokens de Sanctum iguales;
los distingue `kind`. **Ante la duda, no es key**: si `kind` no se puede leer o la consulta falla,
la respuesta queda como la arma Laravel —el panel no puede cambiar por un error nuestro— y queda
un warning en el log. No se mira si la key venció: esto no decide si la llamada pasa, sólo cómo se
le contesta a quien presentó la key.

### Lo que el dominio arma a mano

Una respuesta JSON de error (4xx o 5xx) sin `error` lo gana al pasar por el portero, en las
llamadas con key:

```text
403 {"message": "Esta API key no puede usar ese workspace.", "code": "workspace_forbidden"}
  → {"error": "workspace_forbidden", "message": "Esta API key no puede usar ese workspace.", "code": "workspace_forbidden"}

409 {"message": "El contacto está en una campaña activa."}
  → {"error": "conflict", "message": "El contacto está en una campaña activa."}
```

- Si trae `code` (un string) y no `error`, `error` toma ese valor. Si no, el de su status.
- `error` va primero y el resto del cuerpo queda byte por byte como estaba. Un cuerpo que ya trae
  `error`, con el valor que sea, no se toca.
- Sólo un objeto JSON. Una lista, un cuerpo que no es JSON o una descarga salen como estaban.
- Sólo lo que vuelve por el portero: lo que responde un middleware del dominio que corre antes
  que él sale como lo armó.

Viene con la versión, sin enganchar nada. Lo que Laravel rinde de una excepción que salta dentro
de la ruta también vuelve por el portero, así que sin `ErrorResponse` también gana `error`; pero
con el texto de Laravel, sólo si el pedido pidió JSON y sólo si saltó después del portero. Por
eso `ErrorResponse` se engancha igual.

### La especificación: `AddErrorCode`

Sin esto, el OpenAPI que genera Scramble documenta el 403, el 404 y el 422 sólo con `message`
—es lo que deduce de las excepciones de Laravel—, aunque la API ya devuelva `error`. Es un
transformador de documento: corre cuando Scramble terminó de armarlo.

```php
// app/Providers/AppServiceProvider.php, en boot()
use Dedoc\Scramble\Scramble;

Scramble::afterOpenApiGenerated(new \Funnelchat\PlatformGate\OpenApi\AddErrorCode());
```

Eso engancha la API por defecto de Scramble. Si el dominio publica otra
(`Scramble::registerApi(...)`), en la configuración de esa:
`Scramble::configure('<api>')->withDocumentTransformers(new AddErrorCode())` (Scramble 0.13).

A cada respuesta 4xx o 5xx con cuerpo `application/json` que sea un objeto sin `error` —las
`*Exception` de `components.responses` y las de cada operación— le agrega `error`: primera
propiedad, obligatoria, string, con el código genérico de su status como ejemplo y, en el 403, el
429, el 500 y el 503, los códigos del portero que pueden salir con ese status. Si el cuerpo trae `code`,
`error` lleva lo mismo. Lo que ya trae `error`, un cuerpo que es una referencia a un esquema, una
lista o algo que no es JSON no se toca. **Los códigos propios de cada ruta los documenta el
dominio.**

La librería no exige Scramble: `AddErrorCode` no implementa nada suyo y sólo se carga si el
dominio lo usa. Probado con Scramble 0.12.36 y 0.13.53.

---

## Actualizar desde 0.10.0

Versión **0.11.0**. Sin cambios de config ni migraciones, pero **puede romper a un consumidor**, y
por eso es MINOR y no PATCH: cambian algunas respuestas a llamadas con key —todas en casos que
estaban mal— y `Event` rechaza payloads que antes dejaba salir. Antes de subir, revisá los tests del
dominio que comparan esas respuestas y los payloads de sus eventos (abajo).

**Por qué.** La revisión de huecos del 13-11 (iniciativa api-abierta; `HUECOS-2026-11-13.md` y el
barrido `huecos-1113/barrido-portal-borde-gate.txt`, puntos 7, 9, 10 y 11, en el repo de
coordinación) encontró cinco lugares donde la librería no hacía lo que decía. Es la segunda rueda
del contrato `1113-errores-y-limites` (commit `ce7a8c73265d` del repo de coordinación), después de
0.10.0:

1. **Los patrones con método comparaban el verbo literal.** `GET …` en `allowed` le daba 403 a un
   `HEAD`, y `PUT …` en `send` u `operate` dejaba en `write` el `PATCH` de la misma ruta —las rutas
   `resource` de Laravel registran el update con los dos verbos—: un grado menor por cambiar el
   verbo.
2. **«Como si no existiera» no era cierto.** El README y la config lo prometían para lo que queda
   fuera de `allowed`, pero la respuesta es 403 `route_not_available` y una ruta inexistente da el
   404 del dominio: se puede saber qué rutas hay. El portal documenta el 403, así que se queda; lo
   que cambia es la promesa. Y el mensaje decía «en el piloto», con la API ya en producción.
3. **La lista de claves prohibidas del sobre se podía saltear.** Sólo recorría arrays y comparaba
   el nombre exacto: pasaban `userId`, `accountId` y cualquier objeto —`stdClass`,
   `JsonSerializable`, un Resource— con `user_id` adentro, que `toJson()` igual mandaba.
4. **Los errores de configuración invitaban a reintentar para siempre.** Respondían 503
   `gate_unavailable` con `Retry-After: 5`, documentado como reintentable: un cliente que seguía
   la doc reintentaba cada cinco segundos algo que sólo se arregla corrigiendo el dominio.
5. **No existían los vectores de prueba de la firma** que este repo dice compartir con los
   snippets del portal y otras implementaciones: sólo había casos sueltos dentro de un test.

**Qué cambia:**

- **Patrones:** `GET …` cubre el `HEAD`, y `PUT …` y `PATCH …` se cubren entre sí, cuando la ruta
  resuelta acepta los dos verbos. Ver «Configuración».
- **El texto del 403 `route_not_available`:** «Esta ruta no está disponible para API keys. No es un
  permiso que falte: ninguna key la habilita.» (sin «en el piloto»). `error` y el status no
  cambian.
- **`Event`** revisa lo que va a salir en el JSON —objetos incluidos— y compara los cuatro nombres
  prohibidos sin importar cómo se escriban. Ver «La lista de claves prohibidas es un piso».
- **`500 gate_misconfigured`, nuevo, sin `Retry-After`**, para lo que no se arregla esperando:
  `kind` desconocido o no seleccionado, la lista de permisos ilegible, el `workspace_id` no
  seleccionado o que no es un id, `permission_prefix` ilegible o ambiguo, la exigencia del borde
  sin secreto y el portero sin ruta resuelta. **503 `gate_unavailable`** queda para lo que se
  arregla solo: el store del contador, y el esquema de la base que no se pudo consultar. Ver
  «Respuestas de rechazo». En el código, `GateMisconfigured` extiende `GateUnavailable`: un
  `catch (GateUnavailable)` sigue atrapando las dos, y `$stage` no cambia.
- `AddErrorCode` documenta `gate_misconfigured` en las respuestas 500.
- **`tests/vectors/webhook-signature.json`**, nuevo: los vectores de la firma `v1`, con los que
  prueba `Signature` y contra los que tienen que probar los snippets del portal. La firma no
  cambia.
- No cambian `Caller`, `api_call_log`, el tope, los permisos ni los demás códigos. El panel
  tampoco, salvo en un caso roto: un token cuyo `kind` no se puede leer con la columna en la tabla,
  o con un valor que esta versión no conoce, recibe 500 en vez de 503, sea de quien sea —el
  portero no sabe si es el panel—.

**Antes de subir, en el dominio:**

1. **Tests que esperan 503 `gate_unavailable`** (o `Retry-After: 5`) en un caso de configuración
   de la lista de arriba: ahora es 500 `gate_misconfigured`, sin `Retry-After`.
2. **Tests que comparan el texto del 403 `route_not_available`**: que comparen `error`.
3. **Payloads de eventos.** Si alguno lleva `userId`, `accountId`, `ownerId`, `tokenableId` —o
   esos nombres con otra grafía— o un objeto con alguno de los cuatro adentro, construir el `Event`
   ahora tira y el aviso no sale. Hasta ahora se mandaban al cliente, que es justo lo que la
   regla prohíbe. Correr los tests de payloads del dominio antes de subir.
4. **`PUT …` en `send`, `operate` o `denied` sobre una ruta `resource`:** el `PATCH` de esa ruta
   pasa a pedir el mismo grado —o a quedar cerrado—. Una key con sólo `write` que usaba `PATCH`
   ahí recibe 403. Era el agujero; conviene saber si alguna integración lo usaba.
5. **Alertas:** los errores de configuración salen como 500. Que el monitoreo de 5xx los vea.

**Cómo subir.** Cambiar la restricción a `^0.11` —`^0.10` no la toma— y
`composer update funnelchat20/platform-gate`. Un dominio que todavía está en 0.9.x sube directo a
0.11.0, siguiendo también «Actualizar desde 0.9».

**Orden.**

1. Se publica 0.11.0. Nadie cambia hasta subir.
2. accounts, conversations y communities, en cualquier orden.
3. El portal documenta `500 gate_misconfigured` (no reintentable), el texto nuevo del 403 y que
   una ruta cerrada responde 403, no 404, cuando suba el primer dominio. Sus snippets de firma
   pueden probarse contra los vectores desde ya: la firma no cambió.

---

## Actualizar desde 0.9

Versión **0.10.0**. Cambian las respuestas de error de las llamadas con key: hay que revisar los
tests del dominio que comparan esos cuerpos (abajo). Sin cambios de config ni migraciones.

**Por qué.** El portal promete que todo error de la API trae `error` (un código estable) y
`message` (para personas). Hasta 0.9.1 eso lo cumplía sólo el portero: los dominios devolvían el
`{"message": …}` de Laravel en 403, 404 y 422, y cada uno armaba a mano otras formas —accounts usa
`code` en los 403 de workspace—. La especificación publicada (v0.6.14) documenta esas respuestas
sólo con `message`. Escrito tres veces es como se separaron, así que lo da la librería, como ya
daba el 401 con `UnauthenticatedResponse`. Además, la revisión de huecos del 13-11
(`HUECOS-2026-11-13.md`, punto 8, en el repo de coordinación) marcó que el tope diario es por
dominio y no sólo por key —cada dominio cuenta en su store—, con el corte a medianoche UTC,
mientras el README decía «por key» y el mensaje, «diarias». El comportamiento del tope no cambia:
cambia lo que se dice de él. Contrato `1113-errores-y-limites` v0.1, sección P, iniciativa
api-abierta.

**Qué cambia, sólo en las llamadas con key:**

- `ErrorResponse::register($exceptions)`, nuevo: las excepciones de Laravel salen con `error`, en
  JSON y con texto en español donde el de Laravel no sirve. Ver «El formato de error de las
  llamadas con key». Hasta engancharlo, no cambian.
- **El portero agrega `error`** a las respuestas JSON de error que vuelven por él sin uno: las que
  arma el dominio y las que Laravel rinde de una excepción dentro de la ruta. Viene con la versión,
  sin enganchar nada. Nunca quita ni cambia una clave.
- `AddErrorCode`, nuevo: el transformador de Scramble que pone `error` en la especificación.
- **El texto de `daily_cap_exceeded`** pasa de «Esta API key superó su tope de N llamadas
  diarias.» a «… llamadas diarias. El tope es por clave y por dominio, y se reinicia a las 00:00
  UTC.». `error`, el status, `Retry-After` y cómo se cuenta no cambian.
- `ErrorCode`, `ErrorBody` y `Caller\ApiKeyRequest`, nuevos, para el dominio que quiera armar
  sus propias respuestas con los mismos códigos.
- Las llamadas sin key —el panel— no cambian en nada: ni status, ni cuerpo, ni texto. Tampoco
  cambian el 401, los rechazos del portero, `Caller`, `api_call_log` ni los permisos.

**Antes de subir, en el dominio:**

1. **Tests que comparan un cuerpo de error con key** con `assertExactJson`: ahora traen `error`.
   Que comparen `error`, que es lo estable, o el cuerpo nuevo.
2. **Tests que comparan el texto del 429 `daily_cap_exceeded`**: que comparen `error`.
3. **Los `render` propios en `withExceptions`** van antes de `ErrorResponse::register`: el primero
   que contesta gana, y así el del dominio sigue ganando.
4. **Un cuerpo propio que trae `error` con otro sentido** (un booleano, un objeto) queda como está
   —no se pisa— y no cumple el formato: se cambia en el dominio.

**Cómo subir.** Cambiar la restricción a `^0.10` —`^0.9` no la toma— y
`composer update funnelchat20/platform-gate`. En el mismo cambio, la línea de `ErrorResponse` en
`bootstrap/app.php` y, si el dominio publica con Scramble, la de `AddErrorCode` (ver
«Instalación»).

**Orden** (el del contrato):

1. Se publica 0.10.0. Nadie cambia hasta subir.
2. accounts, conversations y communities suben, enganchan `ErrorResponse` y `AddErrorCode`, y
   llevan sus cuerpos propios al formato. En cualquier orden entre ellos.
3. El portal, con las especificaciones nuevas de los tres: no puede adelantarse a lo que
   responden.

---

## Actualizar desde 0.9.0

Versión **0.9.1**. Compatible: no hay que cambiar config ni código, sólo subir la versión.

**Por qué.** 0.9.0 hizo que `Event` rechazara `workspace_id` en `data`, por la Q7 de workspaces
(26-10, iniciativa api-abierta): «el aviso no dice de qué workspace viene». El 04-11 la Q35
cambió esa decisión (`DECISIONES-workspaces`, en el repo de coordinación):

> **Cambia la Q7: los avisos dicen de qué workspace vienen.** `data.workspace_id` deja de estar
> prohibido en el portero, y accounts lo pone en todos los avisos públicos, con el workspace al
> que se rutea el aviso. La suscripción sigue siendo de un workspace y sigue recibiendo solo los
> suyos.

Lo que lo hizo urgente: accounts ya publicaba `data.workspace_id` en `mac.usage_80` y
`mac.usage_100`. Con 0.9.0 esos avisos se descartaban, y `GET webhooks/events` y el OpenAPI de
accounts respondían 500.

**Qué cambia:**

- `Event` acepta `workspace_id` en `data`, a cualquier profundidad. Llega al cliente tal cual.
- `user_id`, `account_id`, `owner_id` y `tokenable_id` siguen prohibidos, igual que antes, también
  cuando el payload trae `workspace_id`.
- Nada más: ni el sobre, ni la firma, ni `Caller`, ni el `X-Workspace-Id` de las llamadas con key.

**Cómo subir.** Con `^0.9`, `composer update funnelchat20/platform-gate`. Un dominio que todavía
está en 0.8.x sube directo a 0.9.1, siguiendo «Actualizar desde 0.8» salvo su punto 1.

**Orden.** No hay uno obligatorio: el cambio sólo deja pasar lo que antes tiraba. accounts sube
primero, porque es el que ya emite avisos con `workspace_id`; los demás, cuando les toque.

---

## Actualizar desde 0.8

Versión **0.9.0**. Cambia lo que el dominio recibe en las llamadas con key: hay que revisar tres
cosas antes de subir (abajo). Sin cambios de config ni migraciones del paquete.

**Por qué.** Workspaces está en production, en beta privada. El 26-10 (iniciativa api-abierta)
se decidió que **cada API key es de un workspace** y que el `X-Workspace-Id` que mande una key
**no elige nada** (Q5 y Q12 de `DECISIONES-workspaces-2026-10-26.md`, en el repo de
coordinación). Hasta 0.8.x una key no tenía workspace, y los tres dominios procesaban su header
igual que el de una sesión: el cliente elegía el workspace. Además, sin header cada dominio hacía
algo distinto (el por defecto, la unión de la cuenta, el activo). El portero es lo único por lo
que pasan las llamadas con key de todos los dominios, así que es quien le lleva el workspace de la
key al dominio, también a lo que el dominio encola. Y el aviso no dice de qué workspace viene
(Q7): la suscripción ya es de uno. Contrato `1026-workspaces-api` v0.1, sección P.

**Qué cambia:**

- `Caller::workspaceId(): ?int`, nuevo: el `workspace_id` del token de la key; `null` si no tiene
  uno, y para cualquier llamador que no sea key. `Caller::apiKey()` acepta `?int $workspaceId`
  como último argumento, opcional. Ver «El workspace de una key».
- `toArray()` lleva `workspace_id`; `fromArray()` lo lee, y un array de 0.8.x sale con `null`.
- **El `X-Workspace-Id` de una llamada con key** llega al dominio con el workspace de la key, o
  sin header si la key no tiene uno. El de una sesión web no se toca.
- **503 `gate_unavailable` nuevo:** cuando la columna `workspace_id` existe y el token de la key
  no la trae, o trae algo que no es un id. Mientras la columna no exista, la key es del por
  defecto y pasa.
- **`Event` rechaza `workspace_id` en `data`**, a cualquier profundidad, con la misma excepción
  que `user_id`. **Revertido en 0.9.1**: ver «Actualizar desde 0.9.0».
- No cambian el tope diario, `api_call_log`, los permisos ni el `key_id`.

**Antes de subir, en el dominio:**

1. **Ningún evento lleva `workspace_id` en `data`.** Si alguno lo lleva, con 0.9.0 construir el
   `Event` tira y el aviso no sale. El workspace del evento va por el canal interno, no en el
   sobre. **No aplica desde 0.9.1**, que lo deja pasar: subí directo a 0.9.1.
2. **El orden de middlewares:** nada que lea `X-Workspace-Id` corre antes del portero. Ver «El
   workspace de una key», con la prueba que lo confirma.
3. **`findToken()` con columnas fijas:** agregar `workspace_id` cuando la columna exista en la
   base de ese ambiente. Si la columna existe y no está en la lista, cada llamada con key da 503.

**Cómo subir.** Cambiar la restricción a `^0.9` —`^0.8` no la toma— y
`composer update funnelchat20/platform-gate`.

**Orden** (el del contrato):

1. Se publica 0.9.0. Nadie cambia hasta subir.
2. accounts crea la columna `personal_access_tokens.workspace_id`, con cada key existente en el
   workspace por defecto de su dueño.
3. conversations y communities suben y limitan cada llamada con key a su workspace.
4. accounts sube y empieza a crear keys en cualquier workspace del dueño. Recién desde acá hay
   keys fuera del por defecto: un dominio que no subió sigue dejando que el header elija.

---

## Actualizar desde 0.8.0

Versión **0.8.1**. Compatible: no hay que cambiar config ni código, sólo subir la versión.

**Por qué.** El 25-10 (iniciativa api-abierta) se pidió que los nombres técnicos de los
permisos —`accounts:operate`, `conversations:send`…— no aparezcan en ningún lado, tampoco en
la API. Hasta 0.8.0 el 403 decía «Esta API key no tiene el permiso `conversations:send`.», y
quien lo lee tiene que arreglarlo en Desarrolladores, en el panel, donde las claves se crean
eligiendo un área y una casilla —«Contactos y conversaciones → Enviar»— y ese literal no
aparece. El mensaje ahora dice lo mismo que el panel.

**Qué cambia:**

- El texto de `message` en el 403 `permission_denied`. Ver «Respuestas de rechazo». `error`, el
  status y las cabeceras no cambian, y `PermissionDenied::$requiredPermission` sigue siendo el
  literal.
- `PermissionLabels::for()`, nuevo: el nombre de panel de cada permiso, o `null`.
- Ningún otro texto de respuesta de la librería nombraba un permiso; no cambian.

**Cómo subir.** Con `^0.8`, `composer update funnelchat20/platform-gate`. Los dominios suben en
cualquier orden. Si un test del dominio compara el texto viejo del 403, que pase a comparar
`error` —es lo estable— o el texto nuevo. Si el dominio tiene mensajes propios con el literal
de un permiso, que los arme con `PermissionLabels::for()`.

**Orden.** El portal, si muestra el texto del 403, lo actualiza cuando suba el primer dominio.

---

## Actualizar desde 0.7

Versión **0.8.0**. Una migración nueva y dos cosas para hacer en el dominio. Sin hacerlas, el
dominio sigue funcionando igual: las filas salen sin recurso y nadie las purga.

**Por qué.** El 09-10 el CEO marcó que el registro de llamadas no servía para rastrear un
problema: no ligaba la llamada con lo que creó, no tenía un id que el integrador pudiera citar,
y en conversations y communities no se purgaba nunca —sólo accounts tenía su
`api-usage:purge`, a 90 días—. El 17-10 se decidió hacer las tres cosas, con 90 días de
retención en todos los dominios y el id devuelto en la respuesta. Contrato
`1019-registro-llamadas` v0, sección L1, iniciativa api-abierta. Con eso cambia D12: el
registro pasa de seis campos a nueve, y deja de ser "sin purga". Sigue sin PII ni contenido.

**Qué cambia:**

- **`X-Request-Id` en cada respuesta** que pasa por `TrustEdge` o el portero, también en sus
  rechazos y en el 401 de `UnauthenticatedResponse`. El id va a la fila y al contexto del log
  (`request_id`). Ver «El id del pedido».
- **Si el dominio ya ponía un `X-Request-Id` propio:** en los pedidos que pasan por el portero
  desde ahora sale el del portero, que es el de la fila. En el resto sale el del dominio, como
  antes.
- **`TrustEdge` agrega `X-Request-Id` a todas las respuestas del dominio que no traen uno**, no
  sólo a las de `api/*`: es global.
- **`CreatedResource::declare()`**, para que el controlador diga qué creó la llamada.
- **Migración nueva** (`2026_10_19_000001`): `request_id`, `resource_type` y `resource_id` en
  `api_call_log`, las tres nullable, y un índice por `request_id`. La de 0.7 no cambia. Las
  filas viejas quedan como están, con las tres en `null`.
- **`platform-gate:purge-usage`** y `PLATFORM_GATE_USAGE_RETENTION_DAYS` (90).
- El paquete pide `illuminate/console`. Un dominio con `laravel/framework` ya lo tiene.

**Cómo subir, en cada dominio.** Si venís de 0.6, primero «Actualizar desde 0.6»; las dos
guías se pueden hacer en el mismo deploy.

1. **La versión.** `composer require funnelchat20/platform-gate:^0.8`. Con `^0.7` no alcanza:
   en `0.x`, la menor es la que rompe. communities fija la versión exacta (`0.7.1`, sin `^`):
   ahí el `composer require` reemplaza esa restricción.
2. **La migración**, en la conexión de uso de ese dominio (accounts `mysql`, conversations
   `mysql`, communities `groups`): `php artisan migrate`. Si la conexión de uso no es la de
   por defecto, la migración ya la toma de `PLATFORM_GATE_USAGE_CONNECTION`. En una tabla
   grande —conversations y communities nunca purgaron— el índice tarda: contá las filas antes.
   Mientras la migración no corrió, la fila se escribe igual, como en 0.7, y el log avisa
   `Corré la migración de 0.8` una vez por proceso. Bajo PHP-FPM un proceso atiende un
   pedido: ahí es una vez por llamada, mientras dure la ventana entre el deploy y el
   `migrate`. En un proceso de larga vida, el modo 0.7 se apaga solo: se vuelve a probar con
   las columnas como mucho una vez por minuto y, después del `migrate`, las filas vuelven a
   salir completas sin reiniciar nada (queda un info en el log). Cualquier otra falla al
   escribir la fila se loguea como en 0.7, sin reintento.
3. **Declarar los recursos.** En cada ruta de `allowed` que crea algo, el controlador declara
   tipo e id; las que no crean nada, no:

   ```php
   CreatedResource::declare('message', $message->id);
   ```

   El camino encolado también: si la respuesta devuelve un id antes de que el job corra, ése
   es el que se declara.
4. **Programar la purga**, todos los días:

   ```php
   Schedule::command('platform-gate:purge-usage')->daily()->onOneServer()->withoutOverlapping();
   ```

   accounts no: sigue con su `api-usage:purge`, que hace lo mismo sobre la misma tabla.
   La primera corrida en conversations y communities borra todo lo anterior a 90 días.
5. **Tests del dominio**: una llamada de creación con key deja la fila con su `request_id` y
   su recurso; la respuesta trae `X-Request-Id`; la purga está en el schedule.

**Orden.** El portero lee el id que pone la entrada, así que cualquier orden es seguro: sin el
portero nuevo, la cabecera del borde se ignora; sin el borde nuevo, el portero genera el id.
El que conviene:

1. platform-gate 0.8.0.
2. accounts, conversations y communities, en cualquier orden.
3. api-edge (manda `X-Funnelchat-Request-Id` y devuelve el `X-Request-Id` del dominio). Antes
   de los dominios también funciona, pero el id que devuelve sólo queda en los logs de la
   entrada hasta que el dominio sube la versión del portero.
4. El portal documenta `X-Request-Id`.

**Código.** `PlatformGate` y `TrustEdge` reciben dependencias nuevas —`RequestId`, y en el
portero también `CreatedResourceContext`— **al final y opcionales**: un `new PlatformGate(...)`
o un `new TrustEdge($edge)` armados como en 0.7.1 siguen andando, y lo que no se pasa sale del
contenedor. Si los arma el contenedor —lo normal—, no hay nada que hacer. `ApiCall` tiene tres
campos más, opcionales.

---

## Actualizar desde 0.7.0

Versión **0.7.1**. Compatible: no hay que cambiar config ni código, sólo subir la versión.

**Por qué.** La auditoría de communities del 13-10 (hallazgo Medium S1, iniciativa
api-abierta) encontró que el secreto del borde podía terminar en Sentry. 0.7.0 lo sacaba de
las cabeceras y del `server` del pedido, pero no de `$_SERVER`. Y los logs de la librería
dentro de `TrustEdge` —un secreto o un host mal cargado, una rotación desordenada, el Worker
encendido antes que el secreto— salen antes de que Sentry tome el pedido de Laravel: en ese
momento lo arma desde los globales, donde seguía `HTTP_X_FUNNELCHAT_EDGE`. Con el canal
`sentry` en `LOG_STACK`, el secreto real del Worker se iba con el log. Los errores de
configuración, además, se logueaban al armar `TrustEdge`, antes de sacar el secreto de
ningún lado.

**Qué cambia:**

- El secreto se saca también de `$_SERVER` y `$_ENV` (`HTTP_X_FUNNELCHAT_EDGE`), haya
  llegado bien o mal.
- Los errores de configuración del borde (un secreto corto, un host que no es host) se
  loguean en el primer pedido, después de sacar el secreto, y no al armar `TrustEdge`.
  Siguen saliendo una vez por proceso y con el mismo texto.
- Ningún log de la librería incluye el valor. Ya era así; ahora hay tests que lo prueban con
  los logs emitidos dentro de `TrustEdge`.

**Cómo subir.** Si fijaste `0.7.0`, cambiá a `0.7.1`; con `^0.7` alcanza con
`composer update funnelchat20/platform-gate`. Los dominios suben en cualquier orden. Si venís
de 0.6, seguí «Actualizar desde 0.6» con 0.7.1 en lugar de 0.7.0.

**Si usás Sentry, esta versión sola no alcanza.** En PHP-FPM la cabecera sigue a la vista de
`getallheaders()` y desde PHP no se puede borrar: el `before_send` del dominio tiene que
sacar `X-Funnelchat-Edge`. Ver «El borde».

---

## Actualizar desde 0.6

Versión **0.7.0**.

**Por qué.** El 10-10 se decidió que la API pública tenga una sola dirección,
`https://api.funnelchat.com`: un Worker de Cloudflare que reparte a accounts, conversations y
communities. Las keys tienen que entrar sólo por ahí, y nadie tiene que poder hacerse pasar
por el borde. Además, el 09-10 se comprobó en staging que cualquiera podía mandarle
`X-Forwarded-Host` directo a accounts o a conversations y cambiar los links que arma
Laravel, porque las dos le creen esa cabecera a cualquiera (`trustProxies(at: '*')`). Y
accounts necesita que sus rutas de webhooks pidan `webhooks:read` / `webhooks:write`, no
`accounts:{grado}` (Q48). Contrato: `1012-url-unica` v0, iniciativa api-abierta.

**Sin configurar nada, 0.7 se comporta como 0.6:** sin secreto no se le cree nada a nadie,
la exigencia está apagada y, sin `permission_prefix`, cada ruta pide lo mismo que antes. Lo
único que hace sola es sacar `X-Funnelchat-Edge` de cada pedido. No hace falta volver a
publicar la config: las claves nuevas toman su valor por defecto aunque tu
`config/platform-gate.php` publicado no las tenga.

### 1. Variables de entorno

| Variable | Qué es | Valor |
|---|---|---|
| `PLATFORM_GATE_EDGE_SECRET` | El secreto del borde, el actual. | `openssl rand -hex 32`; el mismo que el Worker. No pasa por el chat, el repo ni los logs. |
| `PLATFORM_GATE_EDGE_SECRET_NEXT` | El siguiente, para rotar. | Vacío. |
| `PLATFORM_GATE_PUBLIC_HOSTS` | Hosts públicos aceptados, separados por coma, sin `https://`. | `api.funnelchat.com` en producción; `api-staging.funnelchat.com` en staging. |
| `PLATFORM_GATE_REQUIRE_EDGE` | La exigencia. | `false`. |
| `PUBLIC_API_URL` | La URL que el 403 `use_public_endpoint` le dice a quien integra. La misma que muestran Scramble y la pantalla de Desarrolladores. | `https://api.funnelchat.com`; en staging, `https://api-staging.funnelchat.com`. |

### 2. `bootstrap/app.php`

**`trustProxies`: sacá `X-Forwarded-Host` y dejá `For`, `Proto` y `Port`.** Ojo:
`trustProxies(at: '*')` sin `headers` usa el default de Laravel, que además de Host incluye
`X-Forwarded-Prefix` —cambia la ruta de los links del mismo modo que Host cambia el host—.
Las dos se van:

```php
use Illuminate\Http\Request;

// 0.6
$middleware->trustProxies(at: '*');

// 0.7
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT,
);
```

Si el dominio no llama a `trustProxies` ni tiene `config/trustedproxy.php` (communities):
Laravel 12 —verificado en `laravel/framework` v12.69.3, el que instala este repo— no le cree
a ningún proxy, salvo cuando el host termina en `.on-vapor.com` o `.on-forge.com`, o en
Laravel Cloud: ahí le cree a todos, pero antes borra `X-Forwarded-Host`. Así que en ningún
caso le cree `X-Forwarded-Host`. Fuera de esos hosts tampoco le cree `X-Forwarded-For`, y
entonces la IP del cliente que manda el borde no llega a `$request->ip()`: si la querés para
el log y los límites por IP, agregá el mismo bloque. Tu versión de Laravel puede no ser ésa:
el test del paso 4 lo dice.

**`TrustEdge`, global y al final:**

```php
$middleware->append(\Funnelchat\PlatformGate\Http\Middleware\TrustEdge::class);
```

Al final y no al principio: el `TrustProxies` de Laravel mira el host original para decidir
en qué proxies confía.

**El 401:**

```php
->withExceptions(function (Exceptions $exceptions) {
    \Funnelchat\PlatformGate\Http\UnauthenticatedResponse::register($exceptions);
})
```

En `api/*`, el 401 pasa de `{"message": "Unauthenticated."}` a
`{"error": "unauthenticated", "message": "Falta la API key o no es válida."}`, con
`WWW-Authenticate: Bearer`, también cuando el pedido no pide JSON (antes era una
redirección al login). Si el panel lee el texto del 401, revisalo; si sólo mira el status,
no cambia nada. Otro prefijo: `register($exceptions, 'v2/*')`.

### 3. Lo que puede impedir que los links salgan con el host público

- **`URL::forceRootUrl(...)`** en un service provider: `url()` y `route()` ignoran el host
  del pedido y siempre salen con esa raíz. El paginador no, porque usa `$request->url()`.
  Si tu dominio lo llama, los links de `url()` / `route()` no van a salir con el host
  público.
- **`trustHosts(...)`**: agregá los hosts públicos, o Laravel rechaza el pedido por host no
  confiable.

### 4. Tests del dominio, antes de deployar

- Pedido directo al host interno con `X-Forwarded-Host: ejemplo-externo.test`: los links
  **no** cambian.
- Con `X-Funnelchat-Edge` válido y `X-Forwarded-Host: api.funnelchat.com`: sí.
- El panel sigue igual: un test de una ruta que usa el panel y uno de una ruta con key.

### 5. Sólo accounts: los webhooks

```php
'permission_prefix' => ['api/v1/webhooks*' => 'webhooks'],
```

Desde ese momento, una key con `accounts:*` y sin `webhooks:*` deja de alcanzar los webhooks
(403 `permission_denied`). Antes de declararlo, que las keys que los usan tengan
`webhooks:read` / `webhooks:write`.

### Orden

1. platform-gate 0.7.1 (no 0.7.0: ver «Actualizar desde 0.7.0»).
2. accounts, conversations y communities, cada uno con la exigencia **apagada**: variables
   cargadas (paso 1), y en el mismo deploy la versión 0.7, `trustProxies`, `TrustEdge` y el
   401 (paso 2).
3. api-edge en staging; después en producción.
4. El portal; los usuarios de la beta cambian la URL.
5. `PLATFORM_GATE_REQUIRE_EDGE=true`, dominio por dominio, cuando quienes integran ya usan
   la URL nueva. Antes, revisá que el log de ese dominio no tenga
   `X-Funnelchat-Edge … no coincide`: con la exigencia encendida, eso es un 403 para cada key.

### Rotar el secreto

1. Generá el nuevo y cargalo como `PLATFORM_GATE_EDGE_SECRET_NEXT` en todos los dominios.
2. Cambiá el del Worker (`wrangler secret put`). Durante el cambio valen los dos.
3. En cada dominio, el nuevo pasa a `PLATFORM_GATE_EDGE_SECRET` y el siguiente vuelve a
   quedar vacío.

### Código

El constructor de `PlatformGate` recibe dependencias nuevas. Si lo arma el contenedor —lo
normal—, no hay nada que hacer.

---

## Actualizar desde 0.5

El portero falla cerrado en tres lugares más. Revisá cada uno **antes** de subir la versión:

1. **Declará `allowed`.** Sin ella, ninguna key alcanza nada (403 `route_not_available`).
   Si tu `config/platform-gate.php` publicado no tiene la clave, agregala. Abrir todo es
   `['*']`, pero para una superficie pública conviene listar lo que se expone.
2. **Las keys necesitan sus permisos literales.** Una key cuya lista trae `*` en vez de los
   permisos explícitos recibe 403 `permission_denied` en todo. Si tu modelo restringe
   columnas en `findToken()`, incluí `abilities` o vas a ver 503.
3. **Sólo `api_key` y `web` son valores de `kind`.** Si en tu tabla de tokens hay filas con
   otro valor, esas llamadas pasan a 503. Antes de subir, contá los valores distintos de
   `kind` en cada ambiente.

`allows()` y `assert()` de `PermissionChecker` ahora pueden tirar `GateUnavailable`.

Y una opción nueva, que no cambia nada si no la definís:

4. **`PLATFORM_GATE_KEY_ID_SECRET`**, el mismo valor en todos los dominios. Con él, una key
   tiene el mismo `key_id` en todos los registros de uso y la pantalla de consumo puede
   juntarlos. Sin él, el `key_id` sigue saliendo del `APP_KEY` de cada dominio, como en 0.5.
   Definirlo cambia todos los `key_id`: las filas viejas del registro y los overrides de
   `daily_call_cap_overrides` quedan con el valor anterior.

---

## El tope diario: por key y por dominio

10 000 llamadas por día por key (`PLATFORM_GATE_DAILY_CALL_CAP`), **en cada dominio**: cada uno
cuenta en su propio store de cache (`PLATFORM_GATE_CACHE_STORE`), así que accounts, communities
y conversations cuentan aparte. Una key que agotó su tope en uno sigue andando en los otros.

El día es el de UTC: el contador se reinicia a las 00:00 UTC, y el 429 `daily_cap_exceeded` trae
`Retry-After` con los segundos que faltan. El mensaje lo dice (0.10):

```json
{"error": "daily_cap_exceeded", "message": "Esta API key superó su tope de 10000 llamadas diarias. El tope es por clave y por dominio, y se reinicia a las 00:00 UTC."}
```

Los overrides (`daily_call_cap_overrides`) también son de cada dominio: el que se carga en uno no
cambia el tope de esa key en los otros.

---

## El tope diario NO es un freno de envío

Cuenta **llamadas**. Una sola llamada bien formada puede producir N efectos: una ejecución
masiva alcanza a toda una audiencia, arrancar un flujo produce efectos indefinidamente.
Este tope no lo ve.

Es control de costo y de abuso. Contarlo como "el freno" es lo que hace que el freno real
no se construya nunca.

---

# Webhooks — el sobre

La segunda mitad de la librería define el **sobre**: cómo se llama un evento, la forma del
payload, la firma, el versionado y cómo se suscribe uno. El **catálogo** —qué eventos
existen— lo decide cada aplicación, que es la que sabe qué hechos tiene su negocio.

Si el sobre no se fija antes del primer evento, cada aplicación inventa el suyo y quien
consume aprende varias convenciones para una sola API. Los nombres publicados no se pueden
cambiar sin romperle el workflow a alguien.

## La forma

```json
{
  "id":          "evt_9f2c4e1a7b3d5f8091a2b3c4",
  "type":        "message.received",
  "version":     1,
  "occurred_at": "2026-09-11T17:48:29Z",
  "data":        { "…": "…" }
}
```

La aplicación decide `type` y `data`. Todo lo demás es igual para todas, porque quien lo
recibe es un workflow al que no le importa qué aplicación lo emitió.

`version` es **del tipo de evento**, no del sobre: deja que un tipo evolucione sin
arrastrar a los demás.

## Las reglas que el código hace cumplir

| Regla | Cómo se hace cumplir |
|---|---|
| `recurso.hecho_en_pasado`, minúsculas | `EventName::from()` tira si no machea |
| **Sin `user_id`, `account_id`, `owner_id`, `tokenable_id`** en `data`, ni anidados, ni escritos de otra forma (`userId`, `OWNER-ID`), ni dentro de un objeto | El constructor de `Event` revisa lo que va a salir en el JSON y tira (0.11.0) |
| `workspace_id` **sí** puede ir en `data` (0.9.1): dice a qué workspace se ruteó el aviso | No se valida; la suscripción sigue siendo de un workspace |
| Esa lista es un **piso, no un techo** | Cada aplicación agrega la suya y la hace cumplir con su propio test |
| Firma `t=…,v1=…` sobre `t + "." + cuerpo` | `Signature` — el timestamp va adentro de lo firmado; si no, una entrega capturada se reenvía para siempre. Los vectores de prueba compartidos están en `tests/vectors/webhook-signature.json` (0.11.0) |
| Verificación en tiempo constante | `hash_equals`, con ventana de tolerancia de 5 min |
| Suscripción **por tipo** | `Subscription::wants()`. Acepta `recurso.*`; `*` a secas **no existe** |
| Tres reintentos con espera creciente: 1m, 5m, 30m | `RetryPolicy`. Sólo 5xx, 429 y errores de red |

El tiempo pasado en los nombres es deliberado: un webhook avisa algo que **ya** pasó.
`message.send` sonaría a una orden; `message.sent` es un hecho.

La suscripción por tipo tampoco es comodidad: un evento de cambio de estado puede salir dos
o tres veces por cada operación. Que se pida explícitamente evita que quien consume lo
descubra por un timeout de su propio lado.

## La lista de claves prohibidas es un piso

**Lo que la librería revisa** (0.11.0): lo que va a salir en el JSON de `toJson()`, no la
estructura de PHP. Un `stdClass`, un `JsonSerializable` —un Resource de Laravel— o las
propiedades públicas de un objeto llegan al cliente con sus claves igual que un array, así que
se revisan igual. Y el nombre se compara sin lo que no lo cambia: mayúsculas, guiones, espacios o
`camelCase` (`userId`, `USER-ID` y `user id` son `user_id`). Hasta 0.10.0 se recorrían sólo los
arrays y se comparaba el nombre exacto: un `userId`, o un Resource con `user_id` adentro, pasaban.

Son esos cuatro nombres y nada más: `user_ids`, `assigned_user_id` o `contactId` no son de la
lista, y `workspace_id` sigue permitido.

La librería rechaza lo que es peligroso en cualquier aplicación. **No puede ir más allá**,
porque no sabe qué entidad es de quién.

La regla correcta a nivel aplicación es más ancha: **ids de entidades cuyo schema es tuyo,
sí; de otra aplicación, nunca** — en su lugar va el dato público. Cada una la hace cumplir
con un test propio que recorre el payload en profundidad. La librería pone el piso; el
techo lo pone quien conoce sus tablas.

## Lo que todavía NO está acá

- **El transporte.** Quién despacha, con qué cola y con qué guard de SSRF lo resuelve cada
  aplicación: la entrega vive donde vive el hecho.
- **Dónde se guardan las suscripciones.** `Subscription` es un objeto de valor; la tabla es
  de quien la necesite.
- **El catálogo.** Lo decide cada aplicación.
