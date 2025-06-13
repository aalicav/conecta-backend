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
            $table->foreignId('professional_id')->constrained('professionals')->onDelete('cascade');
            $table->foreignId('solicitation_id')->constrained('solicitations')->onDelete('cascade');
            $table->date('available_date');
            $table->time('available_time');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->foreignId('selected_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            // Using a shorter name for the unique constraint
            $table->unique(
                ['professional_id', 'solicitation_id', 'available_date', 'available_time'],
                'prof_avail_unique'
            );
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