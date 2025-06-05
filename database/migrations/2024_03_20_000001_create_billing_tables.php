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
        // Create fiscal_documents table
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_batch_id');
            $table->string('number');
            $table->date('issue_date');
            $table->string('file_path');
            $table->string('file_type');
            $table->integer('file_size');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('billing_batch_id')->references('id')->on('billing_batches');
        });

        // Create payment_proofs table
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_batch_id');
            $table->date('date');
            $table->decimal('amount', 10, 2);
            $table->string('file_path');
            $table->string('file_type');
            $table->integer('file_size');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('billing_batch_id')->references('id')->on('billing_batches');
        });

        // Create payment_glosas table
        Schema::create('payment_glosas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_item_id');
            $table->decimal('amount', 10, 2);
            $table->text('reason');
            $table->string('status')->default('pending'); // pending, accepted, rejected
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('billing_item_id')->references('id')->on('billing_items');
            $table->foreign('resolved_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_glosas');
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('fiscal_documents');
    }
}; 