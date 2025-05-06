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
        Schema::create('pricing_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tuss_procedure_id');
            $table->unsignedBigInteger('contractable_id');
            $table->string('contractable_type');
            $table->decimal('price', 10, 2);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('tuss_procedure_id')->references('id')->on('tuss_procedures')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['contractable_id', 'contractable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_contracts');
    }
}; 