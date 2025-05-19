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
        Schema::table('contracts', function (Blueprint $table) {
            // Alert fields
            $table->unsignedInteger('alert_days_before_expiration')->default(90)->after('end_date');
            $table->timestamp('last_alert_sent_at')->nullable()->after('alert_days_before_expiration');
            $table->unsignedInteger('alert_count')->default(0)->after('last_alert_sent_at');
            
            // Billing configuration fields
            $table->string('billing_frequency')->default('monthly')->after('alert_count');
            $table->unsignedInteger('payment_term_days')->default(30)->after('billing_frequency');
            $table->foreignId('billing_rule_id')->nullable()->after('payment_term_days')
                ->constrained('billing_rules')->onDelete('set null');
            
            // Add indexes
            $table->index('alert_days_before_expiration');
            $table->index('last_alert_sent_at');
            $table->index('billing_frequency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Remove indexes
            $table->dropIndex(['alert_days_before_expiration']);
            $table->dropIndex(['last_alert_sent_at']);
            $table->dropIndex(['billing_frequency']);
            
            // Remove foreign key constraint
            $table->dropForeign(['billing_rule_id']);
            
            // Remove columns
            $table->dropColumn([
                'alert_days_before_expiration',
                'last_alert_sent_at',
                'alert_count',
                'billing_frequency',
                'payment_term_days',
                'billing_rule_id'
            ]);
        });
    }
}; 