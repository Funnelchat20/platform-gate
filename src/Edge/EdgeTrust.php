<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Edge;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ¿Este pedido viene del borde? Y si viene, qué se le cree.
 *
 * La API pública tiene una sola dirección, `https://api.funnelchat.com`: un Worker que
 * reparte a los dominios y en cada pedido que reenvía pone tres cabeceras (contrato
 * 1012-url-unica, §2):
 *
 *   X-Funnelchat-Edge ........ el secreto del borde
 *   X-Forwarded-Host ......... el host público del pedido
 *   X-Funnelchat-Client-IP ... la IP del cliente, de `CF-Connecting-IP`
 *
 * Las dos últimas las puede mandar cualquiera directo a un host interno. Por eso sólo se
 * creen **con el secreto válido** (Q13), y aun así el host sólo si está en la lista de hosts
 * públicos. Sin secreto válido no se tocan: el dominio deja de creer `X-Forwarded-Host` en
 * su `trustProxies`, y así se cierra el envenenamiento de host que se vio en staging el 09-10
 * —cualquiera cambiaba los links que arma Laravel mandando esa cabecera—.
 *
 * Creerle al borde es reescribir el pedido, no guardar un dato aparte: el `Host` pasa a ser
 * el público, así que `$request->url()`, el paginador y `url()`/`route()` salen con él; y
 * `X-Forwarded-For` pasa a ser la IP del cliente, así que `$request->ip()` —registro y
 * límites por IP del dominio— la devuelve, siempre que el dominio crea `X-Forwarded-For` de
 * su proxy inmediato, que es lo que pide la guía de actualización.
 *
 * Se decide una vez por pedido y queda en sus atributos, que el cliente no puede escribir.
 * Corre desde dos lugares —el middleware global `TrustEdge`, para que todo el pedido lo vea,
 * y el portero, que no puede depender de que el dominio lo haya montado— y la segunda vez
 * sólo lee el veredicto.
 *
 * El secreto se saca apenas se lee, valga o no, de todo lo que lee un reporte de errores: las
 * cabeceras y el `server` del pedido, y `$_SERVER` / `$_ENV`, de donde un reporte arma el
 * pedido cuando todavía no tiene el de Laravel —Sentry lo hace con un log emitido antes de
 * su middleware—. Y nada de esta clase se loguea antes de sacarlo (auditoría de communities,
 * 13-10). Lo que PHP guarda del pedido fuera de esos arrays —`getallheaders()` en PHP-FPM— no
 * se puede borrar desde acá: eso lo cierra el `before_send` del dominio (README, «El borde»).
 */
final class EdgeTrust
{
    public const VERIFIED = 'platform-gate.edge_verified';

    public const SECRET_HEADER = 'X-Funnelchat-Edge';

    public const CLIENT_IP_HEADER = 'X-Funnelchat-Client-IP';

    private const SECRET_SERVER_KEY = 'HTTP_X_FUNNELCHAT_EDGE';

    /**
     * @param list<string> $publicHosts en minúsculas, `host` o `host:puerto`
     * @param list<array{string, array<string, mixed>}> $configErrors mensaje y contexto, para el log
     */
    public function __construct(
        private readonly EdgeSecret $secret,
        private readonly array $publicHosts,
        private readonly LoggerInterface $logger,
        private array $configErrors = [],
    ) {
    }

    /**
     * Lee la configuración tal como la deja el dominio, y anota lo que no acepta.
     *
     * Lo que no se puede leer no se cree: un secreto corto no es secreto, y un host que no
     * es un host no entra a la lista. Nunca se loguea un valor de secreto.
     *
     * Lo anotado no se loguea acá: esto corre cuando el contenedor arma `TrustEdge`, con el
     * secreto todavía en el pedido, y un reporte de errores que lo levante en ese momento se
     * lo llevaría. Se loguea en el primer `inspect()`, ya sin el secreto.
     */
    public static function fromConfig(mixed $secret, mixed $nextSecret, mixed $publicHosts, LoggerInterface $logger): self
    {
        $edgeSecret = new EdgeSecret([
            'PLATFORM_GATE_EDGE_SECRET' => $secret,
            'PLATFORM_GATE_EDGE_SECRET_NEXT' => $nextSecret,
        ]);

        $errors = [];

        foreach ($edgeSecret->rejected as $name) {
            $errors[] = [
                "platform-gate: `{$name}` no se usa: tiene menos de ".EdgeSecret::MIN_LENGTH
                .' caracteres o no es texto. Generalo con `openssl rand -hex 32`.',
                [],
            ];
        }

        $hosts = self::readHosts($publicHosts, $errors);

        return new self($edgeSecret, $hosts, $logger, $errors);
    }

    public function isConfigured(): bool
    {
        return $this->secret->isConfigured();
    }

