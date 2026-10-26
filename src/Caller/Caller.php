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
        /** Ver `workspaceId()`. Sólo una key lo trae: las fábricas de web y no verificado no lo reciben. */
        private ?int $workspaceId = null,
    ) {
    }

    /** @param list<string> $permissions */
    public static function apiKey(string $keyId, ?int $accountId, bool $accountIsOwner, array $permissions = [], ?int $workspaceId = null): self
    {
        return new self(CallerKind::ApiKey, $keyId, $accountId, $accountIsOwner, $permissions, $workspaceId);
    }

    /** ¿La key trae este permiso? El portero ya validó el de la ruta; esto es para ramificar. */
    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * El workspace de la key (0.9): cada key es de un workspace, y el `X-Workspace-Id` que mande
     * el cliente no lo elige —el portero ya lo reemplazó por éste—.
     *
     * `null` en dos casos que el dominio no tiene que distinguir: una key sin workspace, que es
     * del **por defecto** de la cuenta, y cualquier llamador que no sea key. La sesión web sigue
     * con su mecanismo de siempre: su workspace lo dice su header, no esto.
     */
    public function workspaceId(): ?int
    {
        return $this->workspaceId;
    }

    public static function web(?int $accountId, bool $accountIsOwner): self
    {
        return new self(CallerKind::Web, null, $accountId, $accountIsOwner);
    }

    public static function unverified(): self
    {
        return new self(CallerKind::Unverified, null, null, false);
    }

    /** @return array{kind: string, key_id: string|null, account_id: int|null, account_is_owner: bool, permissions: list<string>, workspace_id: int|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'key_id' => $this->keyId,
            'account_id' => $this->accountId,
            'account_is_owner' => $this->accountIsOwner,
            'permissions' => $this->permissions,
            'workspace_id' => $this->workspaceId,
        ];
    }

    /**
     * Un array de 0.8.x —un job que se encoló antes de subir— no trae `workspace_id` y se lee
     * con `null`, o sea el por defecto. Fuera de una key se descarta aunque venga: lo que no es
     * key no tiene workspace propio.
     *
     * @param array{kind: string, key_id?: string|null, account_id?: int|null, account_is_owner?: bool, permissions?: list<string>, workspace_id?: int|null} $data
     */
    public static function fromArray(array $data): self
    {
        $kind = CallerKind::from($data['kind']);

        return new self(
            $kind,
            $data['key_id'] ?? null,
            $data['account_id'] ?? null,
            $data['account_is_owner'] ?? false,
            $data['permissions'] ?? [],
            $kind === CallerKind::ApiKey ? ($data['workspace_id'] ?? null) : null,
        );
    }
}
