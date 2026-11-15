<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\PublicIds;

use Funnelchat\PlatformGate\Caller\CallerContext;
use Funnelchat\PlatformGate\Caller\CallerKind;
use Illuminate\Container\Container;
use InvalidArgumentException;

/**
 * Lo que usa el dominio para los ids de la API pública (0.12.0, contrato `1411-ids-opacos`).
 *
 * | Dónde | Qué | Con `public_ids.enabled` apagado |
 * |---|---|---|
 * | Un Resource | `forResponse('contact', $this->id)`, `forResponseOrNull()` si puede faltar | el entero, igual que hoy |
 * | El `data` de un webhook | `forWebhook('contact', $contact->id)` | el entero |
 * | Un id del cuerpo o de un filtro | `fromRequest('device', $request->input('device_id'))` | el valor, sin tocar |
 * | Un parámetro de ruta | el middleware `public-ids` (`ResolvePublicIds`) | nada |
 *
 * Prendido, sólo las llamadas con key ven ids opacos: el panel usa las mismas rutas con su sesión
 * y sigue con enteros. Los webhooks no tienen llamador: salen opacos siempre que esté prendido, y
 * por la misma razón `forWebhook()` es lo que muestra el panel como «ID para la API».
 *
 * `encode()` y `decode()` son el codificador a secas: no miran el ajuste ni quién llama.
 */
final class PublicIds
{
    /**
     * Lo que devuelve `fromRequest()` en lugar de un id que no es válido: una fila con id 0 no
     * existe. Así un id inválido se comporta exactamente como uno inexistente —la regla `exists`
     * falla, `find()` devuelve `null`, un filtro no trae nada— y no como un campo vacío, que
     * en un filtro opcional devolvería todo.
     */
    public const NONEXISTENT = 0;

    private const LEGACY_INTEGER = '/^[1-9][0-9]{0,18}$/D';

    /** ¿Los ids de la API pública son opacos? */
    public static function enabled(): bool
    {
        return self::settings()->enabled;
    }

    /** Siempre opaco, con la clave vigente. Para lo que tiene que mostrar el id de la API aunque nadie llame con una key. */
    public static function encode(string $type, int $id): string
    {
        return self::settings()->codec()->encode($type, $id);
    }

    /** El entero, o `null` si no es un id de ese tipo hecho con una de las claves. No acepta enteros. */
    public static function decode(mixed $publicId, string $expectedType): ?int
    {
        return self::settings()->codec()->decode($publicId, $expectedType);
    }

    /**
     * El id como lo ve quien llama: opaco a una key con el ajuste prendido; el entero al panel, o
     * con el ajuste apagado.
     *
     * No acepta `null`: para un id que puede faltar está `forResponseOrNull()`. Son dos porque
     * Scramble documenta lo que declara la firma, y así un `id` no sale como «o `null`».
     *
     * @param int|string $id la clave del recurso; un string tiene que ser un entero positivo
     */
    public static function forResponse(string $type, int|string $id): int|string
    {
        if (! self::enabled() || ! self::callerIsKey()) {
            return $id;
        }

        return self::encode($type, self::integer($id));
    }

    /** `forResponse()` para un id que puede ser `null` (una relación opcional). `null` sigue `null`. */
    public static function forResponseOrNull(string $type, int|string|null $id): int|string|null
    {
        return $id === null ? null : self::forResponse($type, $id);
    }

    /**
     * El id para el `data` de un webhook: opaco con el ajuste prendido, el entero con él apagado.
     * No mira quién llama: un aviso siempre va a quien integra.
     *
     * @param int|string|null $id la clave del recurso; un string tiene que ser un entero positivo
     */
    public static function forWebhook(string $type, int|string|null $id): int|string|null
    {
        if ($id === null || ! self::enabled()) {
            return $id;
        }

        return self::encode($type, self::integer($id));
    }

    /**
     * Un id que llegó en el cuerpo o en un filtro de una llamada con key, como entero.
     *
     *   - Apagado, o el pedido no es de una key: el valor tal como llegó.
     *   - `null` o `''`: tal cual (que falte lo decide la validación del dominio).
     *   - Un id de ese tipo: su entero.
     *   - Un entero mientras rige `accept_legacy_until`: ese entero, y la respuesta lleva
     *     `Deprecation` y `Sunset`.
     *   - Cualquier otra cosa —otro tipo, alterado, un entero fuera de la ventana—:
     *     `NONEXISTENT` (0), para que el dominio conteste lo mismo que ante un id que no existe.
     *   - Una lista: cada elemento, con la misma regla.
     */
    public static function fromRequest(string $type, mixed $value): mixed
    {
        if (! self::enabled() || ! self::callerIsKey() || $value === null || $value === '') {
            return $value;
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::fromRequest($type, $item), $value);
        }

        return self::resolve($type, $value) ?? self::NONEXISTENT;
    }

    /**
     * El entero detrás de un valor de una llamada con key, o `null` si no es un id válido. Un
     * entero vale sólo mientras rija la ventana, y marca el pedido.
     *
     * @internal lo usan `fromRequest()` y `ResolvePublicIds`; no mira el ajuste ni quién llama
     */
    public static function resolve(string $type, mixed $value): ?int
    {
        $settings = self::settings();
        $id = $settings->codec()->decode($value, $type);

        if ($id !== null) {
            return $id;
        }

        $legacy = self::legacyInteger($value);

        if ($legacy === null || ! $settings->acceptsLegacy()) {
            return null;
        }

        Container::getInstance()->make(LegacyIdContext::class)->mark();

        return $legacy;
    }

    private static function legacyInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (! is_string($value) || preg_match(self::LEGACY_INTEGER, $value) !== 1) {
            return null;
        }

        // 19 dígitos pueden pasarse de `PHP_INT_MAX`: eso tampoco es un id.
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) ? $id : null;
    }

    private static function integer(int|string $id): int
    {
        $integer = is_int($id) ? $id : self::legacyInteger($id);

        if ($integer === null || $integer < 1) {
            throw new InvalidArgumentException('Un id público sólo se arma con la clave del recurso: un entero positivo.');
        }

        return $integer;
    }

    private static function callerIsKey(): bool
    {
        return Container::getInstance()->make(CallerContext::class)->kind() === CallerKind::ApiKey;
    }

    private static function settings(): PublicIdSettings
    {
        return Container::getInstance()->make(PublicIdSettings::class);
    }
}
