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
            $table->unsignedBigInteger('legal_representative_id')->nullable()->after('legal_representative_position');
            
            $table->foreign('legal_representative_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
                
            $table->string('operational_representative_name')->nullable()->after('legal_representative_id');
            $table->string('operational_representative_cpf')->nullable()->after('operational_representative_name');
            $table->string('operational_representative_position')->nullable()->after('operational_representative_cpf');
            $table->unsignedBigInteger('operational_representative_id')->nullable()->after('operational_representative_position');
            
            $table->foreign('operational_representative_id')
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
        Schema::table('health_plans', function (Blueprint $table) {
            $table->dropForeign(['legal_representative_id']);
            $table->dropForeign(['operational_representative_id']);
            
            $table->dropColumn([
                'legal_representative_id',
                'operational_representative_name',
                'operational_representative_cpf',
                'operational_representative_position',
                'operational_representative_id',
            ]);
        });
    }
}
