<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http\Middleware;

use Closure;
use Funnelchat\PlatformGate\Caller\Caller;
use Funnelchat\PlatformGate\Caller\CallerContext;
use Funnelchat\PlatformGate\Caller\CallerKind;
use Funnelchat\PlatformGate\Caller\KeyIdentity;
use Funnelchat\PlatformGate\Caller\KindColumnProbe;
use Funnelchat\PlatformGate\Exceptions\GateException;
use Funnelchat\PlatformGate\Exceptions\GateUnavailable;
use Funnelchat\PlatformGate\Limits\DailyCallCap;
use Funnelchat\PlatformGate\Permissions\PermissionChecker;
use Funnelchat\PlatformGate\Permissions\RoutePermissions;
use Funnelchat\PlatformGate\Usage\ApiCall;
use Funnelchat\PlatformGate\Usage\ApiCallRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * El portero. Corre en cada llamada: ¿quién sos?, ¿podés?, ¿te pasaste?, anotá.
 *
 * Lo que NO hace, para que nadie lo duplique ni lo espere (ver "Lo que los dominios hacen
 * por su cuenta" en el contrato C6): los frenos de envío, la idempotencia de envío y la
 * autorización por cuenta se quedan en el dominio. La idempotencia es específica del
 * recurso — el portero no puede saber si dos llamadas produjeron el mismo mensaje.
 */
final class PlatformGate
{
    private ?float $startedAt = null;

    private ?string $keyIdForLog = null;

    public function __construct(
        private readonly CallerContext $context,
        private readonly RoutePermissions $routes,
        private readonly PermissionChecker $permissions,
        private readonly DailyCallCap $cap,
        private readonly ApiCallRecorder $recorder,
        private readonly KeyIdentity $identity,
        private readonly KindColumnProbe $kindColumn,
        private readonly LoggerInterface $logger,
        /** @var callable(mixed):array{0:?int,1:bool} */
        private $accountResolver,
        private readonly bool $blockOnUnreadableKind = false,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->startedAt = microtime(true);

        try {
            $this->classify($request);
        } catch (GateException $e) {
            return $this->reject($e);
        }

        return $next($request);
    }

    /**
     * Se corre después de mandarle la respuesta al cliente. Acá va el registro de uso:
     * es medición, no debe sumarle latencia a nadie.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->keyIdForLog === null) {
            return; // sólo se registran las llamadas de key.
        }

        $this->recorder->record(new ApiCall(
            keyId: $this->keyIdForLog,
            route: $request->route()?->uri() ?? $request->path(),
            method: strtoupper($request->method()),
            statusCode: $response->getStatusCode(),
            occurredAt: gmdate('Y-m-d H:i:s', (int) ($this->startedAt ?? microtime(true))),
            durationMs: (int) round((microtime(true) - ($this->startedAt ?? microtime(true))) * 1000),
        ));
    }

    private function classify(Request $request): void
    {
        $user = $this->resolveUser($request);
        $token = $user?->currentAccessToken();

        if ($token === null) {
            // Sin token no hay nada que clasificar. `Unverified`, que es la verdad: el 401
            // —si corresponde— lo da el `auth:sanctum` del dominio, no nosotros.
            $this->context->set(Caller::unverified());

            return;
        }

        // Un `TransientToken` no es una fila de `personal_access_tokens`: es lo que Sanctum
        // adjunta cuando resuelve al usuario por el fallback de sesión (`sanctum.guard`).
        // O sea: una persona con el navegador abierto, no una key de máquina. No tiene
        // `kind` y nunca va a tenerlo — preguntarle por la columna y cortar con 503 rompe
        // todo el front y todos los tests que usan `actingAs()`. Es `web`, y se sabe sin
        // mirar el esquema.
        if ($token instanceof TransientToken) {
            [$accountId, $isOwner] = ($this->accountResolver)($user);
            $this->context->set(Caller::web($accountId, $isOwner));

            return;
        }

        $kind = $this->readKind($token);

        if ($kind === null) {
            // Hay token y no podemos leer `kind`. Hay dos causas y se deciden al revés,
            // así que la pregunta se la hacemos al esquema en vez de adivinar.
            $columnExists = $this->kindColumn->existsFor($token);

            if ($columnExists === false) {
                // Ventana de rollout: accounts todavía no desplegó la migración de C1. No
                // hay keys emitidas, no hay nada que proteger, y cortar acá sería 503 en
                // cada request del front.
                $this->context->set(Caller::unverified());

                return;
            }

            // La columna existe (o no se pudo consultar el esquema) y el atributo llega
            // vacío igual: el dominio no la está seleccionando. conversations sobreescribe
            // `findToken()` con una lista fija de columnas y `kind` puede no estar ahí. El
            // portero queda decorativo **sin dar ninguna señal**, que es la peor forma de
            // fallar que tiene un archivo que existe para frenar cosas.
            $this->logger->error(
                'platform-gate: hay un token y no se puede leer `kind` — el portero no está '
                .'protegiendo nada. La columna existe en la tabla, así que el modelo del dominio '
                .'no la está seleccionando: revisá si sobreescribe `findToken()` con una lista '
                .'fija de columnas.',
                ['token_model' => $token::class, 'column_exists' => $columnExists]
            );

            if ($columnExists === true || $this->blockOnUnreadableKind) {
                throw new GateUnavailable('unreadable_kind');
            }

            // Esquema no consultable: no sabemos. Se deja pasar para no voltear el front
            // por un problema de infraestructura nuestro, pero queda el error de arriba.
            $this->context->set(Caller::unverified());

            return;
        }

        [$accountId, $isOwner] = ($this->accountResolver)($user);

        if ($kind !== CallerKind::ApiKey) {
            $this->context->set(Caller::web($accountId, $isOwner));

            return;
        }

        // Desde acá, es máquina.
        $keyId = $this->identity->for($token->getKey());

        // Se anota ANTES de chequear nada, a propósito: una llamada rechazada también es
        // una llamada, y en un piloto cuyo objetivo es entender cómo se usa la API son
        // justamente las más interesantes — alguien golpeando una ruta que no puede usar
        // es una pregunta de producto, no ruido. Si esto se seteara recién al final, los
        // 403 y los 429 no quedarían en ningún lado. Pasó en la primera prueba de
        // integración: tres filas registradas de cinco llamadas.
        $this->keyIdForLog = $keyId;

        $required = $this->routes->requiredFor($request);
        $this->permissions->assert($token, $required);
        $this->cap->consume($keyId);

        $this->context->set(Caller::apiKey(
            $keyId,
            $accountId,
            $isOwner,
            array_values(array_filter(
                method_exists($token, 'getAbilities') ? (array) $token->getAbilities() : [],
                'is_string'
            )),
        ));
    }

    /**
     * Lee `kind` del token. Devuelve `null` cuando no hay nada legible.
     *
     * TODO el acceso al atributo va adentro del `try`, incluida la normalización: el
     * modelo es del dominio y puede castear la columna a lo que quiera. accounts la
     * castea a un PHP enum propio, y la versión anterior de este método hacía
     * `(string) $raw` afuera del `try` — un `Error` fatal, o sea 500 en cada request
     * autenticado de ese dominio. Lo encontró accounts leyendo esta librería antes de
     * instalarla.
     *
     * Un valor desconocido en la columna NO cae en `web` por default: eso sería inventar
     * un permiso. Cae en `Unverified`, y se loguea fuerte para que se vea.
     */
    /**
     * Resuelve al llamador pidiéndole el guard EXPLÍCITAMENTE.
     *
     * `$request->user()` sin argumento usa el guard por defecto de la app, que en
     * estos dominios es `web` (sesión). Si el middleware queda montado antes de
     * `auth:sanctum` —y en Laravel eso pasa fácil, porque `appendToGroup('api', …)`
     * corre antes que los middleware declarados en cada grupo de rutas— esa llamada
     * devuelve `null`, el portero clasifica todo como `unverified` y **deja pasar
     * cualquier key sin chequear nada**. Falla abierto y en silencio: las respuestas
     * son idénticas a las de antes de instalarlo.
     *
     * Preguntando por el guard de tokens, el orden deja de importar.
     */
    private function resolveUser(Request $request): ?Authenticatable
    {
        $guard = (string) config('platform-gate.auth_guard', 'sanctum');

        try {
            $user = auth()->guard($guard)->user();
        } catch (\InvalidArgumentException) {
            // El dominio no declara ese guard. Caemos al comportamiento anterior en vez
            // de romper: si está mal montado, lo dirá el probe de `kind`.
            return $request->user();
        }

        return $user ?? $request->user();
    }

    private function readKind(object $token): ?CallerKind
    {
        try {
            $raw = $token->kind ?? null;

            if ($raw === null) {
                return null;
            }

            $kind = CallerKind::fromAttribute($raw);
        } catch (Throwable $e) {
            $this->logger->error('platform-gate: no se pudo leer `kind` del token', [
                'token_model' => $token::class,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if ($kind === null) {
            $this->logger->error('platform-gate: `kind` desconocido en personal_access_tokens', [
                'token_model' => $token::class,
            ]);

            return CallerKind::Unverified;
        }

        return $kind;
    }

    private function reject(GateException $e): JsonResponse
    {
        if ($e instanceof GateUnavailable) {
            $this->logger->error('platform-gate: no se pudo evaluar', [
                'stage' => $e->stage,
                'exception' => $e->getPrevious()?->getMessage(),
            ]);
        }

        $payload = [
            'error' => $e->errorCode(),
            'message' => $e->getMessage(),
        ];

        $headers = [];

        if ($e->retryAfter() !== null) {
            $headers['Retry-After'] = (string) $e->retryAfter();
        }

        // Nada de `kind`, nada de `key_id`, nada de identificadores de cuenta: lo que el
        // cliente ve no lleva nada nuestro (D5, y pedidos P1/P2).
        return new JsonResponse($payload, $e->status(), $headers);
    }
}
