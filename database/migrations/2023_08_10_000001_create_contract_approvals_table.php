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
        Schema::create('contract_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->string('step');  // submission, legal_review, commercial_review, director_approval
            $table->enum('status', ['pending', 'completed', 'rejected'])->default('pending');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['contract_id', 'step']);
            $table->index('status');
            $table->index('user_id');
        });
        
        // Add new status values to contracts table
        Schema::table('contracts', function (Blueprint $table) {
            // Modify the existing status enum to add approval workflow statuses
            // Note: In a real scenario, you might need to handle this differently
            // based on your database engine (MySQL, PostgreSQL, etc.)
            // This example is for MySQL
            DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM(
                'draft', 
                'pending_approval',
                'legal_review', 
                'commercial_review', 
                'pending_director_approval',
                'approved', 
                'active', 
                'expired', 
                'terminated', 
                'cancelled'
            ) NOT NULL DEFAULT 'draft'");
            
            $table->timestamp('submitted_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_approvals');
        
        // Revert changes to contracts table
        Schema::table('contracts', function (Blueprint $table) {
            // Revert status enum to original values
            // Again, this depends on your database engine
            DB::statement("ALTER TABLE contracts MODIFY COLUMN status ENUM(
                'draft', 
                'approved', 
                'active', 
                'expired', 
                'terminated', 
                'cancelled'
            ) NOT NULL DEFAULT 'draft'");
            
            $table->dropColumn('submitted_at');
        });
    }
}; 