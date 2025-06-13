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
        Schema::table('billing_rules', function (Blueprint $table) {
            $table->boolean('generate_nfe')->default(false);
            $table->integer('nfe_series')->nullable();
            $table->integer('nfe_environment')->default(2); // 1-Produção, 2-Homologação

            // Add index for better performance
            $table->index('generate_nfe');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_rules', function (Blueprint $table) {
            $table->dropIndex(['generate_nfe']);

            $table->dropColumn([
                'generate_nfe',
                'nfe_series',
                'nfe_environment'
            ]);
        });
    }
}; 