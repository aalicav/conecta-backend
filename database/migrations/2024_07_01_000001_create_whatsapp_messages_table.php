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
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('recipient');
            $table->text('message')->nullable();
            $table->string('media_url')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('external_id')->nullable();
            $table->string('related_model_type')->nullable();
            $table->unsignedBigInteger('related_model_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Index for faster queries
            $table->index('status');
            $table->index('recipient');
            $table->index(['related_model_type', 'related_model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
}; 