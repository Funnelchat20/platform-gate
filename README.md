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
| Aplica el tope diario de **llamadas** por key | Autorización por cuenta (que el recurso sea de quien llama) |
| Escribe una fila por llamada, con su id de pedido y lo que creó, y la purga a los 90 días | Emitir, validar o revocar keys |
| Devuelve `X-Request-Id` en cada respuesta | Leer el registro: se consulta por SQL |
| Reconoce lo que llega por el borde (`api.funnelchat.com`) y le cree host e IP | Rutear entre dominios — eso es el Worker |

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
    // El 401 de `api/*`, igual en todos los dominios.
    \Funnelchat\PlatformGate\Http\UnauthenticatedResponse::register($exceptions);
})
```

`PlatformGate` tiene que correr **después** de `auth:sanctum` —necesita el token resuelto— y
**antes** de cualquier cosa de la aplicación que pregunte quién llama.

Requiere PHP ^8.2, Laravel ^11.9 o ^12.0, y Sanctum ^4.0.

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
rechaza como si no existiera (403 `route_not_available`), y una lista vacía no deja
**ninguna** ruta alcanzable: un dominio que no declara su superficie no expone nada. Así
una ruta nueva nace denegada. Abrir todo es `['*']`, y es algo que hay que escribir. Sólo
afecta a llamadores `api_key`: el front no pasa por esta lista. `denied` se evalúa antes,
para poder excluir algo que machearía un patrón amplio.

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
  las llamadas de key a rutas de `allowed` reciben 503 `gate_unavailable`. Lo mismo si dos
  patrones le dan prefijos distintos a la misma ruta: no se elige por orden de declaración.
  Lo que ya estaba denegado sigue en 403.

### Cómo se leen el `kind` y los permisos del token

**`kind`:** sólo hay dos valores de token, `api_key` y `web`. Cualquier otro —uno que esta
versión no conoce, vacío, o `unverified`, que es un estado del portero y no un valor de la
columna— es un llamador que el portero no sabe clasificar: responde 503
`gate_unavailable` y lo deja en el log. No es `web` por descarte, ni una key sin permisos.

**Permisos de una key:** el permiso que pide la ruta se busca **literal** en la lista de
`abilities` del token. `*` es un string más: no habilita nada a una key. La lista sale de
`getAbilities()` si el modelo lo tiene y, si no, del atributo `abilities` —el
`PersonalAccessToken` de Sanctum no expone ningún método para leerla—. Si no se puede
leer (la columna no se selecciona, el valor no es una lista), responde 503
`gate_unavailable`: no pudo evaluar, y no deja pasar. La misma lista es la que el dominio
ve en `Caller::$permissions`.

### Si tu aplicación restringe columnas en `findToken()`, incluí `kind` y `abilities`

Una aplicación puede sobreescribir `findToken()` en su modelo `PersonalAccessToken` para
traer sólo algunas columnas. Si `kind` no está en esa lista, el atributo llega `null` y el
portero no puede clasificar al llamador. Si falta `abilities`, no puede evaluar los
permisos de una key, y corta con 503.

```php
protected static array $selectColumns = [
    'id', 'tokenable_type', 'tokenable_id', 'token', 'abilities', 'expires_at',
    'kind',
];
```

El comportamiento cuando `kind` no se puede leer lo decide `PLATFORM_GATE_BLOCK_ON_UNREADABLE_KIND`.
En `true`, un token cuyo `kind` no se puede leer devuelve 503 en vez de seguir. Ponelo en
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
produce efectos externos tiene que poder saber que lo originó una key.

**Nada de esto se expone al cliente.** Ni el `kind`, ni el `key_id`, ni ningún
identificador de cuenta — ni en respuestas, ni en headers, ni en webhooks. Lo único que el
portero le agrega a la respuesta es `X-Request-Id`, un id al azar del pedido.

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
contesta el dominio, un 500, y los rechazos del propio portero (403, 429, 503). El 401 de
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
- **Va al log.** Se suma como `request_id` al contexto compartido del log del dominio
  (`Log::shareContext()`) en cuanto se decide: si `TrustEdge` está montado, desde el
  principio del pedido; si no, desde que corre el portero. Al terminar el pedido (el
  `terminate` del portero y de `TrustEdge`) se saca, y el resto del contexto del dominio queda
  como estaba: no depende de cómo maneje el proceso el estado entre pedidos. Lo que el dominio
  loguee en su propio `terminate` después del portero ya no lo lleva.

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
  un entero positivo (hasta 20 dígitos, sin ceros adelante), un UUID o un ULID. Se guarda el
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
tiene contra qué comparar: 503 `gate_unavailable`, nunca dejar pasar.

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
| Ruta fuera de `allowed`, o de la superficie amplificada | 403 | `route_not_available` | Terminal — no hay permiso que pedir |
| Una key llegó directo a un host interno y el dominio exige el borde | 403 | `use_public_endpoint` | Terminal — hay que cambiar la URL |
| Se pasó del tope diario | 429 + `Retry-After` | `daily_cap_exceeded` | Terminal por hoy |
| **No se pudo evaluar** | 503 + `Retry-After` | `gate_unavailable` | **Reintentable** |

Todas llevan `X-Request-Id` (0.8).

"No se pudo evaluar" incluye: el store del contador no responde, `kind` no se puede leer o
tiene un valor desconocido, la lista de permisos de la key no se puede leer,
`permission_prefix` no se puede leer o le da dos prefijos a la misma ruta, y la exigencia
del borde está encendida sin ningún secreto configurado.

El 401 lo tira el `auth:sanctum` del dominio, antes de que corra el portero; por eso es algo
que el dominio engancha. Fuera de `api/*` no cambia nada.

La última es la que importa: si el store se cae, **corta, no deja pasar**. Dejar pasar
cuando no se puede evaluar es el agujero clásico — el cliente automatizado se pasa del
límite justo cuando el control no responde. Y "no pude evaluar" nunca se colapsa con
"evalué y está denegado": son dos respuestas para el cliente y dos métricas para vos.

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

**Cómo subir, en cada dominio.** Si venís de 0.6 (accounts y communities fijan `0.6.0`),
primero «Actualizar desde 0.6»; las dos guías se pueden hacer en el mismo deploy.

1. **La versión.** `composer require funnelchat20/platform-gate:^0.8`. Con `^0.7` no alcanza:
   en `0.x`, la menor es la que rompe.
2. **La migración**, en la conexión de uso de ese dominio (accounts `mysql`, conversations
   `mysql`, communities `groups`): `php artisan migrate`. Si la conexión de uso no es la de
   por defecto, la migración ya la toma de `PLATFORM_GATE_USAGE_CONNECTION`. En una tabla
   grande —conversations y communities nunca purgaron— el índice tarda: contá las filas antes.
   Mientras la migración no corrió, la fila se escribe igual, como en 0.7, y el log avisa
   `Corré la migración de 0.8` una vez por proceso. Bajo PHP-FPM un proceso atiende un
   pedido: ahí es una vez por llamada, mientras dure la ventana entre el deploy y el
   `migrate`. Cualquier otra falla al escribir la fila se loguea como en 0.7, sin reintento.
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

1. platform-gate 0.8.0.
2. api-edge (manda `X-Funnelchat-Request-Id` y devuelve el `X-Request-Id` del dominio).
3. accounts, conversations y communities, en cualquier orden.
4. El portal documenta `X-Request-Id`.

**Código.** `PlatformGate` y `TrustEdge` reciben `RequestId` como dependencia nueva y
opcional. Si los arma el contenedor —lo normal—, no hay nada que hacer. `ApiCall` tiene tres
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
| **Sin `user_id`, `account_id`, `owner_id`, `tokenable_id`** en `data`, ni anidados | El constructor de `Event` recorre el payload y tira |
| Esa lista es un **piso, no un techo** | Cada aplicación agrega la suya y la hace cumplir con su propio test |
| Firma `t=…,v1=…` sobre `t + "." + cuerpo` | `Signature` — el timestamp va adentro de lo firmado; si no, una entrega capturada se reenvía para siempre |
| Verificación en tiempo constante | `hash_equals`, con ventana de tolerancia de 5 min |
| Suscripción **por tipo** | `Subscription::wants()`. Acepta `recurso.*`; `*` a secas **no existe** |
| Tres reintentos con espera creciente: 1m, 5m, 30m | `RetryPolicy`. Sólo 5xx, 429 y errores de red |

El tiempo pasado en los nombres es deliberado: un webhook avisa algo que **ya** pasó.
`message.send` sonaría a una orden; `message.sent` es un hecho.

La suscripción por tipo tampoco es comodidad: un evento de cambio de estado puede salir dos
o tres veces por cada operación. Que se pida explícitamente evita que quien consume lo
descubra por un timeout de su propio lado.

## La lista de claves prohibidas es un piso

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
