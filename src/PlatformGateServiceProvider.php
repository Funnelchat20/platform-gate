<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate;

use Funnelchat\PlatformGate\Caller\CallerContext;
use Funnelchat\PlatformGate\Caller\KeyIdentity;
use Funnelchat\PlatformGate\Caller\KindColumnProbe;
use Funnelchat\PlatformGate\Http\Middleware\PlatformGate;
use Funnelchat\PlatformGate\Limits\DailyCallCap;
use Funnelchat\PlatformGate\Permissions\PermissionChecker;
use Funnelchat\PlatformGate\Permissions\RoutePermissions;
use Funnelchat\PlatformGate\Usage\ApiCallRecorder;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PlatformGateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/platform-gate.php', 'platform-gate');

        // El contexto es del REQUEST, no de la aplicación: `scoped` para que en Octane o
        // en un worker de cola no se filtre el llamador de un request al siguiente.
        $this->app->scoped(CallerContext::class);

        $this->app->singleton(PermissionChecker::class);

        $this->app->singleton(RoutePermissions::class, function ($app): RoutePermissions {
            $domain = $app['config']->get('platform-gate.domain');

            if (! is_string($domain) || $domain === '') {
                throw new RuntimeException(
                    'platform-gate: falta `platform-gate.domain`. Sin eso no se puede saber qué '
                    .'permiso pedir (`{dominio}:read`, `{dominio}:write`, `{dominio}:send`).'
                );
            }

            return new RoutePermissions(
                $domain,
                (array) $app['config']->get('platform-gate.send', []),
                (array) $app['config']->get('platform-gate.operate', []),
                (array) $app['config']->get('platform-gate.denied', []),
                (array) $app['config']->get('platform-gate.allowed', []),
            );
        });

        $this->app->singleton(KeyIdentity::class, fn ($app) => new KeyIdentity(
            (string) $app['config']->get('app.key')
        ));

        $this->app->singleton(KindColumnProbe::class, fn ($app) => new KindColumnProbe(
            $app->make(DatabaseManager::class)
        ));

        $this->app->singleton(DailyCallCap::class, fn ($app) => new DailyCallCap(
            $app->make(CacheFactory::class)->store($app['config']->get('platform-gate.cache_store')),
            (int) $app['config']->get('platform-gate.daily_call_cap', 0),
            (array) $app['config']->get('platform-gate.daily_call_cap_overrides', []),
        ));

        $this->app->singleton(ApiCallRecorder::class, fn ($app) => new ApiCallRecorder(
            $app->make(DatabaseManager::class)->connection($app['config']->get('platform-gate.usage_connection')),
            (string) $app['config']->get('platform-gate.usage_table', 'api_call_log'),
            $app->make(LoggerInterface::class),
        ));

        // `scoped`, NO `singleton`: el middleware guarda estado del request en curso
        // (`keyIdForLog`, `startedAt`) para poder escribir la fila de uso en `terminate`.
        // Como singleton, bajo Octane el mismo objeto atiende requests sucesivos y ese
        // estado se filtra de uno al siguiente: filas de uso atribuidas a la key
        // equivocada, o a un request del front.
        $this->app->scoped(PlatformGate::class, fn ($app) => new PlatformGate(
            $app->make(CallerContext::class),
            $app->make(RoutePermissions::class),
            $app->make(PermissionChecker::class),
            $app->make(DailyCallCap::class),
            $app->make(ApiCallRecorder::class),
            $app->make(KeyIdentity::class),
            $app->make(KindColumnProbe::class),
            $app->make(LoggerInterface::class),
            $this->accountResolver(),
            (bool) $app['config']->get('platform-gate.block_on_unreadable_kind', false),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/platform-gate.php' => config_path('platform-gate.php'),
        ], 'platform-gate-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->make(Router::class)->aliasMiddleware('platform-gate', PlatformGate::class);
    }

    /**
     * @return callable(mixed):array{0:?int,1:bool}
     */
    private function accountResolver(): callable
    {
        $configured = $this->app['config']->get('platform-gate.account_resolver');

        if (is_callable($configured)) {
            return $configured;
        }

        // Default honesto: el id del usuario del token, y `false` en "es el dueño".
        // No adivinamos: si el dominio no nos dio su resolución de owner, no la tenemos,
        // y decirlo es lo que evita que alguien llavee un límite por asiento (P3).
        return static fn ($user): array => [$user?->getKey(), false];
    }
}
