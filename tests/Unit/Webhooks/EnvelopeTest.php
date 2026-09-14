<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit\Webhooks;

use DateTimeImmutable;
use Funnelchat\PlatformGate\Webhooks\Event;
use Funnelchat\PlatformGate\Webhooks\EventName;
use Funnelchat\PlatformGate\Webhooks\RetryPolicy;
use Funnelchat\PlatformGate\Webhooks\Signature;
use Funnelchat\PlatformGate\Webhooks\Subscription;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function test_la_convencion_de_nombres_se_hace_cumplir(): void
    {
        $this->assertSame('message.received', EventName::from('message.received')->value);
        $this->assertSame('group.participant_joined', EventName::from('group.participant_joined')->value);

        // Lo que este test existe para impedir: que un dominio mande CamelCase y el
        // usuario de n8n termine aprendiendo dos convenciones para una sola API.
        $this->expectException(InvalidArgumentException::class);
        EventName::from('GroupParticipantJoined');
    }

    public function test_el_payload_no_puede_llevar_identificadores_de_cuenta(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Event::make('message.received', ['user_id' => 4821, 'text' => 'hola']);
    }

    public function test_tampoco_anidados(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Event::make('message.received', ['contact' => ['name' => 'Edu', 'owner_id' => 1]]);
    }

    public function test_el_sobre_tiene_siempre_la_misma_forma(): void
    {
        $event = Event::make(
            'message.received',
            ['contact_id' => 104816, 'text' => 'hola'],
            new DateTimeImmutable('2026-09-11T17:48:29+00:00'),
        );

        $array = $event->toArray();

        $this->assertSame(['id', 'type', 'version', 'occurred_at', 'data'], array_keys($array));
        $this->assertStringStartsWith('evt_', $array['id']);
        $this->assertSame('message.received', $array['type']);
        $this->assertSame(1, $array['version']);
        $this->assertSame('2026-09-11T17:48:29Z', $array['occurred_at']);
    }

    public function test_la_firma_va_y_vuelve(): void
    {
        $signature = new Signature('shhh');
        $payload = '{"id":"evt_1"}';

        $header = $signature->for($payload, 1789131830);

        $this->assertTrue($signature->verify($payload, $header, now: 1789131830));
    }

    public function test_una_entrega_vieja_reenviada_no_vale(): void
    {
        // La razón por la que el timestamp entra en lo firmado: sin eso, una entrega
        // capturada se puede reenviar para siempre con la firma intacta.
        $signature = new Signature('shhh');
        $payload = '{"id":"evt_1"}';

        $header = $signature->for($payload, 1789131830);

        $this->assertFalse($signature->verify($payload, $header, now: 1789131830 + 3600));
    }

    public function test_un_cuerpo_cambiado_invalida_la_firma(): void
    {
        $signature = new Signature('shhh');
        $header = $signature->for('{"amount":10}', 1789131830);

        $this->assertFalse($signature->verify('{"amount":9999}', $header, now: 1789131830));
    }

    public function test_una_firma_con_otro_secreto_no_vale(): void
    {
        $header = (new Signature('otro'))->for('{"id":"evt_1"}', 1789131830);

        $this->assertFalse((new Signature('shhh'))->verify('{"id":"evt_1"}', $header, now: 1789131830));
    }

    public function test_un_header_roto_no_explota_y_no_pasa(): void
    {
        $signature = new Signature('shhh');

        $this->assertFalse($signature->verify('{}', ''));
        $this->assertFalse($signature->verify('{}', 't=,v1='));
        $this->assertFalse($signature->verify('{}', 'v1=abc'));
    }

    public function test_el_cliente_recibe_solo_lo_que_pidio(): void
    {
        $sub = new Subscription('https://n8n.example/hook', 'shhh', ['message.received', 'broadcast.*']);

        $this->assertTrue($sub->wants(EventName::from('message.received')));
        $this->assertTrue($sub->wants(EventName::from('broadcast.finished')));
        $this->assertFalse($sub->wants(EventName::from('message.status_changed')));
        $this->assertFalse($sub->wants(EventName::from('flow.completed')));
    }

    public function test_no_existe_suscribirse_a_todo_con_un_asterisco(): void
    {
        $sub = new Subscription('https://n8n.example/hook', 'shhh', ['*']);

        $this->assertFalse($sub->wants(EventName::from('message.received')));
    }

    public function test_se_reintenta_lo_que_puede_mejorar_solo(): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue($policy->shouldRetry(1, 500));
        $this->assertTrue($policy->shouldRetry(1, 429));
        $this->assertTrue($policy->shouldRetry(1, null));   // timeout / DNS
        $this->assertFalse($policy->shouldRetry(1, 400));   // el cliente dice "está mal"
        $this->assertFalse($policy->shouldRetry(1, 404));
        $this->assertFalse($policy->shouldRetry(4, 500));   // se acabaron los intentos
    }

    public function test_la_espera_crece_y_despues_se_abandona(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(60, $policy->delayAfter(1));
        $this->assertSame(300, $policy->delayAfter(2));
        $this->assertSame(1800, $policy->delayAfter(3));
        $this->assertNull($policy->delayAfter(4));
    }
}
