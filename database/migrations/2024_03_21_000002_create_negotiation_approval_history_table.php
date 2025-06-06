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
        Schema::create('negotiation_approval_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negotiation_id')->constrained()->onDelete('cascade');
            $table->string('action'); // submit_for_approval, approve, reject
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('negotiation_approval_history');
    }
}; 