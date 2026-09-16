<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Dominio
    |---------------------------------------------------------------------------
    | El nombre con el que este backend aparece en los permisos: `conversations`,
    | `accounts`, `communities`, `wha-link`. De acá salen `{dominio}:read`,
    | `{dominio}:write` y —donde corresponda— `{dominio}:send`.
    */
    'domain' => env('PLATFORM_GATE_DOMAIN'),

    /*
    |---------------------------------------------------------------------------
    | Rutas de envío
    |---------------------------------------------------------------------------
    | Las que producen egress a WhatsApp. Requieren `{dominio}:send`, que NO está
    | implicado por `write` (D10).
    |
    | Sólo tiene sentido en dominios que mandan: conversations y communities.
    | accounts encola a conversations y su audiencia es siempre el dueño de la
    | cuenta; wha-link no manda. En esos dos, esta lista va vacía.
    |
    | Patrones: "POST api/v1/message/send-message" o "api/v1/messages/*".
    | Se machean contra el URI DEFINIDO de la ruta, no contra el path del request.
    */
    'send' => [],

    /*
    |---------------------------------------------------------------------------
    | Superficie amplificada — ningún permiso la habilita
    |---------------------------------------------------------------------------
    | Rutas donde UNA llamada produce N mensajes, con N no acotado por el request.
    | No es "hace falta un permiso más fuerte": no hay permiso que dar (D21, C).
    |
    | Ojo con las que no parecen de envío. En conversations, `contacts/add-tags`
    | con un solo contacto dispara `TagAddedJob` → arranca un flujo → manda
    | mensajes, sin pasar por ninguna ruta de mensaje. Por eso esta lista existe:
    | desde la tabla de rutas no se pueden enumerar los caminos de envío.
    */
    'denied' => [],

    /*
    |---------------------------------------------------------------------------
    | Operación irreversible sobre el recurso externo
    |---------------------------------------------------------------------------
    | Rutas que mutan el recurso del cliente de forma que no se puede deshacer:
    | resetear la sesión de un número —hay que volver a escanear el código—,
    | purgar una cola de mensajes que se pierden sin papelera, borrar un recurso.
    |
    | Es un EJE APARTE, no un peldaño más alto de la escalera: quien puede editar
    | no tiene por qué poder destruir, y al revés tampoco. Sin esta lista, todas
    | estas rutas caen en `write` por ser POST/PUT/DELETE, y el permiso queda
    | decorativo.
    |
    | Se evalúa después de `send` y antes del fallback por método HTTP.
    */
    'operate' => [],

    /*
    |---------------------------------------------------------------------------
    | Tope diario de llamadas por key
    |---------------------------------------------------------------------------
    | ⚠️ Cuenta LLAMADAS, no mensajes: es control de costo y abuso, NO un freno de
    | envío (ver la advertencia en DailyCallCap). El freno de envío vive en la
    | costura de salida de cada dominio.
    |
    | 0 = sin tope. Los overrides son por key y se tocan sin deploy.
    */
    'daily_call_cap' => (int) env('PLATFORM_GATE_DAILY_CALL_CAP', 10000),

    'daily_call_cap_overrides' => [
        // 'key_a1b2c3…' => 50000,
    ],

    /*
    |---------------------------------------------------------------------------
    | Store del contador
    |---------------------------------------------------------------------------
    | Si se cae, el portero corta con 503 y no deja pasar (P7). Que sea el store
    | compartido del dominio, no el de proceso: un contador in-memory se multiplica
    | por la cantidad de instancias y deja de contar lo que dice contar.
    */
    'cache_store' => env('PLATFORM_GATE_CACHE_STORE'),

    /*
    |---------------------------------------------------------------------------
    | Registro de uso
    |---------------------------------------------------------------------------
    | Una fila por llamada, append-only, nadie la lee desde el código (D12).
    | Va aparte de `feature_usage_events` y con sus mismas convenciones, para que
    | unificar mañana sea un UNION y no arqueología (D20).
    */
    'usage_connection' => env('PLATFORM_GATE_USAGE_CONNECTION'),
    'usage_table' => env('PLATFORM_GATE_USAGE_TABLE', 'api_call_log'),

    /*
    |---------------------------------------------------------------------------
    | Qué hacer si hay token pero no se puede leer `kind`
    |---------------------------------------------------------------------------
    | Normalmente NO hace falta tocarlo: el portero le pregunta al esquema.
    |
    |   columna ausente  → ventana de rollout, no hay keys → deja pasar
    |   columna presente → el dominio no la selecciona → **corta con 503**
    |
    | Este flag sólo fuerza el corte en el tercer caso: cuando el esquema no se
    | puede consultar. `false` (default) deja pasar ahí para no voltear el front
    | por un problema de infraestructura, dejando el error en el log.
    |
    | El default NO puede ser `true`: con la columna todavía sin desplegar, cortar
    | daría 503 en cada request del front.
    */
    'block_on_unreadable_kind' => (bool) env('PLATFORM_GATE_BLOCK_ON_UNREADABLE_KIND', false),

    /*
    |---------------------------------------------------------------------------
    | Resolución de cuenta
    |---------------------------------------------------------------------------
    | Devuelve [accountId, esElDueño].
    |
    | El default devuelve el id del usuario del token y declara `false`: NO sabemos
    | si es el dueño o un asiento. Es a propósito, y es lo que pidió conversations
    | (P3): un límite llaveado por asiento se multiplica por la cantidad de
    | asientos, y ese bug ya está documentado cuatro veces en ese repo.
    |
    | Cada dominio sobreescribe esto con su propia resolución de owner —en
    | conversations es `User::resolveOwnerId()`— y recién ahí devuelve `true`.
    */
    'account_resolver' => null,

];
