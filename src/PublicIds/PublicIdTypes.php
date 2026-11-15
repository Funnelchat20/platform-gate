<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\PublicIds;

use InvalidArgumentException;

/**
 * El registro de tipos de id público y sus prefijos (0.12.0, contrato `1411-ids-opacos`).
 *
 * El prefijo es lo que identifica al tipo **entre dominios**: va adelante del id (`ctc_…`) y
 * también adentro del bloque cifrado, como código del tipo. Por eso es el mismo en los tres
 * dominios, y por eso un dominio no puede cambiarle el prefijo a un tipo de la tabla base: el
 * `device_id` que devuelve accounts tiene que ser el que acepta conversations.
 *
 * El nombre del tipo (`contact`, `device`) es sólo cómo lo llama el código del dominio, y de
 * dónde sale el nombre del campo que la documentación reconoce sola (`contact_id`).
 *
 * Cada dominio suma los suyos en `platform-gate.public_ids.types` (nombre => prefijo). Un tipo
 * nuevo se acuerda con la coordinación, con un prefijo que nadie use: este registro sólo puede
 * ver los choques dentro de un dominio.
 */
final class PublicIdTypes
{
    /**
     * La tabla del contrato. Los ids que ya son opacos o de Meta (el `uid` de un grupo, el `uuid`
     * de una campaña, el `uid` de un mensaje y de una acción de grupo, los ids de WABA) no están:
     * quedan como están.
     *
     * @var array<string, string>
     */
    public const BASE = [
        'device' => 'dev',
        'contact' => 'ctc',
        'tag' => 'tag',
        'custom_field' => 'fld',
        'template' => 'tpl',
        'flow' => 'flw',
        'flow_run' => 'run',
        'broadcast' => 'bcs',
        'folder' => 'fdr',
        'campaign' => 'cmp',
        'pipeline' => 'pip',
        'stage' => 'stg',
        'board' => 'knb',
        'note' => 'not',
        'quick_reply' => 'qrp',
        'user' => 'usr',
        'plan' => 'pln',
        'custom_domain' => 'dom',
        'signup_link' => 'lnk',
        'message' => 'msg',
        'action' => 'act',
        'webhook_subscription' => 'whk',
    ];

    private const TYPE = '/^[a-z][a-z0-9_]{0,31}$/D';

    private const PREFIX = '/^[a-z]{3}$/D';

    /** @param array<string, string> $prefixes nombre => prefijo, ya validado */
    private function __construct(private readonly array $prefixes)
    {
    }

    /**
     * La tabla base más lo que declara el dominio.
     *
     * Falla —y el portero corta con 500 `gate_misconfigured`— si lo declarado no se puede usar
     * sin adivinar: un nombre o un prefijo con otra forma, un tipo base con otro prefijo, o dos
     * tipos con el mismo prefijo. Repetir un tipo base con su mismo prefijo no es un error.
     *
     * @throws InvalidArgumentException con un texto que se puede loguear: no lleva secretos
     */
    public static function with(mixed $extra): self
    {
        if ($extra === null) {
            $extra = [];
        }

        if (! is_array($extra) || ($extra !== [] && array_is_list($extra))) {
            throw new InvalidArgumentException('`public_ids.types` tiene que ser un mapa nombre => prefijo.');
        }

        $prefixes = self::BASE;

        foreach ($extra as $type => $prefix) {
            if (! is_string($type) || preg_match(self::TYPE, $type) !== 1) {
                throw new InvalidArgumentException('`public_ids.types`: un nombre de tipo no es válido (minúsculas, números y `_`, hasta 32, empezando con letra).');
            }

            if (! is_string($prefix) || preg_match(self::PREFIX, $prefix) !== 1) {
                throw new InvalidArgumentException("`public_ids.types`: el prefijo de `{$type}` no son tres letras minúsculas.");
            }

            if (isset($prefixes[$type]) && $prefixes[$type] !== $prefix) {
                throw new InvalidArgumentException("`public_ids.types`: `{$type}` ya tiene el prefijo `{$prefixes[$type]}` y no se puede cambiar: es el mismo en todos los dominios.");
            }

            $prefixes[$type] = $prefix;
        }

        $owners = [];

        foreach ($prefixes as $type => $prefix) {
            if (isset($owners[$prefix])) {
                throw new InvalidArgumentException("`public_ids.types`: `{$owners[$prefix]}` y `{$type}` tienen el mismo prefijo `{$prefix}`.");
            }

            $owners[$prefix] = $type;
        }

        return new self($prefixes);
    }

    /** @throws InvalidArgumentException si el tipo no está registrado: es un error del código, no del pedido */
    public function prefixOf(string $type): string
    {
        return $this->prefixes[$type] ?? throw new InvalidArgumentException(
            "No hay un tipo de id público `{$type}`. Los de la tabla base están en `PublicIdTypes::BASE`; "
            .'los propios del dominio se declaran en `platform-gate.public_ids.types`.'
        );
    }

    public function has(string $type): bool
    {
        return isset($this->prefixes[$type]);
    }

    /** @return array<string, string> nombre => prefijo */
    public function all(): array
    {
        return $this->prefixes;
    }
}
