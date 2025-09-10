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
        Schema::create('appointment_reschedulings', function (Blueprint $table) {
            $table->id();
            $table->string('rescheduling_number')->unique();
            $table->foreignId('original_appointment_id')->constrained('appointments')->onDelete('cascade');
            $table->foreignId('new_appointment_id')->constrained('appointments')->onDelete('cascade');
            $table->string('reason'); // payment_not_released, doctor_absent, patient_request, clinic_request, other
            $table->text('reason_description');
            $table->string('status')->default('pending'); // pending, approved, rejected, completed
            $table->datetime('original_scheduled_date');
            $table->datetime('new_scheduled_date');
            $table->foreignId('original_provider_type_id')->nullable();
            $table->string('original_provider_type')->nullable();
            $table->foreignId('new_provider_type_id')->nullable();
            $table->string('new_provider_type')->nullable();
            $table->boolean('provider_changed')->default(false);
            $table->boolean('financial_impact')->default(false);
            $table->decimal('original_amount', 10, 2)->nullable();
            $table->decimal('new_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('whatsapp_sent')->default(false);
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_reschedulings');
    }
};
