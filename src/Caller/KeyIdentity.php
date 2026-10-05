<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Caller;

/**
 * El identificador opaco y estable de una key (pedido P2).
 *
 * Dos requisitos que se contradicen si uno no tiene cuidado: tiene que ser **estable**
 * —dos llamadas de la misma key traen el mismo valor, si no el soporte no puede decirle a
 * un invitado cuál de sus workflows se desbocó— y tiene que ser **opaco**: no es, ni
 * deriva legiblemente de, `user_id` ni `account_id` (D5).
 *
 * Un HMAC resuelve las dos: determinístico para el mismo id de token, y sin significado
 * derivable para quien lo lea en un log sin el secreto.
 *
 * El secreto es `platform-gate.key_id_secret` si está definido —el mismo en todos los
 * dominios, para que la misma key tenga el mismo `key_id` en todos y el consumo se pueda
 * juntar— y si no, el `APP_KEY` del dominio. Ojo con la estabilidad: si rota el secreto,
 * rotan todos los `key_id`. Es aceptable —son correlación de logs, no una clave
 * primaria— pero hay que saberlo antes de llavear algo persistente con esto.
 */
final readonly class KeyIdentity
{
    public function __construct(private string $secret)
    {
    }

    public function for(int|string $tokenId): string
    {
        return 'key_'.substr(hash_hmac('sha256', (string) $tokenId, $this->secret), 0, 24);
    }
}
