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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number')->unique();
            $table->unsignedBigInteger('contractable_id');
            $table->string('contractable_type');
            $table->string('type')->comment('health_plan, clinic, professional');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('pending')->comment('pending, active, expired, terminated');
            $table->string('file_path');
            $table->boolean('is_signed')->default(false);
            $table->dateTime('signed_at')->nullable();
            $table->string('signature_ip')->nullable();
            $table->string('signature_token')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['contractable_id', 'contractable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
}; 