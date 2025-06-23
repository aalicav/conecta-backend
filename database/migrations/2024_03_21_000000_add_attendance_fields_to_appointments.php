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
        Schema::table('appointments', function (Blueprint $table) {
            // Only add fields that don't already exist
            if (!Schema::hasColumn('appointments', 'attendance_confirmed_at')) {
                $table->timestamp('attendance_confirmed_at')->nullable();
            }
            
            if (!Schema::hasColumn('appointments', 'attendance_confirmed_by')) {
                $table->unsignedBigInteger('attendance_confirmed_by')->nullable();
            }
            
            if (!Schema::hasColumn('appointments', 'attendance_notes')) {
                $table->text('attendance_notes')->nullable();
            }
            
            if (!Schema::hasColumn('appointments', 'billing_batch_id')) {
                $table->unsignedBigInteger('billing_batch_id')->nullable();
            }
            
            // Add foreign key constraints only if columns were added
            if (!Schema::hasColumn('appointments', 'attendance_confirmed_by')) {
                $table->foreign('attendance_confirmed_by')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            }
                
            if (!Schema::hasColumn('appointments', 'billing_batch_id')) {
                $table->foreign('billing_batch_id')
                    ->references('id')
                    ->on('billing_batches')
                    ->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Drop foreign keys first
            if (Schema::hasColumn('appointments', 'attendance_confirmed_by')) {
                $table->dropForeign(['attendance_confirmed_by']);
            }
            
            if (Schema::hasColumn('appointments', 'billing_batch_id')) {
                $table->dropForeign(['billing_batch_id']);
            }
            
            // Drop columns
            $columnsToDrop = [];
            
            if (Schema::hasColumn('appointments', 'attendance_confirmed_at')) {
                $columnsToDrop[] = 'attendance_confirmed_at';
            }
            
            if (Schema::hasColumn('appointments', 'attendance_confirmed_by')) {
                $columnsToDrop[] = 'attendance_confirmed_by';
            }
            
            if (Schema::hasColumn('appointments', 'attendance_notes')) {
                $columnsToDrop[] = 'attendance_notes';
            }
            
            if (Schema::hasColumn('appointments', 'billing_batch_id')) {
                $columnsToDrop[] = 'billing_batch_id';
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};