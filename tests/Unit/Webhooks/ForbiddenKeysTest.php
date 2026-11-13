<?php

declare(strict_types=1);

namespace Funnelchat\PlatformGate\Tests\Unit\Webhooks;

use Funnelchat\PlatformGate\Webhooks\Event;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las claves prohibidas no se pueden saltear (0.11.0). Hasta 0.10.0 se recorrían sólo los arrays
 * y se comparaba el nombre exacto, así que `userId` o un objeto con `user_id` adentro llegaban al
 * cliente. Los casos de siempre siguen en `EnvelopeTest`.
 */
final class ForbiddenKeysTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function disguised(): iterable
    {
        yield 'camelCase' => [['userId' => 7]];
        yield 'camelCase, otra' => [['contact' => ['accountId' => 7]]];
        yield 'PascalCase' => [['OwnerId' => 7]];
        yield 'con guion' => [['tokenable-id' => 7]];
        yield 'con espacio y mayúsculas' => [['USER ID' => 7]];
        yield 'todo junto' => [['userid' => 7]];
        yield 'en un stdClass' => [['contact' => (object) ['name' => 'Edu', 'user_id' => 7]]];
        yield 'en un stdClass dentro de una lista' => [['contacts' => [(object) ['name' => 'Ana'], (object) ['ownerId' => 7]]]];
        yield 'en un JsonSerializable' => [['contact' => new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['name' => 'Edu', 'account_id' => 7];
            }
        }]];
        yield 'en una propiedad pública de un objeto' => [['contact' => new class {
            public string $name = 'Edu';

            public int $owner_id = 7;
        }]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('disguised')]
    public function test_escrita_de_otra_forma_o_dentro_de_un_objeto_tambien_tira(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no puede llevar');

        Event::make('message.received', $data);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function allowed(): iterable
    {
        // La lista es un piso: los cuatro nombres, escritos como sea. No se agranda a otros.
        yield 'workspace_id' => [['workspace_id' => 7, 'contact' => (object) ['workspaceId' => 7]]];
        yield 'otro id' => [['contact_id' => 104816, 'contactId' => 104816]];
        yield 'un nombre que sólo empieza igual' => [['user_identifier' => 'opaco']];
        yield 'el valor no importa, sólo la clave' => [['text' => 'user_id']];
        yield 'un objeto vacío' => [['meta' => (object) []]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('allowed')]
    public function test_lo_que_no_es_uno_de_los_cuatro_pasa_intacto(array $data): void
    {
        $this->assertSame($data, Event::make('message.received', $data)->data);
    }

    public function test_un_texto_que_no_es_utf8_no_es_una_fuga(): void
    {
        // Lo que no se puede serializar no se rechaza por eso: se busca en lo que sí.
        $data = ['text' => "\xB1\x31"];

        $this->assertSame($data, Event::make('message.received', $data)->data);

        $this->expectException(InvalidArgumentException::class);
        Event::make('message.received', ['text' => "\xB1\x31", 'contact' => (object) ['userId' => 7]]);
    }
}
