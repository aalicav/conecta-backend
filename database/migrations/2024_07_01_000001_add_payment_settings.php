<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert payment settings
        DB::table('system_settings')->insert([
            [
                'key' => 'payment_on_schedule',
                'value' => 'false',
                'group' => 'payment',
                'description' => 'Create a pending payment when an appointment is scheduled',
                'is_public' => false,
                'data_type' => 'boolean',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'payment_reminder_days',
                'value' => '3',
                'group' => 'payment',
                'description' => 'Days before payment due date to send a reminder',
                'is_public' => false,
                'data_type' => 'integer',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'payment_grace_period',
                'value' => '5',
                'group' => 'payment',
                'description' => 'Grace period in days after payment due date',
                'is_public' => false,
                'data_type' => 'integer',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'payment_methods',
                'value' => '["credit_card","bank_transfer","boleto"]',
                'group' => 'payment',
                'description' => 'Available payment methods',
                'is_public' => true,
                'data_type' => 'array',
                'created_at' => now(),
                'updated_at' => now()
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', [
                'payment_on_schedule',
                'payment_reminder_days',
                'payment_grace_period',
                'payment_methods'
            ])
            ->delete();
    }
}; 