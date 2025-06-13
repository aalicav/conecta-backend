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
        Schema::create('professional_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->constrained()->onDelete('cascade');
            $table->foreignId('solicitation_id')->constrained()->onDelete('cascade');
            $table->date('available_date');
            $table->time('available_time');
            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // pending, accepted, rejected
            $table->foreignId('selected_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            // Add unique constraint to prevent duplicate availabilities
            $table->unique(['professional_id', 'solicitation_id', 'available_date', 'available_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('professional_availabilities');
    }
}; 