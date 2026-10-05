<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Feature;

use Funnelchat\PlatformGate\Caller\KeyIdentity;
use Funnelchat\PlatformGate\PlatformGateServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * El `key_id` sale del secreto compartido cuando está definido, y del `APP_KEY` si no.
 *
 * Lo que importa: dos dominios con `APP_KEY` distintos y el mismo secreto le dan a la
 * misma key el mismo `key_id`, que es lo que deja juntar el consumo de los tres registros.
 */
final class KeyIdSecretTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PlatformGateServiceProvider::class];
    }

    private function keyIdWith(string $appKey, ?string $secret, int $tokenId): string
    {
        $this->app['config']->set('app.key', $appKey);
        $this->app['config']->set('platform-gate.key_id_secret', $secret);
        $this->app->forgetInstance(KeyIdentity::class);

        return $this->app->make(KeyIdentity::class)->for($tokenId);
    }

    public function test_without_shared_secret_the_key_id_depends_on_the_app_key(): void
    {
        $conversations = $this->keyIdWith('base64:conversations-app-key', null, 876);
        $communities = $this->keyIdWith('base64:communities-app-key', null, 876);

        $this->assertNotSame($conversations, $communities);
        $this->assertSame(
            'key_'.substr(hash_hmac('sha256', '876', 'base64:conversations-app-key'), 0, 24),
            $conversations,
        );
    }

    public function test_with_shared_secret_the_same_key_has_the_same_key_id_in_every_domain(): void
    {
        $conversations = $this->keyIdWith('base64:conversations-app-key', 'shared-secret', 876);
        $communities = $this->keyIdWith('base64:communities-app-key', 'shared-secret', 876);
        $accounts = $this->keyIdWith('base64:accounts-app-key', 'shared-secret', 876);

        $this->assertSame($conversations, $communities);
        $this->assertSame($conversations, $accounts);
        $this->assertSame('key_'.substr(hash_hmac('sha256', '876', 'shared-secret'), 0, 24), $conversations);
    }

    public function test_an_empty_shared_secret_falls_back_to_the_app_key(): void
    {
        $this->assertSame(
            $this->keyIdWith('base64:accounts-app-key', null, 12),
            $this->keyIdWith('base64:accounts-app-key', '', 12),
        );
    }

    public function test_different_tokens_get_different_key_ids_under_the_shared_secret(): void
    {
        $this->assertNotSame(
            $this->keyIdWith('base64:accounts-app-key', 'shared-secret', 876),
            $this->keyIdWith('base64:accounts-app-key', 'shared-secret', 877),
        );
    }
}
