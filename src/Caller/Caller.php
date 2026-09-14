<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Caller;

/**
 * El llamador resuelto, tal como el portero lo vio.
 *
 * Es inmutable y serializable a propósito: los dominios necesitan leerlo también desde
 * jobs de cola, fuera del ciclo de request (pedido P1). Un job que envía mensajes tiene
 * que poder saber que lo originó una key.
 *
 * NADA de esto se expone jamás al cliente: ni en respuestas, ni en headers, ni en
 * webhooks. `toArray()` existe para propagar a un job, no para serializar a una respuesta.
 */
final readonly class Caller
{
    private function __construct(
        public CallerKind $kind,
        /** Identificador opaco y estable de la key. `null` para web/unverified (pedido P2). */
        public ?string $keyId,
        /** La cuenta que resolvió el portero, para uso interno del servidor. */
        public ?int $accountId,
        /**
         * Si `accountId` es el DUEÑO de la cuenta o un asiento (pedido P3).
         *
         * Importa: un límite llaveado por asiento se multiplica por la cantidad de
         * asientos. Si esto es `false`, el dominio tiene que normalizar con su propia
         * resolución de owner antes de llavear nada.
         */
        public bool $accountIsOwner,
        /**
         * Los permisos que trae la key, tal como se emitieron. Lista vacía para web y
         * para no verificado.
         *
         * El portero ya chequeó el de esta ruta; esto es para que el dominio pueda
         * **ramificar** por permiso cuando su comportamiento depende de qué puede hacer
         * el que llama y no sólo de si puede llamar. Lo pidió communities: su partición
         * tiene un grado `operate` —mutar la sesión de WhatsApp: crear grupos, agregar
         * admins, salir de un grupo— que quema el número del cliente sin enviar un solo
         * mensaje, y hay caminos donde el dominio necesita saber si lo tiene antes de
         * decidir qué hace.
         *
         * @var list<string>
         */
        public array $permissions = [],
    ) {
    }

    /** @param list<string> $permissions */
    public static function apiKey(string $keyId, ?int $accountId, bool $accountIsOwner, array $permissions = []): self
    {
        return new self(CallerKind::ApiKey, $keyId, $accountId, $accountIsOwner, $permissions);
    }

    /** ¿La key trae este permiso? El portero ya validó el de la ruta; esto es para ramificar. */
    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public static function web(?int $accountId, bool $accountIsOwner): self
    {
        return new self(CallerKind::Web, null, $accountId, $accountIsOwner);
    }

    public static function unverified(): self
    {
        return new self(CallerKind::Unverified, null, null, false);
    }

    /** @return array{kind: string, key_id: string|null, account_id: int|null, account_is_owner: bool} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'key_id' => $this->keyId,
            'account_id' => $this->accountId,
            'account_is_owner' => $this->accountIsOwner,
            'permissions' => $this->permissions,
        ];
    }

    /** @param array{kind: string, key_id?: string|null, account_id?: int|null, account_is_owner?: bool, permissions?: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            CallerKind::from($data['kind']),
            $data['key_id'] ?? null,
            $data['account_id'] ?? null,
            $data['account_is_owner'] ?? false,
            $data['permissions'] ?? [],
        );
    }
}
