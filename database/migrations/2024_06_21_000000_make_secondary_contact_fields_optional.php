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
        Schema::table('patients', function (Blueprint $table) {
            $table->string('secondary_contact_name')->nullable()->change();
            $table->string('secondary_contact_phone')->nullable()->change();
            $table->string('secondary_contact_relationship')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('secondary_contact_name')->nullable(false)->change();
            $table->string('secondary_contact_phone')->nullable(false)->change();
            $table->string('secondary_contact_relationship')->nullable(false)->change();
        });
    }
}; 