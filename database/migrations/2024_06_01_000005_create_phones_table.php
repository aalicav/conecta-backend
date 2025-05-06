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
        Schema::create('phones', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->string('country_code')->default('+55');
            $table->string('type')->comment('mobile, landline, work, fax');
            $table->boolean('is_whatsapp')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('phoneable_id');
            $table->string('phoneable_type');
            $table->timestamps();

            $table->index(['phoneable_id', 'phoneable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phones');
    }
}; 