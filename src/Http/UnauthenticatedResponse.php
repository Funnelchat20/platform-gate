<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El 401 de la API pública, igual en todos los dominios (Q18):
 *
 *     401 {"error": "unauthenticated", "message": "…"}
 *
 * Detrás de una sola URL, quien integra no sabe a qué dominio le habló, así que "no estás
 * autenticado" no puede tener tres formas. Sin esto cada dominio contesta lo suyo: el
 * `{"message": "Unauthenticated."}` de Laravel, o —si el pedido no pide JSON— una
 * redirección al login, que en una API sin ruta `login` termina en 500.
 *
 * El portero no lo puede dar solo: el 401 lo tira el `auth:sanctum` del dominio antes de
 * que el portero corra. Por eso es algo que el dominio engancha en su `bootstrap/app.php`:
 *
 *     ->withExceptions(function (Exceptions $exceptions) {
 *         \Funnelchat\PlatformGate\Http\UnauthenticatedResponse::register($exceptions);
 *     })
 *
 * Sólo toca los pedidos a `api/*` (o a los patrones que se le pasen). El resto de la
 * aplicación sigue contestando como antes.
 */
final readonly class UnauthenticatedResponse
{
    public const MESSAGE = 'Falta la API key o no es válida.';

    /** @param list<string> $paths patrones de `Request::is()` */
    public function __construct(private array $paths = ['api/*'])
    {
    }

    public static function register(Exceptions $exceptions, string ...$paths): void
    {
        $exceptions->render(new self($paths === [] ? ['api/*'] : array_values($paths)));
    }

    public function __invoke(AuthenticationException $e, Request $request): ?JsonResponse
    {
        if (! $request->is(...$this->paths)) {
            return null;
        }

        return new JsonResponse(
            ['error' => 'unauthenticated', 'message' => self::MESSAGE],
            401,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
