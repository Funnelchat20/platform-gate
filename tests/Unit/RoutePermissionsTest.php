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
    /**
     * La superficie abierta, declarada a propósito. Los tests que verifican CÓMO se
     * clasifica una ruta la usan para que `allowed` no sea lo que decide el resultado.
     */
    private const EVERYTHING = ['*'];

    public function test_get_pide_read_y_el_resto_write(): void
    {
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING);

        $this->assertSame('conversations:read', $routes->requiredFor($this->request('GET', 'api/v1/contacts')));
        $this->assertSame('conversations:write', $routes->requiredFor($this->request('POST', 'api/v1/contacts')));
        $this->assertSame('conversations:write', $routes->requiredFor($this->request('DELETE', 'api/v1/contacts/{contact}')));
    }

    public function test_una_ruta_de_envio_pide_send(): void
    {
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, send: [
            'POST api/v1/contacts/{contact}/message',
        ]);

        $this->assertSame(
            'conversations:send',
            $routes->requiredFor($this->request('POST', 'api/v1/contacts/{contact}/message'))
        );
    }

    public function test_una_ruta_de_operacion_irreversible_pide_operate(): void
    {
        $routes = new RoutePermissions('accounts', allowed: self::EVERYTHING, operate: [
            'PUT api/v1/devices/{device}/reset',
            'POST api/v1/devices/{device}/clean',
            'DELETE api/v1/devices/{device}/message-queue/all',
        ]);

        $this->assertSame('accounts:operate', $routes->requiredFor($this->request('PUT', 'api/v1/devices/{device}/reset')));
        $this->assertSame('accounts:operate', $routes->requiredFor($this->request('POST', 'api/v1/devices/{device}/clean')));
        $this->assertSame('accounts:operate', $routes->requiredFor($this->request('DELETE', 'api/v1/devices/{device}/message-queue/all')));
    }

    public function test_operate_no_se_lleva_puestas_las_demas_mutaciones(): void
    {
        // El riesgo de esta lista es al revés que el de `denied`: si machea de más,
        // rutas comunes de escritura empiezan a pedir un permiso que casi nadie tiene.
        $routes = new RoutePermissions('accounts', allowed: self::EVERYTHING, operate: [
            'POST api/v1/devices/{device}/clean',
        ]);

        $this->assertSame('accounts:write', $routes->requiredFor($this->request('PUT', 'api/v1/devices/{device}')));
        $this->assertSame('accounts:read', $routes->requiredFor($this->request('GET', 'api/v1/devices/{device}')));
    }

    public function test_denied_gana_sobre_operate(): void
    {
        // Mismo orden que con `send`: lo que ningún permiso habilita no puede
        // volverse alcanzable por figurar además en otra lista.
        $routes = new RoutePermissions(
            'accounts',
            operate: ['POST api/v1/devices/{device}/clean'],
            denied: ['POST api/v1/devices/{device}/clean'],
            allowed: self::EVERYTHING,
        );

        $this->expectException(\Funnelchat\PlatformGate\Exceptions\RouteNotAvailable::class);
        $routes->requiredFor($this->request('POST', 'api/v1/devices/{device}/clean'));
    }

    public function test_allowed_invierte_el_default(): void
    {
        $routes = new RoutePermissions('accounts', allowed: [
            'api/v1/devices*',
            'api/v1/alerts*',
        ]);

        $this->assertSame('accounts:read', $routes->requiredFor($this->request('GET', 'api/v1/devices')));
        $this->assertSame('accounts:write', $routes->requiredFor($this->request('POST', 'api/v1/alerts/destinations')));
    }

    public function test_lo_no_declarado_en_allowed_no_existe(): void
    {
        // El punto de esta lista: una ruta nueva nace denegada. Con el default abierto,
        // cualquier ruta que alguien agregue queda alcanzable el día que se mergea.
        $routes = new RoutePermissions('accounts', allowed: ['api/v1/devices*']);

        $this->expectException(\Funnelchat\PlatformGate\Exceptions\RouteNotAvailable::class);
        $routes->requiredFor($this->request('POST', 'api/v1/me/billing/checkout'));
    }

    public function test_sin_allowed_nada_es_alcanzable_para_una_key(): void
    {
        // Una lista vacía no es "sin restricción": es "nada declarado". Una superficie que
        // nadie declaró no existe para una key, sea cual sea el método.
        $routes = new RoutePermissions('accounts', send: ['POST api/v1/messages'], operate: ['POST api/v1/devices/{device}/clean']);

        foreach ([['GET', 'api/v1/cualquier-cosa'], ['POST', 'api/v1/contacts'], ['POST', 'api/v1/messages'], ['POST', 'api/v1/devices/{device}/clean']] as [$method, $uri]) {
            try {
                $routes->requiredFor($this->request($method, $uri));
                $this->fail("{$method} {$uri} no debería ser alcanzable sin `allowed`.");
            } catch (RouteNotAvailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_comodin_en_allowed_abre_toda_la_superficie_y_hay_que_escribirlo(): void
    {
        // La superficie abierta sigue existiendo, pero es una decisión escrita en la
        // config del dominio, no lo que pasa cuando nadie escribió nada.
        $routes = new RoutePermissions('accounts', allowed: ['*']);

        $this->assertSame('accounts:read', $routes->requiredFor($this->request('GET', 'api/v1/cualquier-cosa')));
        $this->assertSame('accounts:write', $routes->requiredFor($this->request('DELETE', 'api/v1/cualquier-cosa/{id}')));
    }

    public function test_denied_gana_sobre_allowed(): void
    {
        $routes = new RoutePermissions(
            'accounts',
            denied: ['POST api/v1/devices/{device}/execute'],
            allowed: ['api/v1/devices*'],
        );

        $this->expectException(\Funnelchat\PlatformGate\Exceptions\RouteNotAvailable::class);
        $routes->requiredFor($this->request('POST', 'api/v1/devices/{device}/execute'));
    }

    public function test_el_metodo_forma_parte_del_patron(): void
    {
        // `GET contacts/{contact}/message` no es envío aunque el URI coincida.
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, send: [
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
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, send: [
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
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, denied: ['POST api/v1/contacts/{contact}']);

        $this->assertSame(
            'conversations:write',
            $routes->requiredFor($this->request('POST', 'api/v1/contacts/{contact}/message'))
        );
    }

    public function test_la_superficie_amplificada_no_la_habilita_ningun_permiso(): void
    {
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, denied: [
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
            allowed: self::EVERYTHING,
        );

        $this->expectException(RouteNotAvailable::class);

        $routes->requiredFor($this->request('POST', 'api/v1/broadcasts/{broadcast}/execute'));
    }

    public function test_el_comodin_machea_por_prefijo(): void
    {
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, denied: ['api/v1/broadcasts/*']);

        $this->expectException(RouteNotAvailable::class);

        $routes->requiredFor($this->request('POST', 'api/v1/broadcasts/{broadcast}/retry-errors'));
    }

    public function test_sin_ruta_resuelta_corta_en_vez_de_adivinar(): void
    {
        // Sin ruta resuelta sólo queda el path con ids adentro, que no machea ningún
        // patrón: las listas `send` y `denied` dejarían de aplicar EN SILENCIO y la
        // superficie amplificada quedaría alcanzable con `write`.
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, denied: ['POST api/v1/contacts/add-tags']);

        $request = Request::create('/api/v1/contacts/add-tags', 'POST');

        $this->expectException(GateUnavailable::class);

        $routes->requiredFor($request);
    }

    public function test_el_patron_no_depende_de_como_se_tipeo_la_ruta(): void
    {
        $routes = new RoutePermissions('conversations', allowed: self::EVERYTHING, denied: ['POST api/v1/Contacts/Add-Tags']);

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
