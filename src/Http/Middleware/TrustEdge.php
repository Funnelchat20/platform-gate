<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http\Middleware;

use Closure;
use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Funnelchat\PlatformGate\Http\RequestId;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le cree al borde lo antes posible: middleware GLOBAL, agregado al final del stack global
 * (`$middleware->append(...)`), así lo ve todo el pedido —el `throttle` del grupo `api`, el
 * log del dominio— y no sólo lo que corre después del portero.
 *
 * El portero hace la misma inspección si esto no está montado, porque la exigencia no puede
 * depender de que alguien se acuerde de montarlo. Lo que se pierde sin esto es que lo que
 * corre ANTES del portero vea el host interno y la IP del Worker.
 *
 * Va al final y no al principio a propósito: el `TrustProxies` de Laravel mira el host
 * original para decidir en qué proxies confía (`*.on-vapor.com`, Laravel Cloud), y no tiene
 * que ver el ya reescrito.
 *
 * Desde 0.8 también decide el id del pedido (`RequestId`): así el log de todo el pedido lo
 * lleva, y `X-Request-Id` sale en todas las respuestas del dominio —también un 500 o un 401
 * que no pasaron por el portero—. Sólo si la respuesta no trae uno: en los pedidos que pasan
 * por el portero, el portero ya puso el suyo, reemplazando el del dominio; en el resto, el
 * del dominio se respeta.
 */
final class TrustEdge
{
    private readonly RequestId $requestIds;

    public function __construct(private readonly EdgeTrust $edge, ?RequestId $requestIds = null)
    {
        $this->requestIds = $requestIds ?? new RequestId($edge, app(LoggerInterface::class));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->edge->inspect($request);
        $this->requestIds->for($request);

        return $this->requestIds->stampIfMissing($request, $next($request));
    }
}
