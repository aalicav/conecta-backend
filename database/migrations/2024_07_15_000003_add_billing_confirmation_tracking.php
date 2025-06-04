<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBillingConfirmationTracking extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add confirmation tracking to billing batches
        Schema::table('billing_batches', function (Blueprint $table) {
            // Payment tracking
            $table->string('payment_status')->default('pending')->after('status');
            $table->timestamp('payment_received_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_proof_path')->nullable();
            
            // Invoice tracking
            $table->string('invoice_status')->nullable();
            $table->timestamp('invoice_generated_at')->nullable();
            $table->timestamp('invoice_sent_at')->nullable();
            $table->string('invoice_xml_path')->nullable();
            $table->string('invoice_pdf_path')->nullable();
            
            // Operator portal tracking
            $table->timestamp('operator_viewed_at')->nullable();
            $table->timestamp('operator_approved_at')->nullable();
            $table->string('operator_approval_user')->nullable();
            
            // Late payment tracking
            $table->boolean('is_late')->default(false);
            $table->integer('days_late')->nullable();
            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->integer('reminders_sent_count')->default(0);
            
            // Indexes
            $table->index('payment_status');
            $table->index('payment_received_at');
            $table->index('invoice_status');
            $table->index('is_late');
        });

        // Add confirmation tracking to billing items
        Schema::table('billing_items', function (Blueprint $table) {
            // Item verification
            $table->boolean('verified_by_operator')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_user')->nullable();
            $table->text('verification_notes')->nullable();
            
            // Patient journey reference
            $table->json('patient_journey_data')->nullable();
            
            // Procedure details
            $table->string('tuss_code')->nullable();
            $table->string('tuss_description')->nullable();
            $table->string('professional_name')->nullable();
            $table->string('professional_specialty')->nullable();
            $table->string('patient_name')->nullable();
            $table->string('patient_document')->nullable();
            
            // Indexes
            $table->index('verified_by_operator');
            $table->index('verified_at');
            $table->index('tuss_code');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('billing_batches', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'payment_received_at',
                'payment_method',
                'payment_reference',
                'payment_proof_path',
                'invoice_status',
                'invoice_generated_at',
                'invoice_sent_at',
                'invoice_xml_path',
                'invoice_pdf_path',
                'operator_viewed_at',
                'operator_approved_at',
                'operator_approval_user',
                'is_late',
                'days_late',
                'last_reminder_sent_at',
                'reminders_sent_count'
            ]);
        });

        Schema::table('billing_items', function (Blueprint $table) {
            $table->dropColumn([
                'verified_by_operator',
                'verified_at',
                'verification_user',
                'verification_notes',
                'patient_journey_data',
                'tuss_code',
                'tuss_description',
                'professional_name',
                'professional_specialty',
                'patient_name',
                'patient_document'
            ]);
        });
    }
} 