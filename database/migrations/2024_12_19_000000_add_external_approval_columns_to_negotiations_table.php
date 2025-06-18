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
        Schema::table('negotiations', function (Blueprint $table) {
            $table->text('external_approval_notes')->nullable()->after('external_approved');
            $table->timestamp('external_approved_at')->nullable()->after('external_approval_notes');
            $table->unsignedBigInteger('external_approved_by')->nullable()->after('external_approved_at');
            $table->text('external_rejection_notes')->nullable()->after('external_approved_by');
            $table->timestamp('external_rejected_at')->nullable()->after('external_rejection_notes');
            $table->unsignedBigInteger('external_rejected_by')->nullable()->after('external_rejected_at');
            
            $table->foreign('external_approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('external_rejected_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('negotiations', function (Blueprint $table) {
            $table->dropForeign(['external_approved_by']);
            $table->dropForeign(['external_rejected_by']);
            
            $table->dropColumn([
                'external_approval_notes',
                'external_approved_at',
                'external_approved_by',
                'external_rejection_notes',
                'external_rejected_at',
                'external_rejected_by'
            ]);
        });
    }
}; 