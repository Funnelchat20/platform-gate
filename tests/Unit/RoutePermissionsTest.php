<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit;

use Funnelchat\PlatformGate\Exceptions\GateUnavailable;
use Funnelchat\PlatformGate\Exceptions\RouteNotAvailable;
use Funnelchat\PlatformGate\Permissions\RoutePermissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;

final class RoutePermissionsTest extends TestCase
{
    public function test_get_pide_read_y_el_resto_write(): void
    {
        $routes = new RoutePermissions('conversations');

        $this->assertSame('conversations:read', $routes->requiredFor($this->request('GET', 'api/v1/contacts')));
        $this->assertSame('conversations:write', $routes->requiredFor($this->request('POST', 'api/v1/contacts')));
        $this->assertSame('conversations:write', $routes->requiredFor($this->request('DELETE', 'api/v1/contacts/{contact}')));
    }

    public function test_una_ruta_de_envio_pide_send(): void
    {
        $routes = new RoutePermissions('conversations', send: [
            'POST api/v1/contacts/{contact}/message',
        ]);

        $this->assertSame(
            'conversations:send',
            $routes->requiredFor($this->request('POST', 'api/v1/contacts/{contact}/message'))
        );
    }

    public function test_el_metodo_forma_parte_del_patron(): void
    {
        // `GET contacts/{contact}/message` no es envío aunque el URI coincida.
        $routes = new RoutePermissions('conversations', send: [
            'POST api/v1/contacts/{contact}/message',
        ]);

        $this->assertSame(
            'conversations:read',
            $routes->requiredFor($this->request('GET', 'api/v1/contacts/{contact}/message'))
        );
    }

    public function test_el_nombre_del_placeholder_no_decide_si_el_freno_aplica(): void
    {
        // Regresión de un bug real: la ruta de envío de plantilla en conversations está
        // registrada como `{template_id}`, no `{template}`. Con matcheo literal, el patrón
        // no macheaba y la ruta caía en `write` — una key sin permiso de envío mandando
        // plantillas. El nombre del parámetro no puede decidir esto.
        $routes = new RoutePermissions('conversations', send: [
            'POST api/v1/devices/{device}/templates/{template}/send-template',
        ]);

        $this->assertSame(
            'conversations:send',
            $routes->requiredFor($this->request('POST', 'api/v1/devices/{device}/templates/{template_id}/send-template'))
        );
    }

    public function test_el_placeholder_no_se_come_un_segmento_de_mas(): void
    {
        // `{}` no puede volverse un comodín ancho: `contacts/{contact}` no es
        // `contacts/{contact}/message`.
        $routes = new RoutePermissions('conversations', denied: ['POST api/v1/contacts/{contact}']);

        $this->assertSame(
            'conversations:write',
            $routes->requiredFor($this->request('POST', 'api/v1/contacts/{contact}/message'))
        );
    }

    public function test_la_superficie_amplificada_no_la_habilita_ningun_permiso(): void
    {
        $routes = new RoutePermissions('conversations', denied: [
            'POST api/v1/contacts/add-tags',
        ]);

        $this->expectException(RouteNotAvailable::class);

        $routes->requiredFor($this->request('POST', 'api/v1/contacts/add-tags'));
    }

    public function test_denied_gana_sobre_send(): void
    {
        // Si una ruta está en las dos listas, no se envía: lo prohibido manda.
        $routes = new RoutePermissions(
            'conversations',
            send: ['POST api/v1/broadcasts/{broadcast}/execute'],
            denied: ['POST api/v1/broadcasts/{broadcast}/execute'],
        );

        $this->expectException(RouteNotAvailable::class);

        $routes->requiredFor($this->request('POST', 'api/v1/broadcasts/{broadcast}/execute'));
    }

    public function test_el_comodin_machea_por_prefijo(): void
    {
        $routes = new RoutePermissions('conversations', denied: ['api/v1/broadcasts/*']);

        $this->expectException(RouteNotAvailable::class);

        $routes->requiredFor($this->request('POST', 'api/v1/broadcasts/{broadcast}/retry-errors'));
    }

    public function test_sin_ruta_resuelta_corta_en_vez_de_adivinar(): void
    {
        // Sin ruta resuelta sólo queda el path con ids adentro, que no machea ningún
        // patrón: las listas `send` y `denied` dejarían de aplicar EN SILENCIO y la
        // superficie amplificada quedaría alcanzable con `write`.
        $routes = new RoutePermissions('conversations', denied: ['POST api/v1/contacts/add-tags']);

        $request = Request::create('/api/v1/contacts/add-tags', 'POST');

        $this->expectException(GateUnavailable::class);

        $routes->requiredFor($request);
    }

    public function test_el_patron_no_depende_de_como_se_tipeo_la_ruta(): void
    {
        $routes = new RoutePermissions('conversations', denied: ['POST api/v1/Contacts/Add-Tags']);

        $this->expectException(RouteNotAvailable::class);

        $routes->requiredFor($this->request('POST', 'api/v1/contacts/add-tags'));
    }

    private function request(string $method, string $uri): Request
    {
        $request = Request::create('/'.str_replace(['{', '}'], '', $uri), $method);
        $route = new Route([$method], $uri, static fn () => null);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }
}
