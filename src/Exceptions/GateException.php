<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Exceptions;

use RuntimeException;

/**
 * Base de lo que el portero rechaza.
 *
 * La distinción que importa (pedido P7, y doctrina de esta base): "evalué y está
 * denegado" es TERMINAL, "no pude evaluar" es REINTENTABLE. Son dos respuestas
 * distintas para el cliente y dos métricas distintas para nosotros. Nunca se
 * colapsan en un 403 genérico.
 */
abstract class GateException extends RuntimeException
{
    abstract public function status(): int;

    /** Código estable que el cliente puede machear. No cambia aunque cambie el texto. */
    abstract public function errorCode(): string;

    public function retryAfter(): ?int
    {
        return null;
    }
}
