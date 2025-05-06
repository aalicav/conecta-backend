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
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->boolean('is_negotiation_template')->default(false)->after('is_active');
            $table->json('negotiation_fields')->nullable()->after('is_negotiation_template')
                ->comment('JSON mapping of negotiation fields to template placeholders');
            $table->boolean('auto_apply_negotiation_values')->default(false)->after('negotiation_fields')
                ->comment('Automatically apply negotiation values when generating contract');
            $table->index('is_negotiation_template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->dropIndex(['is_negotiation_template']);
            $table->dropColumn([
                'is_negotiation_template',
                'negotiation_fields',
                'auto_apply_negotiation_values'
            ]);
        });
    }
};
