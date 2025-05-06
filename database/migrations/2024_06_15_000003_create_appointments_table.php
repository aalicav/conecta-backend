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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitation_id')->constrained()->onDelete('cascade');
            $table->morphs('provider'); // polymorphic relationship (clinic or professional)
            $table->enum('status', [
                'scheduled',
                'confirmed',
                'completed',
                'cancelled',
                'missed'
            ])->default('scheduled');
            $table->timestamp('scheduled_date');
            $table->timestamp('confirmed_date')->nullable();
            $table->timestamp('completed_date')->nullable();
            $table->timestamp('cancelled_date')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('status');
            $table->index('scheduled_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
}; 