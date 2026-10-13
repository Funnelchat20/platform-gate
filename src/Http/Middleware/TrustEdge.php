<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http\Middleware;

use Closure;
use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Illuminate\Http\Request;
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
 */
final class TrustEdge
{
    public function __construct(private readonly EdgeTrust $edge)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->edge->inspect($request);

        return $next($request);
    }
}
