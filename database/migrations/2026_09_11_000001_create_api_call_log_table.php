<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de uso del piloto: una fila por llamada (D12, pedido P6).
 *
 * Las reglas de esta tabla son las de su hermana `feature_usage_events`, a propósito,
 * para que unificarlas mañana sea un UNION y no arqueología (D20):
 *
 *  - Append-only de verdad: nunca UPDATE, nunca DELETE, sin purga. No hay `updated_at`.
 *  - `occurred_at` es `dateTime` y NO `timestamp`. Un `timestamp` en la primera columna
 *    NOT NULL puede recibir el auto-update implícito de MySQL y romper la inmutabilidad
 *    justo en la columna que la define.
 *  - Sin foreign keys: la fila sobrevive al borrado de lo que la generó.
 *  - Sin PII y sin contenido: ni teléfonos, ni cuerpos, ni texto escrito por personas.
 *    `route` es el URI definido (`contacts/{contact}/messages`), no el path real.
 *
 * Nadie la lee desde el código. Se consulta por SQL.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('platform-gate.usage_connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create(
            config('platform-gate.usage_table', 'api_call_log'),
            function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('key_id', 32);
                $table->string('route', 255);
                $table->string('method', 10);
                $table->unsignedSmallInteger('status_code');
                $table->dateTime('occurred_at');
                $table->unsignedInteger('duration_ms');

                // Las dos consultas que el piloto va a hacer por SQL: "qué usó esta key"
                // y "quién usó esta ruta". Nada más: los índices también cuestan escritura.
                $table->index(['key_id', 'occurred_at'], 'api_call_log_key_occurred_idx');
                $table->index(['route', 'occurred_at'], 'api_call_log_route_occurred_idx');
            }
        );
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())
            ->dropIfExists(config('platform-gate.usage_table', 'api_call_log'));
    }
};
