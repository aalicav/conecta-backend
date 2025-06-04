<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPatientJourneyToAppointments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Scheduling and confirmation tracking
            $table->timestamp('scheduled_at')->nullable()->after('scheduled_date');
            $table->timestamp('pre_confirmation_sent_at')->nullable();
            $table->timestamp('pre_confirmation_response_at')->nullable();
            $table->boolean('pre_confirmation_response')->nullable();
            
            // Day-of confirmation tracking
            $table->timestamp('day_confirmation_sent_at')->nullable();
            $table->timestamp('patient_confirmation_at')->nullable();
            $table->timestamp('professional_confirmation_at')->nullable();
            $table->boolean('patient_confirmed')->nullable();
            $table->boolean('professional_confirmed')->nullable();
            
            // Guide and documentation tracking
            $table->timestamp('guide_attached_at')->nullable();
            $table->timestamp('guide_signed_at')->nullable();
            $table->string('guide_status')->nullable();
            
            // Attendance tracking
            $table->boolean('patient_attended')->nullable();
            $table->text('absence_reason')->nullable();
            $table->string('absence_type')->nullable();
            
            // Billing eligibility
            $table->boolean('eligible_for_billing')->default(false);
            $table->timestamp('billing_eligibility_checked_at')->nullable();
            $table->text('billing_ineligibility_reason')->nullable();
            
            // Indexes for efficient querying
            $table->index('scheduled_at');
            $table->index('pre_confirmation_response');
            $table->index(['patient_confirmed', 'professional_confirmed']);
            $table->index('patient_attended');
            $table->index('eligible_for_billing');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'scheduled_at',
                'pre_confirmation_sent_at',
                'pre_confirmation_response_at',
                'pre_confirmation_response',
                'day_confirmation_sent_at',
                'patient_confirmation_at',
                'professional_confirmation_at',
                'patient_confirmed',
                'professional_confirmed',
                'guide_attached_at',
                'guide_signed_at',
                'guide_status',
                'patient_attended',
                'absence_reason',
                'absence_type',
                'eligible_for_billing',
                'billing_eligibility_checked_at',
                'billing_ineligibility_reason'
            ]);
        });
    }
} 