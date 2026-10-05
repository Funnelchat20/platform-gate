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
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', \Funnelchat\PlatformGate\Http\Middleware\PlatformGate::class);
})
```

Tiene que correr **después** de `auth:sanctum` —necesita el token resuelto— y **antes** de
cualquier cosa de la aplicación que pregunte quién llama.

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

## Respuestas de rechazo

| Situación | Status | `error` | Terminal o reintentable |
|---|---|---|---|
| Le falta el permiso | 403 | `permission_denied` | Terminal |
| Ruta fuera de `allowed`, o de la superficie amplificada | 403 | `route_not_available` | Terminal — no hay permiso que pedir |
| Se pasó del tope diario | 429 + `Retry-After` | `daily_cap_exceeded` | Terminal por hoy |
| **No se pudo evaluar** | 503 + `Retry-After` | `gate_unavailable` | **Reintentable** |

"No se pudo evaluar" incluye: el store del contador no responde, `kind` no se puede leer o
tiene un valor desconocido, y la lista de permisos de la key no se puede leer.

La última es la que importa: si el store se cae, **corta, no deja pasar**. Dejar pasar
cuando no se puede evaluar es el agujero clásico — el cliente automatizado se pasa del
límite justo cuando el control no responde. Y "no pude evaluar" nunca se colapsa con
"evalué y está denegado": son dos respuestas para el cliente y dos métricas para vos.

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