    /**
     * Decide si el pedido viene del borde y, si viene, le cree el host y la IP.
     *
     * Idempotente: la segunda llamada sobre el mismo pedido sólo devuelve el veredicto.
     */
    public function inspect(Request $request): bool
    {
        if ($request->attributes->has(self::VERIFIED)) {
            return $request->attributes->get(self::VERIFIED) === true;
        }

        $presented = $request->headers->all(strtolower(self::SECRET_HEADER));

        // Primero se saca, después se loguea: cualquier log de acá en adelante puede terminar
        // en un reporte de errores que arma el pedido en ese momento.
        $this->forgetSecret($request);
        $this->reportConfigErrors();

        // Una sola cabecera, y que coincida. Dos es un pedido que el Worker no arma —borra
        // la del cliente antes de poner la suya—, así que no se elige una.
        $verified = count($presented) === 1 && $this->secret->matches((string) $presented[0]);

        $request->attributes->set(self::VERIFIED, $verified);

        if (! $verified && $presented !== []) {
            $this->logger->warning($this->secret->isConfigured()
                ? 'platform-gate: llegó `X-Funnelchat-Edge` y no coincide con ninguno de los dos secretos. '
                    .'Si se está rotando, revisá que el nuevo esté cargado como siguiente en este dominio.'
                : 'platform-gate: llegó `X-Funnelchat-Edge` y este dominio no tiene secreto del borde '
                    .'configurado (`PLATFORM_GATE_EDGE_SECRET`): no se le cree ni el host ni la IP.');
        }

        if ($verified) {
            $this->trustPublicHost($request);
            $this->trustClientIp($request);
        }

        return $verified;
    }

    /**
     * Las cabeceras y el `server` del pedido, y los globales: sobre todo `$_SERVER`, de donde
     * un reporte de errores arma el pedido si todavía no tiene el de Laravel. Si el pedido es
     * un sub-pedido, igual se limpian: el secreto no tiene que estar en ningún lado.
     */
    private function forgetSecret(Request $request): void
    {
        $request->headers->remove(self::SECRET_HEADER);
        $request->server->remove(self::SECRET_SERVER_KEY);

        unset($_SERVER[self::SECRET_SERVER_KEY], $_ENV[self::SECRET_SERVER_KEY]);
    }

    /** Una vez por proceso: el singleton se queda sin nada que decir después del primer pedido. */
    private function reportConfigErrors(): void
    {
        foreach ($this->configErrors as [$message, $context]) {
            $this->logger->error($message, $context);
        }

        $this->configErrors = [];
    }

    private function trustPublicHost(Request $request): void
    {
        $values = $request->headers->all('x-forwarded-host');

        if ($values === []) {
            return;
        }

        $host = count($values) === 1 ? strtolower(trim((string) $values[0])) : '';

        if (! in_array($host, $this->publicHosts, true)) {
            $this->logger->warning(
                'platform-gate: el borde mandó un `X-Forwarded-Host` que no está en `PLATFORM_GATE_PUBLIC_HOSTS`: '
                .'se ignora y los links salen con el host interno.',
                ['forwarded_host' => $host, 'public_hosts' => $this->publicHosts],
            );

            return;
        }

        $request->headers->set('Host', $host);
        $request->server->set('HTTP_HOST', $host);

        $this->forgetCachedRoot($request);
    }

    private function trustClientIp(Request $request): void
    {
        $values = $request->headers->all(strtolower(self::CLIENT_IP_HEADER));

        if ($values === []) {
            return;
        }

        $ip = count($values) === 1 ? trim((string) $values[0]) : '';

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->logger->warning('platform-gate: el borde mandó una `X-Funnelchat-Client-IP` que no es una IP: se ignora.');

            return;
        }

        // Una sola entrada: el borde ya borró lo que mandó el cliente, y lo que agregaron
        // los proxies después del borde es el borde mismo, no el cliente.
        $request->headers->set('X-Forwarded-For', $ip);
        $request->server->set('HTTP_X_FORWARDED_FOR', $ip);
    }

    /**
     * El generador de URLs guarda la raíz la primera vez que arma una. Si algo armó una antes
     * de este punto, quedó con el host interno; se la hace olvidar. Sólo si su pedido es éste:
     * un sub-pedido no le cambia el host a la respuesta de nadie.
     */
    private function forgetCachedRoot(Request $request): void
    {
        try {
            $container = Container::getInstance();
            $url = $container->bound('url') ? $container->make('url') : null;
        } catch (Throwable) {
            return;
        }

        if ($url instanceof UrlGenerator && $url->getRequest() === $request) {
            $url->setRequest($request);
        }
    }

    /**
     * @param list<array{string, array<string, mixed>}> $errors se le agrega lo que no se acepta
     *
     * @return list<string>
     */
    private static function readHosts(mixed $configured, array &$errors): array
    {
        if ($configured === null || $configured === '') {
            return [];
        }

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            $errors[] = ['platform-gate: `PLATFORM_GATE_PUBLIC_HOSTS` no se puede leer: no se cree ningún host público.', []];

            return [];
        }

        $hosts = [];

        foreach ($configured as $host) {
            $host = is_string($host) ? strtolower(trim($host)) : '';

            if ($host === '') {
                continue;
            }

            // Un host, con puerto si hace falta (local). Una URL entera no: es el error más
            // probable, y aceptarla a medias sería creerle algo que nadie escribió.
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d{1,5})?$/', $host) !== 1) {
                $errors[] = [
                    'platform-gate: un valor de `PLATFORM_GATE_PUBLIC_HOSTS` no es un host y se ignora. '
                    .'Va el host solo, sin `https://` ni ruta: `api.funnelchat.com`.',
                    ['value' => $host],
                ];

                continue;
            }

            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }
}
