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
        Schema::create('billing_batches', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type'); // health_plan, clinic, etc.
            $table->unsignedBigInteger('entity_id');
            $table->date('reference_period_start');
            $table->date('reference_period_end');
            $table->date('billing_date');
            $table->date('due_date');
            $table->decimal('total_amount', 10, 2);
            $table->string('status')->default('pending'); // pending, paid, overdue, glosa, renegotiated
            $table->integer('items_count')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id']);
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_batch_id');
            $table->unsignedBigInteger('appointment_id');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('billing_batch_id')->references('id')->on('billing_batches');
            $table->foreign('appointment_id')->references('id')->on('appointments');
        });

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
        Schema::dropIfExists('billing_items');
        Schema::dropIfExists('billing_batches');
    }
}; 