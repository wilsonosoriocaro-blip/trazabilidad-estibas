<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every batch of box labels sent to the Zebra. Once a lot has a print,
     * its labels exist on paper and the lot can no longer be changed.
     */
    public function up(): void
    {
        Schema::create('box_label_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_label_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->string('lot_code', 50);
            $table->unsignedInteger('labels');
            $table->string('printer', 100);
            $table->foreignId('printed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('printed_at');

            $table->index(['production_order_id', 'lot_code']);
        });

        DB::unprepared('CREATE TRIGGER box_label_prints_append_only BEFORE UPDATE OR DELETE ON box_label_prints FOR EACH ROW EXECUTE FUNCTION forbid_update_delete()');
    }

    public function down(): void
    {
        Schema::dropIfExists('box_label_prints');
    }
};
