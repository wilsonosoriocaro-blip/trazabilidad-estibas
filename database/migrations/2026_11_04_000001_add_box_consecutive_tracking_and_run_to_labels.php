<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Production orders: tracking del consecutivo actual y corrida
        Schema::table('production_orders', function (Blueprint $table) {
            $table->unsignedInteger('last_box_sequence')->default(0);
            $table->unsignedInteger('current_box_run')->default(1);
            $table->timestampTz('last_box_labels_at')->nullable();
        });

        // 2. Box label batches: trazabilidad de rangos por lote
        Schema::table('box_label_batches', function (Blueprint $table) {
            $table->unsignedInteger('first_sequence')->default(1);
            $table->unsignedInteger('last_sequence')->default(1);
            $table->unsignedInteger('run_number')->default(1);
            $table->boolean('is_reset')->default(false);
        });

        // 3. Box labels: corrida para permitir reinicio ordenado de consecutivos sin violar unicidad
        Schema::table('box_labels', function (Blueprint $table) {
            $table->unsignedInteger('run_number')->default(1);
        });

        // Ajustar constraints de unicidad en box_labels para incluir run_number
        Schema::table('box_labels', function (Blueprint $table) {
            $table->dropUnique('box_labels_code_unique');
            $table->dropUnique('box_labels_production_order_id_lot_code_sequence_unique');

            $table->unique(['code', 'run_number'], 'box_labels_code_run_unique');
            $table->unique(['production_order_id', 'lot_code', 'run_number', 'sequence'], 'box_labels_order_lot_run_seq_unique');
        });

        // 4. Backfill de datos existentes para no romper nada
        DB::statement('
            UPDATE production_orders po
            SET last_box_sequence = COALESCE((
                SELECT MAX(bl.sequence)
                FROM box_labels bl
                WHERE bl.production_order_id = po.id
            ), 0)
        ');

        try {
            DB::statement('ALTER TABLE box_label_batches DISABLE TRIGGER box_label_batches_append_only');
            DB::statement('
                UPDATE box_label_batches b
                SET first_sequence = COALESCE((SELECT MIN(bl.sequence) FROM box_labels bl WHERE bl.box_label_batch_id = b.id), 1),
                    last_sequence = COALESCE((SELECT MAX(bl.sequence) FROM box_labels bl WHERE bl.box_label_batch_id = b.id), b.labels)
            ');
            DB::statement('ALTER TABLE box_label_batches ENABLE TRIGGER box_label_batches_append_only');
        } catch (Throwable) {
            // Respaldo silencioso si la política de la BD no permite alterar triggers
        }
    }

    public function down(): void
    {
        Schema::table('box_labels', function (Blueprint $table) {
            $table->dropUnique('box_labels_order_lot_run_seq_unique');
            $table->dropUnique('box_labels_code_run_unique');

            $table->unique(['production_order_id', 'lot_code', 'sequence'], 'box_labels_production_order_id_lot_code_sequence_unique');
            $table->unique('code', 'box_labels_code_unique');

            $table->dropColumn('run_number');
        });

        Schema::table('box_label_batches', function (Blueprint $table) {
            $table->dropColumn(['first_sequence', 'last_sequence', 'run_number', 'is_reset']);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['last_box_sequence', 'current_box_run', 'last_box_labels_at']);
        });
    }
};
