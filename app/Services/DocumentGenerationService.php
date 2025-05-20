<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Tuss;
use App\Models\HealthPlan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class DocumentGenerationService
{
    /**
     * Generate appointment guide
     *
     * @param int $appointmentId
     * @param Patient $patient
     * @param mixed $provider
     * @param Tuss $procedure
     * @param HealthPlan $healthPlan
     * @param Carbon $scheduledDate
     * @param float $price
     * @return string Path to the generated file
     */
    public function generateAppointmentGuide(
        int $appointmentId,
        Patient $patient,
        $provider,
        Tuss $procedure,
        HealthPlan $healthPlan,
        Carbon $scheduledDate,
        float $price
    ) {
        try {
            // Generate unique code for the guide
            $guideCode = $this->generateGuideCode($appointmentId, $patient->id, $healthPlan->id);
            
            // Format date for the guide
            $formattedDate = $scheduledDate->format('d/m/Y H:i');
            
            // Get provider name and details
            $providerName = $provider->name;
            $providerType = class_basename($provider);
            $providerAddress = $provider->address ?? 'Não informado';
            
            // Generate guide data
            $data = [
                'guide_number' => $guideCode,
                'date_generated' => Carbon::now()->format('d/m/Y H:i:s'),
                'appointment_id' => $appointmentId,
                'scheduled_date' => $formattedDate,
                
                // Patient data
                'patient_name' => $patient->name,
                'patient_cpf' => $patient->cpf,
                'patient_dob' => $patient->birth_date ? Carbon::parse($patient->birth_date)->format('d/m/Y') : 'Não informado',
                'patient_gender' => $patient->gender ?? 'Não informado',
                'patient_card_number' => $patient->health_plan_card_number ?? 'Não informado',
                
                // Provider data
                'provider_name' => $providerName,
                'provider_type' => $providerType,
                'provider_address' => $providerAddress,
                
                // Procedure data
                'procedure_code' => $procedure->code,
                'procedure_name' => $procedure->name,
                
                // Health Plan data
                'health_plan_name' => $healthPlan->name,
                'health_plan_code' => $healthPlan->code ?? 'N/A',
                
                // Financial data
                'procedure_price' => number_format($price, 2, ',', '.'),
                
                // Signature areas
                'patient_signature_area' => true,
                'provider_signature_area' => true,
            ];
            
            // Generate PDF
            $pdf = PDF::loadView('documents.appointment_guide', $data);
            
            // Save to storage
            $filename = "guide_{$guideCode}_{$appointmentId}.pdf";
            $path = "guides/{$healthPlan->id}/{$appointmentId}/{$filename}";
            
            Storage::put($path, $pdf->output());
            
            Log::info('Appointment guide generated successfully', [
                'appointment_id' => $appointmentId,
                'file_path' => $path
            ]);
            
            return $path;
        } catch (\Exception $e) {
            Log::error('Error generating appointment guide: ' . $e->getMessage(), [
                'appointment_id' => $appointmentId,
                'exception' => $e
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Generate a unique code for the guide
     *
     * @param int $appointmentId
     * @param int $patientId
     * @param int $healthPlanId
     * @return string
     */
    protected function generateGuideCode(int $appointmentId, int $patientId, int $healthPlanId): string
    {
        $date = Carbon::now()->format('Ymd');
        $random = substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 4);
        
        return "GDA{$healthPlanId}{$date}{$appointmentId}{$random}";
    }
    
    /**
     * Validate a signed guide that was uploaded
     *
     * @param string $guidePath
     * @return bool
     */
    public function validateSignedGuide(string $guidePath): bool
    {
        // Implement validation if needed
        // This could check file integrity, presence of signatures, etc.
        
        return true;
    }
    
    /**
     * Create a directory blade template for the guide
     * This method would be used to create the initial blade template when setting up the system
     */
    public function createGuideTemplate()
    {
        $templateContent = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Guia de Atendimento</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }
        .container {
            width: 100%;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 10px;
        }
        .logo {
            max-width: 200px;
            height: auto;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            margin: 10px 0;
        }
        .subtitle {
            font-size: 14px;
            margin: 5px 0;
        }
        .info-box {
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 15px;
        }
        .info-title {
            font-weight: bold;
            margin-bottom: 5px;
            background-color: #f3f3f3;
            padding: 3px;
        }
        .row {
            display: flex;
            margin-bottom: 5px;
        }
        .col {
            flex: 1;
        }
        .label {
            font-weight: bold;
            margin-right: 5px;
        }
        .signature-area {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            border-top: 1px solid #333;
            width: 45%;
            padding-top: 5px;
            text-align: center;
        }
        .guide-number {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 14px;
            font-weight: bold;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" class="logo">
            <div class="title">GUIA DE ATENDIMENTO MÉDICO</div>
            <div class="subtitle">{{ $health_plan_name }}</div>
        </div>
        
        <div class="guide-number">Guia Nº: {{ $guide_number }}</div>
        
        <div class="info-box">
            <div class="info-title">DADOS DO BENEFICIÁRIO</div>
            <div class="row">
                <div class="col">
                    <span class="label">Nome:</span> {{ $patient_name }}
                </div>
                <div class="col">
                    <span class="label">CPF:</span> {{ $patient_cpf }}
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <span class="label">Data de Nascimento:</span> {{ $patient_dob }}
                </div>
                <div class="col">
                    <span class="label">Gênero:</span> {{ $patient_gender }}
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <span class="label">Carteira:</span> {{ $patient_card_number }}
                </div>
                <div class="col">
                    <span class="label">Plano:</span> {{ $health_plan_name }}
                </div>
            </div>
        </div>
        
        <div class="info-box">
            <div class="info-title">DADOS DO PRESTADOR</div>
            <div class="row">
                <div class="col">
                    <span class="label">Nome:</span> {{ $provider_name }}
                </div>
                <div class="col">
                    <span class="label">Tipo:</span> {{ $provider_type }}
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <span class="label">Endereço:</span> {{ $provider_address }}
                </div>
            </div>
        </div>
        
        <div class="info-box">
            <div class="info-title">DADOS DO ATENDIMENTO</div>
            <div class="row">
                <div class="col">
                    <span class="label">Data/Hora:</span> {{ $scheduled_date }}
                </div>
                <div class="col">
                    <span class="label">Código do Procedimento:</span> {{ $procedure_code }}
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <span class="label">Procedimento:</span> {{ $procedure_name }}
                </div>
                <div class="col">
                    <span class="label">Valor:</span> R$ {{ $procedure_price }}
                </div>
            </div>
        </div>
        
        <div class="info-box">
            <div class="info-title">AUTORIZAÇÕES</div>
            <div class="row">
                <div class="col">
                    <span class="label">Código de Autorização:</span> {{ $guide_number }}
                </div>
                <div class="col">
                    <span class="label">Data de Emissão:</span> {{ $date_generated }}
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <span class="label">Observações:</span> Esta guia é válida apenas para o atendimento na data e horário agendados.
                </div>
            </div>
        </div>
        
        <div class="signature-area">
            <div class="signature-box">
                Assinatura do Beneficiário
            </div>
            <div class="signature-box">
                Assinatura e Carimbo do Profissional
            </div>
        </div>
        
        <div style="margin-top: 30px; font-size: 10px; text-align: center;">
            Este documento é válido apenas com as assinaturas do beneficiário e do profissional que realizou o atendimento.
        </div>
    </div>
</body>
</html>
HTML;

        // Create directories if they don't exist
        if (!file_exists(resource_path('views/documents'))) {
            mkdir(resource_path('views/documents'), 0755, true);
        }
        
        // Write the template to a file
        file_put_contents(resource_path('views/documents/appointment_guide.blade.php'), $templateContent);
        
        return true;
    }
} 