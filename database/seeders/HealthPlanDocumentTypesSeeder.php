<?php

namespace Database\Seeders;

use App\Models\EntityDocumentType;
use Illuminate\Database\Seeder;

class HealthPlanDocumentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $documentTypes = [
            [
                'entity_type' => 'health_plan',
                'name' => 'Contrato Social',
                'code' => 'social_contract',
                'description' => 'Contrato social ou estatuto da operadora de plano de saúde',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => null // Não expira
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Registro ANS',
                'code' => 'ans_registration',
                'description' => 'Registro da operadora na Agência Nacional de Saúde Suplementar',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 90 // Alerta 90 dias antes
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Alvará de Funcionamento',
                'code' => 'operating_license',
                'description' => 'Alvará de funcionamento emitido pela prefeitura',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 30 // Alerta 30 dias antes
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Certidão Negativa de Débitos',
                'code' => 'tax_clearance',
                'description' => 'Certidão negativa de débitos federais',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 30
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Certificado Digital',
                'code' => 'digital_certificate',
                'description' => 'Certificado digital para emissão de documentos eletrônicos',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 30
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Comprovante de Regularidade INSS',
                'code' => 'inss_clearance',
                'description' => 'Certidão de regularidade junto ao INSS',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 30
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Comprovante de Regularidade FGTS',
                'code' => 'fgts_clearance',
                'description' => 'Certificado de regularidade do FGTS',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 30
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Tabela de Preços',
                'code' => 'price_table',
                'description' => 'Tabela de preços dos procedimentos médicos',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 180 // Alerta 6 meses antes
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Rede Credenciada',
                'code' => 'accredited_network',
                'description' => 'Lista da rede credenciada de prestadores',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 90
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Procuração do Representante Legal',
                'code' => 'legal_proxy',
                'description' => 'Procuração dando poderes ao representante legal',
                'is_required' => true,
                'is_active' => true,
                'expiration_alert_days' => 365 // Alerta 1 ano antes
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Comprovante Bancário',
                'code' => 'bank_details',
                'description' => 'Comprovante dos dados bancários para pagamentos',
                'is_required' => false,
                'is_active' => true,
                'expiration_alert_days' => null
            ],
            [
                'entity_type' => 'health_plan',
                'name' => 'Outros Documentos',
                'code' => 'other_documents',
                'description' => 'Documentos adicionais não categorizados',
                'is_required' => false,
                'is_active' => true,
                'expiration_alert_days' => null
            ]
        ];

        foreach ($documentTypes as $type) {
            EntityDocumentType::updateOrCreate(
                ['code' => $type['code'], 'entity_type' => $type['entity_type']],
                $type
            );
        }
    }
} 