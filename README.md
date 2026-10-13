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
| Escribe una fila por llamada | Emitir, validar o revocar keys |
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
identificador de cuenta — ni en respuestas, ni en headers, ni en webhooks.

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

**El secreto no sigue en el pedido.** Se saca apenas se lee, valga o no: lo que corre
después —logs, reportes de errores— no lo ve. Lo que corre **antes** de `TrustEdge` sí lo
pudo ver: si el dominio manda cabeceras a un servicio de errores desde un middleware global
que corre primero, agregá `X-Funnelchat-Edge` a las que se limpian.

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

1. platform-gate 0.7.0.
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
