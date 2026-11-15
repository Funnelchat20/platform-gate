<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http\Middleware;

use Closure;
use Funnelchat\PlatformGate\Caller\ApiKeyRequest;
use Funnelchat\PlatformGate\Exceptions\GateMisconfigured;
use Funnelchat\PlatformGate\Http\ErrorCode;
use Funnelchat\PlatformGate\Http\RequestId;
use Funnelchat\PlatformGate\PublicIds\LegacyIdContext;
use Funnelchat\PlatformGate\PublicIds\PublicIds;
use Funnelchat\PlatformGate\PublicIds\PublicIdSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los ids opacos en los parámetros de ruta de una llamada con key (0.12.0). La ruta declara
 * cuáles son y de qué tipo:
 *
 *     Route::get('contacts/{contact}', …)->middleware('public-ids:contact');          // parámetro = tipo
 *     Route::get('devices/{id}/qr', …)->middleware('public-ids:id=device');           // parámetro = tipo
 *     Route::get('contacts/{contact}/notes/{note}', …)->middleware('public-ids:contact,note');
 *
 * Con `public_ids.enabled` y una key, cada parámetro declarado se reemplaza por su entero antes de
 * que lo vea nadie más: el binding de modelos y el controlador reciben lo de siempre. Un valor que
 * no es un id de ese tipo —mal formado, de otro tipo, alterado, de una clave que no existe, o un
 * entero fuera de `accept_legacy_until`— corta con 404 `not_found`, el mismo cuerpo que el de un
 * recurso que no existe.
 *
 * Apagado, o con el panel, no hace nada: el parámetro llega como llegó.
 *
 * **Corre antes que `SubstituteBindings`**: el service provider lo pone ahí en la lista de
 * prioridad de Laravel. Si corriera después, el binding buscaría `ctc_…` en la base y daría 404
 * antes de que el id se pudiera leer. Por eso tampoco puede esperar al portero, que corre
 * después del binding: pregunta si el pedido es de una key con `ApiKeyRequest`, igual que el
 * formato de error.
 */
final class ResolvePublicIds
{
    public function __construct(
        private readonly PublicIdSettings $settings,
        private readonly LegacyIdContext $legacy,
        private readonly ApiKeyRequest $apiKeys,
        private readonly RequestId $requestIds,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$declared): Response
    {
        if (! $this->settings->enabled || ! $this->apiKeys->is($request)) {
            return $next($request);
        }

        $route = $request->route();
        $parameters = $route instanceof Route ? $this->parameters($route, $declared) : null;

        if ($parameters === null || $this->settings->problem() !== null) {
            $this->logger->error('platform-gate: los ids públicos de esta ruta no se pueden resolver; se corta.', [
                'route' => $route instanceof Route ? $route->uri() : null,
                'declared' => $declared,
                'problem' => $this->settings->problem(),
            ]);

            return $this->requestIds->stamp($request, $this->misconfigured());
        }

        foreach ($parameters as $name => $type) {
            if (! $route->hasParameter($name)) {
                continue; // un parámetro opcional que no vino
            }

            $id = PublicIds::resolve($type, $route->parameter($name));

            if ($id === null) {
                return $this->requestIds->stamp($request, $this->legacy->stamp($this->notFound(), $this->settings));
            }

            $route->setParameter($name, $id);
        }

        return $this->legacy->stamp($next($request), $this->settings);
    }

    public function terminate(): void
    {
        $this->legacy->clear();
    }

    /**
     * `contact` o `id=device` → [parámetro => tipo]. `null` si la declaración no se puede usar:
     * un parámetro que la ruta no tiene o un tipo que no está registrado. Es un error del
     * dominio, y no se adivina: dejarlo pasar le daría `ctc_…` al binding o al controlador.
     *
     * @param list<string> $declared
     * @return array<string, string>|null
     */
    private function parameters(Route $route, array $declared): ?array
    {
        $parameters = [];

        foreach ($declared as $entry) {
            [$name, $type] = str_contains($entry, '=') ? explode('=', $entry, 2) : [$entry, $entry];

            if (! in_array($name, $route->parameterNames(), true) || ! $this->settings->types()->has($type)) {
                return null;
            }

            $parameters[$name] = $type;
        }

        return $parameters === [] ? null : $parameters;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found', 'message' => ErrorCode::message('not_found')], 404);
    }

    private function misconfigured(): JsonResponse
    {
        $e = new GateMisconfigured('public_ids');

        return new JsonResponse(['error' => $e->errorCode(), 'message' => $e->getMessage()], $e->status());
    }
}
