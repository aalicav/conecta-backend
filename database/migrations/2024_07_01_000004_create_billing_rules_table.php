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
        // Check if table exists and add missing columns
        if (Schema::hasTable('billing_rules')) {
            Schema::table('billing_rules', function (Blueprint $table) {
                // Add missing columns if they don't exist
                if (!Schema::hasColumn('billing_rules', 'name')) {
                    $table->string('name')->after('id');
                }
                if (!Schema::hasColumn('billing_rules', 'description')) {
                    $table->text('description')->nullable()->after('name');
                }
                if (!Schema::hasColumn('billing_rules', 'entity_type')) {
                    $table->string('entity_type')->after('description');
                }
                if (!Schema::hasColumn('billing_rules', 'entity_id')) {
                    $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
                }
                if (!Schema::hasColumn('billing_rules', 'rule_type')) {
                    $table->string('rule_type')->after('entity_id');
                }
                if (!Schema::hasColumn('billing_rules', 'billing_cycle')) {
                    $table->string('billing_cycle')->nullable()->after('rule_type');
                }
                if (!Schema::hasColumn('billing_rules', 'payment_term_days')) {
                    $table->unsignedInteger('payment_term_days')->nullable()->after('billing_day');
                }
                if (!Schema::hasColumn('billing_rules', 'invoice_generation_days_before')) {
                    $table->unsignedInteger('invoice_generation_days_before')->nullable()->after('payment_term_days');
                }
                if (!Schema::hasColumn('billing_rules', 'payment_method')) {
                    $table->string('payment_method')->nullable()->after('invoice_generation_days_before');
                }
                if (!Schema::hasColumn('billing_rules', 'conditions')) {
                    $table->json('conditions')->nullable()->after('payment_method');
                }
                if (!Schema::hasColumn('billing_rules', 'discounts')) {
                    $table->json('discounts')->nullable()->after('conditions');
                }
                if (!Schema::hasColumn('billing_rules', 'tax_rules')) {
                    $table->json('tax_rules')->nullable()->after('discounts');
                }
                if (!Schema::hasColumn('billing_rules', 'priority')) {
                    $table->unsignedInteger('priority')->default(0)->after('is_active');
                }
                if (!Schema::hasColumn('billing_rules', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->after('priority');
                }
            });
            
            // Add indexes if they don't exist
            Schema::table('billing_rules', function (Blueprint $table) {
                if (!Schema::hasIndex('billing_rules', 'billing_rules_entity_type_entity_id_index')) {
                    $table->index(['entity_type', 'entity_id']);
                }
                if (!Schema::hasIndex('billing_rules', 'billing_rules_is_active_index')) {
                    $table->index('is_active');
                }
                if (!Schema::hasIndex('billing_rules', 'billing_rules_rule_type_index')) {
                    $table->index('rule_type');
                }
            });
        } else {
            // Create table if it doesn't exist
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
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remove added columns if they exist
        Schema::table('billing_rules', function (Blueprint $table) {
            $columnsToDrop = [
                'name', 'description', 'entity_type', 'entity_id', 'rule_type',
                'billing_cycle', 'payment_term_days', 'invoice_generation_days_before',
                'payment_method', 'conditions', 'discounts', 'tax_rules', 'priority', 'created_by'
            ];
            
            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('billing_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
} 