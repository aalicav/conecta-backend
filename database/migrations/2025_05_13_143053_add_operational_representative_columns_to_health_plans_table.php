<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOperationalRepresentativeColumnsToHealthPlansTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('health_plans', function (Blueprint $table) {
            $table->string('operational_representative_name')->nullable()->after('legal_representative_email');
            $table->string('operational_representative_cpf')->nullable()->after('operational_representative_name');
            $table->string('operational_representative_position')->nullable()->after('operational_representative_cpf');
            $table->string('operational_representative_email')->nullable()->after('operational_representative_position');
            $table->string('operational_representative_phone')->nullable()->after('operational_representative_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_plans', function (Blueprint $table) {
            $table->dropColumn([
                'operational_representative_name',
                'operational_representative_cpf',
                'operational_representative_position',
                'operational_representative_email',
                'operational_representative_phone'
            ]);
        });
    }
}
