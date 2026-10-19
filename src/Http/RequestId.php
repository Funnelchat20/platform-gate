<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Http;

use Funnelchat\PlatformGate\Edge\EdgeTrust;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * El id del pedido: el que el integrador cita al reportar un problema, y con el que soporte
 * encuentra la fila de `api_call_log` y los logs del dominio (contrato 1019-registro-llamadas, L1).
 *
 * De dónde sale:
 *
 *   - del borde, si el pedido trae el secreto válido y `X-Funnelchat-Request-Id` es UN UUID
 *     válido. El Worker borra el que manda el cliente y pone el suyo, así que el id que el
 *     cliente ve es el mismo de punta a punta;
 *   - si no, lo genera el portero (UUID v4). Un `X-Funnelchat-Request-Id` que llega sin el
 *     secreto lo pudo poner cualquiera: no se le cree, igual que al host y a la IP.
 *
 * Se decide una vez por pedido y queda en sus atributos, que el cliente no puede escribir.
 * Corre desde los mismos lugares que `EdgeTrust` —`TrustEdge`, el portero y el 401—, y la
 * segunda vez sólo lee lo decidido.
 *
 * Al decidirlo, se suma como `request_id` al contexto compartido del log del dominio: lo que
 * se loguee de ahí en adelante en ese pedido lo lleva. Al terminar el pedido se saca
 * (`forgetFromLog()`, desde el `terminate` del portero y de `TrustEdge`), para no depender de
 * cómo maneja el proceso el estado entre pedidos.
 *
 * Es un id al azar, no un identificador nuestro: devolverlo no le dice nada a nadie (D5).
 */
final readonly class RequestId
{
    public const ATTRIBUTE = 'platform-gate.request_id';

    /** La que pone el borde. La del cliente con el mismo nombre la borra el Worker. */
    public const EDGE_HEADER = 'X-Funnelchat-Request-Id';

    /** La que ve el cliente, en todas las respuestas. */
    public const RESPONSE_HEADER = 'X-Request-Id';

    public function __construct(
        private EdgeTrust $edge,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * El id de este pedido, decidido la primera vez que se pregunta.
     *
     * Para el dominio que lo necesite antes de la respuesta —un job que encola, un log propio—:
     * `app(RequestId::class)->for($request)`.
     */
    public function for(Request $request): string
    {
        $decided = self::of($request);

        if ($decided !== null) {
            return $decided;
        }

        // Primero el borde: decide si al pedido se le cree algo, y saca el secreto antes de
        // cualquier log de acá.
        $fromEdge = $this->edge->inspect($request);

        $id = ($fromEdge ? $this->fromEdge($request) : null) ?? self::generate();

        $request->attributes->set(self::ATTRIBUTE, $id);
        $this->shareWithLog($id);

        return $id;
    }

    /** El id ya decidido para este pedido, o `null` si nadie lo decidió todavía. */
    public static function of(Request $request): ?string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }

    /**
     * Le pone `X-Request-Id` a la respuesta, reemplazando el que haya. Es lo que hace el
     * portero: en un pedido que pasó por él hay una fila con este id, y si el dominio puso
     * otro, el cliente citaría uno que no está en ninguna.
     */
    public function stamp(Request $request, Response $response): Response
    {
        $response->headers->set(self::RESPONSE_HEADER, $this->for($request));

        return $response;
    }

    /**
     * Le pone `X-Request-Id` sólo si la respuesta no trae uno. Es lo que hace `TrustEdge`, que es
     * global: en lo que no pasó por el portero no hay fila que citar, y el id propio del dominio
     * —si tiene uno— es el que sus logs conocen.
     */
    public function stampIfMissing(Request $request, Response $response): Response
    {
        if (! $response->headers->has(self::RESPONSE_HEADER)) {
            $response->headers->set(self::RESPONSE_HEADER, $this->for($request));
        }

        return $response;
    }

    /** UUID v4, sin depender de `ramsey/uuid`: el paquete sólo pide `illuminate/support`. */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function fromEdge(Request $request): ?string
    {
        $values = $request->headers->all(strtolower(self::EDGE_HEADER));

        if ($values === []) {
            // El borde de antes de L2 no lo manda: no es un error, se genera.
            return null;
        }

        // Una sola cabecera, como el resto de lo que pone el borde. El valor no va al log:
        // no se sabe qué es.
        $id = count($values) === 1 ? strtolower(trim((string) $values[0])) : '';

        // Sin versión, `Str::isUuid()` es sólo el formato: no necesita `ramsey/uuid`.
        if (! Str::isUuid($id)) {
            $this->logger->warning(
                'platform-gate: el borde mandó un `X-Funnelchat-Request-Id` que no es un UUID: se ignora y el portero genera otro.'
            );

            return null;
        }

        return $id;
    }

    /**
     * Saca `request_id` del log y deja el resto del contexto como estaba.
     *
     * Dos lugares: el contexto compartido del `LogManager` —lo que reciben los canales que se
     * abran después— y el de cada canal ya abierto. Para el segundo hace falta
     * `withoutContext($keys)`; un `withoutContext()` sin parámetros vacía TODO el contexto del
     * canal, el del dominio incluido, y entonces los canales no se tocan.
     */
    public function forgetFromLog(): void
    {
        $logger = $this->logger;

        try {
            if (method_exists($logger, 'sharedContext') && method_exists($logger, 'flushSharedContext')) {
                $rest = array_diff_key((array) $logger->sharedContext(), ['request_id' => true]);

                $logger->flushSharedContext();

                if ($rest !== [] && method_exists($logger, 'shareContext')) {
                    $logger->shareContext($rest);
                }
            }

            if (method_exists($logger, 'withoutContext')
                && (new ReflectionMethod($logger, 'withoutContext'))->getNumberOfParameters() > 0) {
                $logger->withoutContext(['request_id']);
            }
        } catch (Throwable) {
            // Medición, no control: un log que no deja limpiar no rompe nada.
        }
    }

    /**
     * El contexto compartido es de Laravel (`Log::shareContext()`, 11+), no de PSR-3: si el log
     * del dominio no lo tiene, el id igual queda en la fila y en la respuesta.
     */
    private function shareWithLog(string $id): void
    {
        if (! method_exists($this->logger, 'shareContext')) {
            return;
        }

        try {
            $this->logger->shareContext(['request_id' => $id]);
        } catch (Throwable) {
            // Medición, no control: un log que no acepta contexto no rompe el pedido.
        }
    }
}
