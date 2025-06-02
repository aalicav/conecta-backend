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
        // Verifica e remove foreign keys existentes
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'negotiations'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            AND CONSTRAINT_NAME IN ('negotiations_approved_by_foreign', 'negotiations_rejected_by_foreign')
        ");

        Schema::table('negotiations', function (Blueprint $table) use ($foreignKeys) {
            // Remove foreign keys existentes
            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }

            // Remove colunas existentes se existirem
            $columns = [
                'approval_level',
                'approved_by',
                'approved_at',
                'approval_notes',
                'rejection_notes',
                'rejected_by',
                'rejected_at'
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('negotiations', $column)) {
                    $table->dropColumn($column);
                }
            }

            // Adiciona as novas colunas
            $table->enum('approval_level', ['pending_approval'])->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            
            $table->foreign('approved_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
                  
            $table->foreign('rejected_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Verifica e remove foreign keys existentes
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'negotiations'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            AND CONSTRAINT_NAME IN ('negotiations_approved_by_foreign', 'negotiations_rejected_by_foreign')
        ");

        Schema::table('negotiations', function (Blueprint $table) use ($foreignKeys) {
            // Remove foreign keys existentes
            foreach ($foreignKeys as $fk) {
                $table->dropForeign($fk->CONSTRAINT_NAME);
            }

            $table->dropColumn([
                'approval_level',
                'approved_by',
                'approved_at',
                'approval_notes',
                'rejection_notes',
                'rejected_by',
                'rejected_at'
            ]);
        });
    }
}; 