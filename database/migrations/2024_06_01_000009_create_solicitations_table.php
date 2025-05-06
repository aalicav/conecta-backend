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
        Schema::create('solicitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('patient_id')->constrained()->onDelete('cascade');
            $table->foreignId('tuss_id')->constrained('tuss_procedures')->onDelete('restrict');
            $table->foreignId('requested_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->enum('status', [
                'pending',
                'processing',
                'scheduled',
                'completed',
                'cancelled',
                'failed'
            ])->default('pending');
            
            $table->enum('priority', [
                'low',
                'normal',
                'high',
                'urgent'
            ])->default('normal');
            
            $table->text('notes')->nullable();
            $table->timestamp('preferred_date_start')->nullable();
            $table->timestamp('preferred_date_end')->nullable();
            $table->double('preferred_location_lat')->nullable();
            $table->double('preferred_location_lng')->nullable();
            $table->double('max_distance_km')->nullable();
            $table->boolean('scheduled_automatically')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('status');
            $table->index('priority');
            $table->index(['preferred_date_start', 'preferred_date_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitations');
    }
}; 