<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBillingBatchesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('billing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_rule_id')->constrained()->onDelete('restrict');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->date('reference_period_start');
            $table->date('reference_period_end');
            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('fees_amount', 10, 2)->default(0);
            $table->decimal('taxes_amount', 10, 2)->default(0);
            $table->decimal('net_amount', 10, 2)->default(0);
            $table->date('billing_date');
            $table->date('due_date');
            $table->string('status')->default('pending');
            $table->string('invoice_number')->nullable();
            $table->string('invoice_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->text('processing_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['entity_type', 'entity_id']);
            $table->index('status');
            $table->index('billing_date');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('billing_batches');
    }
} 