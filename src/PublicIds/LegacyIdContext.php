<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\PublicIds;

use Symfony\Component\HttpFoundation\Response;

/**
 * ¿Este pedido usó un entero donde va un id público? (0.12.0) Del pedido en curso (`scoped`),
 * como `CreatedResourceContext`: lo marca quien decodifica —`ResolvePublicIds` en la ruta,
 * `PublicIds::fromRequest()` en el cuerpo o un filtro— y lo lee quien arma la respuesta, que es
 * el que le pone `Deprecation` y `Sunset`.
 *
 * No en los atributos del pedido: el FormRequest que recibe el controlador es una copia, y lo
 * que se escribe en sus atributos el portero no lo ve.
 */
final class LegacyIdContext
{
    private bool $used = false;

    public function mark(): void
    {
        $this->used = true;
    }

    public function used(): bool
    {
        return $this->used;
    }

    public function clear(): void
    {
        $this->used = false;
    }

    /** Si el pedido usó un entero, le pone a la respuesta las cabeceras de la ventana. */
    public function stamp(Response $response, PublicIdSettings $settings): Response
    {
        if ($this->used) {
            foreach ($settings->legacyHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
