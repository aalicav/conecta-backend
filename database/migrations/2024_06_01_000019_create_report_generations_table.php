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
        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id');
            $table->string('file_path')->nullable();
            $table->string('file_format');
            $table->json('parameters')->nullable();
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status')->default('processing')->comment('processing, completed, failed');
            $table->text('error_message')->nullable();
            $table->integer('rows_count')->nullable();
            $table->string('file_size')->nullable();
            $table->boolean('was_scheduled')->default(false)->comment('Whether this was generated by a schedule');
            $table->boolean('was_sent')->default(false)->comment('Whether this was sent to recipients');
            $table->json('sent_to')->nullable()->comment('List of user IDs or emails this was sent to');
            $table->timestamps();
            
            $table->foreign('report_id')->references('id')->on('reports')->onDelete('cascade');
            $table->foreign('generated_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_generations');
    }
}; 