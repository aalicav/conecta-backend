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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('sender_phone')->nullable(); // Phone number of sender (for inbound messages)
            $table->string('recipient_phone')->nullable(); // Phone number of recipient (for outbound messages)
            $table->text('content')->nullable(); // Message content
            $table->string('media_url')->nullable(); // URL of media file
            $table->string('media_type')->nullable(); // Type of media (image, document, video, audio)
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound'); // Message direction
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->text('error_message')->nullable(); // Error message if failed
            $table->timestamp('sent_at')->nullable(); // When message was sent
            $table->timestamp('delivered_at')->nullable(); // When message was delivered
            $table->timestamp('read_at')->nullable(); // When message was read
            $table->string('external_id')->nullable(); // External ID from Twilio
            $table->string('related_model_type')->nullable(); // Polymorphic relationship type
            $table->unsignedBigInteger('related_model_id')->nullable(); // Polymorphic relationship ID
            $table->enum('message_type', ['text', 'media', 'template'])->default('text'); // Type of message
            $table->string('template_name')->nullable(); // Template name if using template
            $table->json('metadata')->nullable(); // Additional metadata
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for faster queries
            $table->index('direction');
            $table->index('status');
            $table->index('sender_phone');
            $table->index('recipient_phone');
            $table->index(['related_model_type', 'related_model_id']);
            $table->index('external_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
}; 