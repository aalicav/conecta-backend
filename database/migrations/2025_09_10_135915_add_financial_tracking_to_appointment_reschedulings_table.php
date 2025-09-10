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
        Schema::table('appointment_reschedulings', function (Blueprint $table) {
            $table->decimal('difference_amount', 10, 2)->nullable()->after('new_amount');
            $table->boolean('billing_reversed')->default(false)->after('difference_amount');
            $table->boolean('new_billing_created')->default(false)->after('billing_reversed');
            $table->foreignId('original_billing_item_id')->nullable()->constrained('billing_items')->onDelete('set null')->after('new_billing_created');
            $table->foreignId('new_billing_item_id')->nullable()->constrained('billing_items')->onDelete('set null')->after('original_billing_item_id');
            $table->timestamp('billing_reversed_at')->nullable()->after('new_billing_item_id');
            $table->timestamp('new_billing_created_at')->nullable()->after('billing_reversed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_reschedulings', function (Blueprint $table) {
            $table->dropColumn([
                'difference_amount',
                'billing_reversed',
                'new_billing_created',
                'original_billing_item_id',
                'new_billing_item_id',
                'billing_reversed_at',
                'new_billing_created_at'
            ]);
        });
    }
};