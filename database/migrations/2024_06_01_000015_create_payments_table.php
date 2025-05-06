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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference_id')->unique();
            $table->unsignedBigInteger('payable_id');
            $table->string('payable_type');
            $table->string('payment_type')->comment('appointment, contract, subscription');
            $table->decimal('amount', 10, 2);
            $table->decimal('total_amount', 10, 2)->comment('Amount after taxes, fees, and discounts');
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('gloss_amount', 10, 2)->default(0)->comment('Amount rejected in audit/gloss');
            $table->string('status')->default('pending')->comment('pending, processing, completed, failed, refunded, partially_refunded');
            $table->string('payment_method')->nullable()->comment('credit_card, debit_card, bank_transfer, pix, cash');
            $table->string('payment_gateway')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('processed_by')->references('id')->on('users');
            $table->index(['payable_id', 'payable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
}; 