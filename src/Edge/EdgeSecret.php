<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Edge;

/**
 * El secreto que comparte el borde —el Worker de `api.funnelchat.com`— con cada dominio.
 *
 * Dos valores, el actual y el siguiente, para rotar sin cortar (Q11): se carga el nuevo
 * como siguiente en los dominios, después se cambia en el Worker, y recién entonces se lo
 * promueve a actual. Mientras dura la rotación valen los dos.
 *
 * La comparación es en tiempo constante y no dice cuál coincidió. Se comparan digests, que
 * tienen largo fijo —así `hash_equals` no corta antes por largo y el largo del secreto no se
 * filtra— y se recorren siempre los dos valores, sin salir en el primero que coincide.
 *
 * Un valor vacío es "no configurado": un header vacío nunca coincide con un secreto vacío.
 * Un valor corto tampoco cuenta: es adivinable, y es lo único que separa a quien llama a un
 * host interno de quien llega por el borde. Se descarta y queda en el log; con la exigencia
 * encendida, eso es un 503 para las keys, no un borde que cualquiera imita.
 */
final readonly class EdgeSecret
{
    /** `openssl rand -hex 32` da 64; `-base64 32`, 44. Cualquiera de los dos alcanza. */
    public const MIN_LENGTH = 32;

    /** @var list<string> */
    private array $digests;

    /**
     * Los nombres de lo que vino configurado y no se acepta (corto, o no es texto). Para que
     * quien construye esto lo diga en el log: acá no hay logger, y el valor no se nombra.
     *
     * @var list<string>
     */
    public array $rejected;

    /** @param array<string, mixed> $configured nombre de la clave => valor configurado */
    public function __construct(array $configured)
    {
        $digests = [];
        $rejected = [];

        foreach ($configured as $name => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            if (! is_string($value) || strlen(trim($value)) < self::MIN_LENGTH) {
                $rejected[] = (string) $name;

                continue;
            }

            $digests[] = hash('sha256', trim($value), true);
        }

        $this->digests = $digests;
        $this->rejected = $rejected;
    }

    public function isConfigured(): bool
    {
        return $this->digests !== [];
    }

    public function matches(?string $presented): bool
    {
        if ($presented === null || trim($presented) === '' || $this->digests === []) {
            return false;
        }

        $presented = hash('sha256', trim($presented), true);
        $matched = false;

        foreach ($this->digests as $digest) {
            // `hash_equals` a la izquierda: se evalúa siempre, coincida o no el anterior.
            $matched = hash_equals($digest, $presented) || $matched;
        }

        return $matched;
    }
}
