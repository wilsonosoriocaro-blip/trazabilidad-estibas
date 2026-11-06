<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('box_label_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_label_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->string('lot_code', 50);
            $table->unsignedInteger('total_labels');
            $table->unsignedInteger('printed_labels')->default(0);
            $table->unsignedInteger('pending_labels');
            $table->unsignedInteger('start_sequence');
            $table->unsignedInteger('end_sequence');
            $table->unsignedInteger('last_printed_sequence')->default(0);
            $table->unsignedInteger('next_sequence');
            $table->string('status', 30)->default('pending'); // pending, printing, paused_error, completed, cancelled
            $table->string('error_reason', 255)->nullable();
            $table->string('printer_ip', 50)->default('192.168.1.155');
            $table->unsignedInteger('printer_port')->default(9100);
            $table->unsignedInteger('chunk_size')->default(50);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['box_label_batch_id', 'status']);
            $table->index(['production_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_label_print_jobs');
    }
};
