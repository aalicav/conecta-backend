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
        Schema::create('payment_glosses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->decimal('amount', 10, 2);
            $table->string('reason');
            $table->string('gloss_code')->nullable()->comment('An optional standardized code for the gloss reason');
            $table->string('status')->default('applied')->comment('applied, appealed, reverted');
            $table->boolean('is_appealable')->default(true);
            $table->unsignedBigInteger('applied_by');
            $table->unsignedBigInteger('reverted_by')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreign('applied_by')->references('id')->on('users');
            $table->foreign('reverted_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_glosses');
    }
}; 