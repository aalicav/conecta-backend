<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EnhanceGlosaManagement extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payment_glosses', function (Blueprint $table) {
            // Glosa categorization
            $table->string('glosa_type')->after('description');
            $table->string('glosa_code')->nullable();
            $table->string('glosa_category')->nullable();
            $table->decimal('original_amount', 10, 2)->after('amount');
            
            // Operator interaction
            $table->string('operator_response_status')->default('pending');
            $table->text('operator_justification')->nullable();
            $table->timestamp('operator_response_at')->nullable();
            $table->string('operator_response_user')->nullable();
            
            // Resolution tracking
            $table->string('resolution_status')->default('pending');
            $table->text('resolution_notes')->nullable();
            $table->decimal('negotiated_amount', 10, 2)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            
            // Documentation
            $table->json('supporting_documents')->nullable();
            $table->timestamp('documents_sent_at')->nullable();
            $table->timestamp('documents_received_at')->nullable();
            
            // Appeal process
            $table->boolean('can_appeal')->default(true);
            $table->integer('appeal_deadline_days')->nullable();
            $table->timestamp('appeal_deadline_at')->nullable();
            $table->boolean('was_appealed')->default(false);
            $table->timestamp('appealed_at')->nullable();
            $table->string('appeal_status')->nullable();
            $table->text('appeal_notes')->nullable();
            
            // Indexes
            $table->index('glosa_type');
            $table->index('glosa_code');
            $table->index('operator_response_status');
            $table->index('resolution_status');
            $table->index(['can_appeal', 'appeal_deadline_at']);
            $table->index('appeal_status');
        });

        // Create glosa history table for tracking changes
        Schema::create('payment_gloss_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gloss_id')->constrained()->onDelete('cascade');
            $table->string('action_type');
            $table->string('status_from')->nullable();
            $table->string('status_to')->nullable();
            $table->decimal('amount_from', 10, 2)->nullable();
            $table->decimal('amount_to', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('changes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index('payment_gloss_id');
            $table->index('action_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_glosses', function (Blueprint $table) {
            $table->dropColumn([
                'glosa_type',
                'glosa_code',
                'glosa_category',
                'original_amount',
                'operator_response_status',
                'operator_justification',
                'operator_response_at',
                'operator_response_user',
                'resolution_status',
                'resolution_notes',
                'negotiated_amount',
                'resolved_at',
                'resolved_by',
                'supporting_documents',
                'documents_sent_at',
                'documents_received_at',
                'can_appeal',
                'appeal_deadline_days',
                'appeal_deadline_at',
                'was_appealed',
                'appealed_at',
                'appeal_status',
                'appeal_notes'
            ]);
        });

        Schema::dropIfExists('payment_gloss_history');
    }
} 