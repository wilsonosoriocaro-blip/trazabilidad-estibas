<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('box_label_print_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('start_odometer')->nullable()->after('chunk_size');
            $table->unsignedBigInteger('last_odometer')->nullable()->after('start_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('box_label_print_jobs', function (Blueprint $table) {
            $table->dropColumn(['start_odometer', 'last_odometer']);
        });
    }
};
