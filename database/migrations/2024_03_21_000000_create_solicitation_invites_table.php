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
        Schema::create('solicitation_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitation_id')->constrained()->onDelete('cascade');
            $table->string('provider_type'); // 'professional' or 'clinic'
            $table->unsignedBigInteger('provider_id');
            $table->string('status')->default('pending'); // pending, accepted, rejected
            $table->timestamp('responded_at')->nullable();
            $table->text('response_notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['provider_type', 'provider_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitation_invites');
    }
}; 