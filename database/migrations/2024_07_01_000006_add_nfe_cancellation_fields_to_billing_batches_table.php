<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_batches', function (Blueprint $table) {
            $table->timestamp('nfe_cancellation_date')->nullable();
            $table->text('nfe_cancellation_reason')->nullable();
            $table->string('nfe_cancellation_protocol')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_batches', function (Blueprint $table) {
            $table->dropColumn([
                'nfe_cancellation_date',
                'nfe_cancellation_reason',
                'nfe_cancellation_protocol'
            ]);
        });
    }
}; 