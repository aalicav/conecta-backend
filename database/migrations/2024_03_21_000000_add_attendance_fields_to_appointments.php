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
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('patient_attended')->nullable();
            $table->timestamp('attendance_confirmed_at')->nullable();
            $table->unsignedBigInteger('attendance_confirmed_by')->nullable();
            $table->text('attendance_notes')->nullable();
            $table->boolean('eligible_for_billing')->default(false);
            $table->unsignedBigInteger('billing_batch_id')->nullable();
            
            $table->foreign('attendance_confirmed_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
                
            $table->foreign('billing_batch_id')
                ->references('id')
                ->on('billing_batches')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['attendance_confirmed_by']);
            $table->dropForeign(['billing_batch_id']);
            $table->dropColumn([
                'patient_attended',
                'attendance_confirmed_at',
                'attendance_confirmed_by',
                'attendance_notes',
                'eligible_for_billing',
                'billing_batch_id'
            ]);
        });
    }
};