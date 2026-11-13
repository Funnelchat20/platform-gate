<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http;

use Funnelchat\PlatformGate\Caller\ApiKeyRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * El formato de error de las llamadas con key, igual en todos los dominios (0.10, contrato
 * `1113-errores-y-limites`, puntos 1 y 2):
 *
 *     {"error": "<código>", "message": "<texto>"}      (+ `errors` en el 422)
 *
 * El portal promete eso para toda respuesta de error. Hasta 0.9 lo cumplía sólo el portero: los
 * dominios devolvían el `{"message": …}` de Laravel en 403, 404 y 422, y cada uno armaba a mano
 * otras formas. Escrito tres veces es como se separaron; por eso lo da la librería, igual que el
 * 401 de `UnauthenticatedResponse`. El dominio lo engancha en su `bootstrap/app.php`:
 *
 *     ->withExceptions(function (Exceptions $exceptions) {
 *         \Funnelchat\PlatformGate\Http\UnauthenticatedResponse::register($exceptions);
 *         \Funnelchat\PlatformGate\Http\ErrorResponse::register($exceptions);
 *     })
 *
 * Qué rinde, sólo para pedidos con key (`ApiKeyRequest`) a `api/*`:
 *
 *   - `ValidationException` → 422 `validation_failed`, con `errors`. `message` es el del
 *     validador del dominio, en su idioma.
 *   - Cualquier `HttpException` —también lo que Laravel convierte en una: modelo o ruta no
 *     encontrados, autorización, `throttle`— → el código de su status (`ErrorCode`), con sus
 *     cabeceras (`Retry-After` incluida). `message` es el que escribió el dominio
 *     (`abort(404, '…')`, `Response::deny('…')` en una policy) o, si el texto es el de Laravel
 *     —en inglés y a veces con el nombre de una clase—, el genérico en español de ese código.
 *
 * Siempre JSON, aunque el pedido no lo pida: a una key no se le contesta con una página HTML.
 *
 * Lo que NO toca: un pedido sin key —el panel— recibe exactamente lo de antes; tampoco una
 * `ValidationException` que ya trae su propia respuesta, una `HttpResponseException`, una
 * excepción con su propio `render()` ni un 500. Lo que el dominio arma a mano lo completa el
 * portero (`ErrorBody`) cuando pasa por él.
 *
 * Un `render` propio del dominio registrado **antes** que éste gana: queda el suyo.
 */
final readonly class ErrorResponse
{
    /** @param list<string> $paths patrones de `Request::is()` */
    public function __construct(private array $paths = ['api/*'])
    {
    }

    public static function register(Exceptions $exceptions, string ...$paths): void
    {
        $exceptions->render(new self($paths === [] ? ['api/*'] : array_values($paths)));
    }

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is(...$this->paths)) {
            return null;
        }

        $rendered = match (true) {
            $e instanceof ValidationException => $e->response === null ? self::validation($e) : null,
            $e instanceof HttpExceptionInterface => self::http($e, $request),
            default => null,
        };

        // Lo barato primero: la pregunta por la key puede costar una consulta.
        if ($rendered === null || ! Container::getInstance()->make(ApiKeyRequest::class)->is($request)) {
            return null;
        }

        [$payload, $status, $headers] = $rendered;

        $headers[RequestId::RESPONSE_HEADER] = Container::getInstance()->make(RequestId::class)->for($request);

        return new JsonResponse($payload, $status, $headers);
    }

    /** @return array{0: array<string, mixed>, 1: int, 2: array<string, mixed>} */
    private static function validation(ValidationException $e): array
    {
        return [
            ['error' => 'validation_failed', 'message' => $e->getMessage(), 'errors' => $e->errors()],
            $e->status,
            [],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: int, 2: array<string, mixed>} */
    private static function http(HttpExceptionInterface $e, Request $request): array
    {
        $status = $e->getStatusCode();
        $code = ErrorCode::forStatus($status);

        return [
            ['error' => $code, 'message' => self::ownMessage($e, $request) ?? ErrorCode::message($code)],
            $status,
            $e->getHeaders(),
        ];
    }

    /**
     * El texto que escribió el dominio, o `null` si es el de Laravel.
     *
     * Laravel arma la `HttpException` con el texto de la excepción original (`prepareException`):
     * «No query results for model [App\Models\Contact] 5.», «This action is unauthorized.»,
     * «Too Many Attempts.», «The route … could not be found.». Ninguno es para quien integra.
     * Se distingue sin comparar textos:
     *
     *   - viene de una `AuthorizationException`: es del dominio si la policy dio un texto, o sea
     *     si no es el que la excepción trae cuando nadie le pasa uno;
     *   - viene de cualquier otra (modelo, registros, enum, CSRF, pedido mal formado): de Laravel;
     *   - el `throttle` de Laravel: de Laravel;
     *   - no hay ruta resuelta: lo tiró el router (ruta o método inexistentes) o un middleware
     *     global como el de mantenimiento, no el dominio;
     *   - si no, es un `abort()` o una `HttpException` del dominio: suyo, si no está vacío.
     */
    private static function ownMessage(HttpExceptionInterface $e, Request $request): ?string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof AuthorizationException) {
            $own = $previous->getMessage();

            return $own !== '' && $own !== (new AuthorizationException())->getMessage() ? $own : null;
        }

        if ($previous !== null || $e instanceof ThrottleRequestsException || $request->route() === null) {
            return null;
        }

        return $e->getMessage() !== '' ? $e->getMessage() : null;
    }
}
