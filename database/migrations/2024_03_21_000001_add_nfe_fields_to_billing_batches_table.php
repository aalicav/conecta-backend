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
        Schema::table('billing_batches', function (Blueprint $table) {
            $table->string('nfe_number')->nullable();
            $table->string('nfe_key', 44)->nullable();
            $table->text('nfe_xml')->nullable();
            $table->string('nfe_status')->default('pending');
            $table->string('nfe_protocol')->nullable();
            $table->timestamp('nfe_authorization_date')->nullable();

            // Add indexes for better performance
            $table->index('nfe_number');
            $table->index('nfe_key');
            $table->index('nfe_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_batches', function (Blueprint $table) {
            $table->dropIndex(['nfe_number']);
            $table->dropIndex(['nfe_key']);
            $table->dropIndex(['nfe_status']);

            $table->dropColumn([
                'nfe_number',
                'nfe_key',
                'nfe_xml',
                'nfe_status',
                'nfe_protocol',
                'nfe_authorization_date'
            ]);
        });
    }
}; 