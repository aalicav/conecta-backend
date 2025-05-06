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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->comment('financial, appointment, performance, custom');
            $table->text('description')->nullable();
            $table->json('parameters')->nullable()->comment('Filter parameters used to generate the report');
            $table->string('file_path')->nullable();
            $table->string('file_format')->nullable()->comment('pdf, csv, xlsx');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('last_generated_at')->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->string('schedule_frequency')->nullable()->comment('daily, weekly, monthly, quarterly');
            $table->timestamp('next_scheduled_at')->nullable();
            $table->json('recipients')->nullable()->comment('List of user IDs or emails to send to');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_template')->default(false);
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('created_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
}; 