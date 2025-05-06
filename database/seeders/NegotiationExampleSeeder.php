<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Negotiation;
use App\Models\NegotiationItem;
use App\Models\HealthPlan;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\Tuss;
use App\Models\ContractTemplate;
use App\Models\User;
use Carbon\Carbon;

class NegotiationExampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = User::where('email', 'admin@example.com')->first();
        
        if (!$adminUser) {
            $adminUser = User::first();
        }

        $creatorId = $adminUser ? $adminUser->id : 1;
        
        // Get entities for negotiations
        $healthPlan = HealthPlan::first();
        $professional = Professional::first();
        $clinic = Clinic::first();
        
        // If they don't exist, we can't create negotiations
        if (!$healthPlan || !$professional || !$clinic) {
            $this->command->info('Missing entities (HealthPlan, Professional, or Clinic). Skipping NegotiationExampleSeeder.');
            return;
        }
        
        // Get contract templates
        $healthPlanTemplate = ContractTemplate::where('entity_type', 'health_plan')->first();
        $professionalTemplate = ContractTemplate::where('entity_type', 'professional')->first();
        $clinicTemplate = ContractTemplate::where('entity_type', 'clinic')->first();
        
        // Get some TUSS procedures
        $tussProcedures = Tuss::take(10)->get();
        
        if ($tussProcedures->isEmpty()) {
            $this->command->info('No TUSS procedures found. Skipping NegotiationExampleSeeder.');
            return;
        }
        
        // Create a Health Plan negotiation
        if ($healthPlan && $healthPlanTemplate) {
            $healthPlanNegotiation = Negotiation::create([
                'title' => 'Negociação Anual - ' . $healthPlan->name,
                'description' => 'Negociação de valores para procedimentos do plano ' . $healthPlan->name,
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addYear(),
                'negotiable_type' => HealthPlan::class,
                'negotiable_id' => $healthPlan->id,
                'contract_template_id' => $healthPlanTemplate->id,
                'creator_id' => $creatorId,
                'status' => 'approved',
                'approved_at' => Carbon::now(),
                'notes' => 'Negociação aprovada automaticamente pelo sistema.',
            ]);
            
            // Add negotiation items
            foreach ($tussProcedures as $index => $tuss) {
                // Base price plus some random variation for each procedure
                $basePrice = 100 + ($index * 20);
                
                NegotiationItem::create([
                    'negotiation_id' => $healthPlanNegotiation->id,
                    'tuss_id' => $tuss->id,
                    'proposed_value' => $basePrice,
                    'approved_value' => $basePrice,
                    'status' => 'approved',
                    'responded_at' => Carbon::now(),
                    'notes' => 'Valor aprovado na negociação inicial.',
                ]);
            }
        }
        
        // Create a Professional negotiation
        if ($professional && $professionalTemplate) {
            $professionalNegotiation = Negotiation::create([
                'title' => 'Credenciamento - Dr. ' . $professional->name,
                'description' => 'Negociação inicial para credenciamento do profissional',
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addYear(),
                'negotiable_type' => Professional::class,
                'negotiable_id' => $professional->id,
                'contract_template_id' => $professionalTemplate->id,
                'creator_id' => $creatorId,
                'status' => 'approved',
                'approved_at' => Carbon::now(),
                'notes' => 'Credenciamento padrão para novos profissionais.',
            ]);
            
            // Add negotiation items
            foreach ($tussProcedures->take(5) as $index => $tuss) {
                $basePrice = 80 + ($index * 15);
                
                NegotiationItem::create([
                    'negotiation_id' => $professionalNegotiation->id,
                    'tuss_id' => $tuss->id,
                    'proposed_value' => $basePrice,
                    'approved_value' => $basePrice,
                    'status' => 'approved',
                    'responded_at' => Carbon::now(),
                    'notes' => 'Valor aprovado no credenciamento inicial.',
                ]);
            }
        }
        
        // Create a Clinic negotiation
        if ($clinic && $clinicTemplate) {
            $clinicNegotiation = Negotiation::create([
                'title' => 'Contrato de Serviços - ' . $clinic->name,
                'description' => 'Negociação para prestação de serviços pela clínica',
                'start_date' => Carbon::now(),
                'end_date' => Carbon::now()->addYear(),
                'negotiable_type' => Clinic::class,
                'negotiable_id' => $clinic->id,
                'contract_template_id' => $clinicTemplate->id,
                'creator_id' => $creatorId,
                'status' => 'approved',
                'approved_at' => Carbon::now(),
                'notes' => 'Contrato padrão para clínicas parceiras.',
            ]);
            
            // Add negotiation items
            foreach ($tussProcedures as $index => $tuss) {
                $basePrice = 150 + ($index * 25);
                
                NegotiationItem::create([
                    'negotiation_id' => $clinicNegotiation->id,
                    'tuss_id' => $tuss->id,
                    'proposed_value' => $basePrice,
                    'approved_value' => $basePrice,
                    'status' => 'approved',
                    'responded_at' => Carbon::now(),
                    'notes' => 'Valor aprovado no contrato inicial.',
                ]);
            }
        }
        
        // Create an "in progress" negotiation example
        if ($healthPlan && $healthPlanTemplate) {
            $pendingNegotiation = Negotiation::create([
                'title' => 'Renegociação de Valores - ' . $healthPlan->name,
                'description' => 'Atualização de valores para procedimentos específicos',
                'start_date' => Carbon::now()->addDays(15),
                'end_date' => Carbon::now()->addYear()->addDays(15),
                'negotiable_type' => HealthPlan::class,
                'negotiable_id' => $healthPlan->id,
                'contract_template_id' => $healthPlanTemplate->id,
                'creator_id' => $creatorId,
                'status' => 'submitted',
                'notes' => 'Renegociação de valores solicitada pelo plano.',
            ]);
            
            // Add negotiation items with mixed statuses
            foreach ($tussProcedures->take(6) as $index => $tuss) {
                $basePrice = 120 + ($index * 30);
                $proposedPrice = $basePrice * 1.15; // 15% increase
                
                // Alternate between pending, approved, and counter_offered
                $status = ['pending', 'approved', 'counter_offered'][$index % 3];
                $approvedValue = null;
                $respondedAt = null;
                
                if ($status === 'approved') {
                    $approvedValue = $proposedPrice;
                    $respondedAt = Carbon::now();
                } elseif ($status === 'counter_offered') {
                    $approvedValue = $proposedPrice * 0.9; // 10% less than proposed
                    $respondedAt = Carbon::now();
                }
                
                NegotiationItem::create([
                    'negotiation_id' => $pendingNegotiation->id,
                    'tuss_id' => $tuss->id,
                    'proposed_value' => $proposedPrice,
                    'approved_value' => $approvedValue,
                    'status' => $status,
                    'responded_at' => $respondedAt,
                    'notes' => 'Valor em renegociação.',
                ]);
            }
        }
    }
} 