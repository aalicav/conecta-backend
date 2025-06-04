<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFiscalDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('document_type');
            $table->string('document_number');
            $table->string('series_number')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('issue_date');
            $table->date('competence_date');
            $table->string('status');
            
            // Municipal integration
            $table->string('municipal_registration')->nullable();
            $table->string('municipal_batch_number')->nullable();
            $table->string('municipal_protocol')->nullable();
            $table->string('municipal_verification_code')->nullable();
            
            // Document paths
            $table->string('xml_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('rps_path')->nullable();
            
            // Integration status
            $table->string('integration_status')->nullable();
            $table->timestamp('sent_to_municipal')->nullable();
            $table->timestamp('processed_by_municipal')->nullable();
            $table->text('integration_error')->nullable();
            $table->integer('integration_attempts')->default(0);
            
            // Cancellation
            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_protocol')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            // Substitution
            $table->boolean('is_substituted')->default(false);
            $table->foreignId('substituted_by_id')->nullable()->constrained('fiscal_documents');
            $table->foreignId('substitutes_id')->nullable()->constrained('fiscal_documents');
            
            // Service details
            $table->string('service_code')->nullable();
            $table->text('service_description')->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('iss_amount', 10, 2)->nullable();
            $table->string('tax_regime')->nullable();
            
            // Recipient details
            $table->string('recipient_name');
            $table->string('recipient_document');
            $table->string('recipient_municipal_registration')->nullable();
            $table->string('recipient_address')->nullable();
            $table->string('recipient_city')->nullable();
            $table->string('recipient_state')->nullable();
            $table->string('recipient_postal_code')->nullable();
            
            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('document_number');
            $table->index('issue_date');
            $table->index('status');
            $table->index('integration_status');
            $table->index('is_cancelled');
            $table->index('recipient_document');
        });

        // Create fiscal document events table
        Schema::create('fiscal_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_document_id')->constrained()->onDelete('cascade');
            $table->string('event_type');
            $table->string('status');
            $table->text('description')->nullable();
            $table->json('event_data')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index(['fiscal_document_id', 'event_type']);
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
        Schema::dropIfExists('fiscal_document_events');
        Schema::dropIfExists('fiscal_documents');
    }
} 