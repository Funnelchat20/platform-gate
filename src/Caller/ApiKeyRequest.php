<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Caller;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ¿Este pedido vino con una API key? (0.10)
 *
 * Lo pregunta el formato de error (`ErrorResponse`), que tiene que contestarlo también cuando la
 * excepción salta **antes** de que corra el portero: una ruta que no existe (el router corta
 * antes de cualquier middleware de ruta), un `throttle` o un binding de modelo que el orden de
 * middlewares de Laravel pone delante del portero.
 *
 *   1. Si el portero ya clasificó el pedido, su respuesta: `api_key` sí, `web` no. Es la misma
 *      clasificación que decidió los permisos, y no cuesta nada.
 *   2. Si no (`unverified`: no corrió, o corrió sin token), se mira el token como lo mira el
 *      portero —el `kind` de su fila en `personal_access_tokens`—:
 *      - el que ya resolvió el guard (`auth:sanctum` corrió), sin consultar nada;
 *      - si no, el `Bearer` del pedido, buscado con el modelo de tokens del dominio
 *        (`Sanctum::personalAccessTokenModel()::findToken()`, el mismo que usa Sanctum). Una
 *        consulta, sólo en el camino de error.
 *
 * La forma del token no alcanza: las keys y los tokens `web` del panel son tokens de Sanctum
 * iguales; lo único que los distingue es `kind`.
 *
 * **Ante la duda, no es key.** Lo que se decide acá no es un permiso sino un formato, y lo que el
 * contrato no negocia es que el panel reciba exactamente lo de antes. Un `kind` ilegible o una
 * consulta que falla dejan la respuesta de Laravel —el portero, si llega a correr, corta esa key
 * de todos modos—. Nunca tira: un error al decidir el formato no puede convertir un 404
 * en un 500.
 *
 * No mira si la key venció: no decide si pasa, sólo cómo se le contesta a quien la presentó.
 */
final class ApiKeyRequest
{
    public function __construct(
        private readonly CallerContext $context,
        private readonly AuthFactory $auth,
        private readonly LoggerInterface $logger,
        private readonly string $guard = 'sanctum',
    ) {
    }

    public function is(Request $request): bool
    {
        return match ($this->context->kind()) {
            CallerKind::ApiKey => true,
            CallerKind::Web => false,
            CallerKind::Unverified => $this->presentsApiKey($request),
        };
    }

    private function presentsApiKey(Request $request): bool
    {
        try {
            $token = $this->resolvedToken() ?? $this->bearerToken($request);

            if ($token === null || $token instanceof TransientToken) {
                return false;
            }

            return CallerKind::fromAttribute($token->kind ?? null) === CallerKind::ApiKey;
        } catch (Throwable $e) {
            // Sin el token ni el mensaje: el mensaje de un error de base puede traer la consulta.
            $this->logger->warning(
                'platform-gate: no se pudo saber si el pedido trae una API key; el error sale con el formato de Laravel.',
                ['exception' => $e::class]
            );

            return false;
        }
    }

    /** El token que ya resolvió el guard, si lo resolvió. No dispara la resolución. */
    private function resolvedToken(): ?object
    {
        try {
            $guard = $this->auth->guard($this->guard);
        } catch (InvalidArgumentException) {
            // El dominio no declara ese guard: queda el `Bearer`, como en el portero.
            return null;
        }

        if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
            return null;
        }

        $user = $guard->user();

        return is_object($user) && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;
    }

    private function bearerToken(Request $request): ?object
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return null;
        }

        $model = Sanctum::personalAccessTokenModel();

        return $model::findToken($bearer);
    }
}
