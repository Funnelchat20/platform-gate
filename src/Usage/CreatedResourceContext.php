<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Usage;

/**
 * Dónde vive lo que declaró el controlador durante el pedido. `scoped` en el contenedor, como
 * `CallerContext`.
 *
 * No va en los atributos del pedido: el FormRequest que recibe el controlador es una COPIA
 * (`Request::createFrom()` copia los atributos a una bolsa nueva), y lo que se escribe en la
 * copia no lo ve el portero, que tiene el original. Se perdía en silencio justo en las rutas
 * que crean algo, que son las que validan con un FormRequest.
 *
 * El portero lo vacía al empezar cada pedido que pasa por él y al terminarlo: una declaración
 * no puede quedar pegada al pedido siguiente.
 */
final class CreatedResourceContext
{
    private ?CreatedResource $declared = null;

    public function set(CreatedResource $resource): void
    {
        $this->declared = $resource;
    }

    public function get(): ?CreatedResource
    {
        return $this->declared;
    }

    public function clear(): void
    {
        $this->declared = null;
    }
}
