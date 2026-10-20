<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tres columnas más en el registro de uso (0.8, contrato 1019-registro-llamadas, L1). D12 pasa
 * de seis campos a nueve: el registro no servía para rastrear un problema, porque no ligaba la
 * llamada con lo que creó ni tenía un id que el integrador pudiera citar.
 *
 *  - `request_id`: el id del pedido, el mismo de `X-Request-Id`. Con índice: es la consulta de
 *    soporte, "esta llamada que me cita el integrador".
 *  - `resource_type` / `resource_id`: lo que el controlador declaró (`CreatedResource`). Un id
 *    interno, nunca un teléfono ni un texto.
 *
 * Las tres nullable: las filas de antes quedan como están, y una llamada que no declara
 * recurso deja esas dos en `null`.
 *
 * Lo que dice de la tabla la migración que la crea (`2026_09_11_000001`, que no se edita) sigue
 * valiendo, salvo una cosa: ya no es "sin purga". Desde 0.8 la purga el comando `platform-gate:purge-usage` (90 días por defecto). Nunca
 * se actualiza una fila.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('platform-gate.usage_connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table(
            config('platform-gate.usage_table', 'api_call_log'),
            function (Blueprint $table): void {
                $table->char('request_id', 36)->nullable();
                $table->string('resource_type', 32)->nullable();
                $table->string('resource_id', 64)->nullable();

                $table->index('request_id', 'api_call_log_request_id_idx');
            }
        );
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table(
            config('platform-gate.usage_table', 'api_call_log'),
            function (Blueprint $table): void {
                $table->dropIndex('api_call_log_request_id_idx');
                $table->dropColumn(['request_id', 'resource_type', 'resource_id']);
            }
        );
    }
};
