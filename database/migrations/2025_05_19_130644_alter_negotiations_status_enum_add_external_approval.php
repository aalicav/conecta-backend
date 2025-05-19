<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE negotiations MODIFY COLUMN status ENUM(
            'draft', 'submitted', 'pending', 'complete', 
            'approved', 'partially_approved', 'rejected', 'cancelled',
            'pending_external_approval', 'externally_approved', 'externally_rejected',
            'pending_review_after_external', 'external_review_requested'
        ) DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE negotiations MODIFY COLUMN status ENUM(
            'draft', 'submitted', 'pending', 'complete', 
            'approved', 'partially_approved', 'rejected', 'cancelled'
        ) DEFAULT 'draft'");
    }
};
