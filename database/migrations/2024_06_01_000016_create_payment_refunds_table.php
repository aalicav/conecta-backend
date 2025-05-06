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
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending')->comment('pending, processing, completed, failed');
            $table->string('reason');
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_response')->nullable();
            $table->unsignedBigInteger('refunded_by');
            $table->timestamp('refunded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreign('refunded_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
}; 