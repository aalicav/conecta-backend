<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBillingRulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('billing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('rule_type');
            $table->string('billing_cycle')->nullable();
            $table->unsignedTinyInteger('billing_day')->nullable();
            $table->unsignedInteger('payment_term_days')->nullable();
            $table->unsignedInteger('invoice_generation_days_before')->nullable();
            $table->string('payment_method')->nullable();
            $table->json('conditions')->nullable();
            $table->json('discounts')->nullable();
            $table->json('tax_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['entity_type', 'entity_id']);
            $table->index('is_active');
            $table->index('rule_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('billing_rules');
    }
} 