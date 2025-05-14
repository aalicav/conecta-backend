<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EntityDocumentTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $entities = ['professional', 'clinic'];
        
        // Seed document types for professionals (CPF)
        $professionalDocTypes = [
            [
                'name' => 'Documento de Identificação',
                'code' => 'identification',
                'description' => 'CPF, RG ou CNH do profissional',
                'is_required' => true,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Diploma',
                'code' => 'diploma',
                'description' => 'Diploma de graduação em medicina ou área da saúde',
                'is_required' => true,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Certificado de Especialização',
                'code' => 'certificate',
                'description' => 'Certificado de especialização médica',
                'is_required' => false,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Registro no Conselho (CRM/CREFITO/CRO)',
                'code' => 'license',
                'description' => 'Registro no conselho profissional',
                'is_required' => true,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => 60,
            ],
            [
                'name' => 'Contrato de Trabalho',
                'code' => 'contract',
                'description' => 'Contrato de prestação de serviços',
                'is_required' => false,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => 30,
            ],
            [
                'name' => 'Currículo',
                'code' => 'other',
                'description' => 'Currículo atualizado',
                'is_required' => false,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Comprovante de Endereço',
                'code' => 'other',
                'description' => 'Comprovante de residência atualizado',
                'is_required' => false,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Certificado de Vacinação',
                'code' => 'certificate',
                'description' => 'Comprovante de vacinação atualizado',
                'is_required' => false,
                'entity_type' => 'professional',
                'is_active' => true,
                'expiration_alert_days' => 365,
            ],
        ];
        
        // Seed document types for clinics/establishments (CNPJ)
        $clinicDocTypes = [
            [
                'name' => 'CNPJ',
                'code' => 'identification',
                'description' => 'Comprovante de CNPJ',
                'is_required' => true,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Alvará de Funcionamento',
                'code' => 'license',
                'description' => 'Alvará de funcionamento emitido pela prefeitura',
                'is_required' => true,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => 60,
            ],
            [
                'name' => 'Licença Sanitária',
                'code' => 'license',
                'description' => 'Licença sanitária emitida pela Vigilância Sanitária',
                'is_required' => true,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => 60,
            ],
            [
                'name' => 'Certificado de Regularidade Técnica',
                'code' => 'certificate',
                'description' => 'Certificado emitido pelo conselho profissional competente',
                'is_required' => true,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => 60,
            ],
            [
                'name' => 'Contrato Social',
                'code' => 'contract',
                'description' => 'Contrato social da empresa e alterações',
                'is_required' => true,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Comprovante de Endereço',
                'code' => 'other',
                'description' => 'Comprovante de endereço do estabelecimento',
                'is_required' => false,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => null,
            ],
            [
                'name' => 'Certificado de Controle de Pragas',
                'code' => 'certificate',
                'description' => 'Certificado de controle de pragas e vetores',
                'is_required' => false,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => 180,
            ],
            [
                'name' => 'AVCB/CLCB',
                'code' => 'license',
                'description' => 'Auto de Vistoria do Corpo de Bombeiros',
                'is_required' => false,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => 365,
            ],
            [
                'name' => 'PGRSS',
                'code' => 'other',
                'description' => 'Plano de Gerenciamento de Resíduos de Serviços de Saúde',
                'is_required' => false,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => 365,
            ],
            [
                'name' => 'Contrato com Operadora',
                'code' => 'contract',
                'description' => 'Contrato de credenciamento com operadora de saúde',
                'is_required' => false,
                'entity_type' => 'clinic',
                'is_active' => true,
                'expiration_alert_days' => 60,
            ],
        ];
        
        $allDocTypes = array_merge($professionalDocTypes, $clinicDocTypes);
        
        // Insert to entity_document_types table
        foreach ($allDocTypes as $docType) {
            DB::table('entity_document_types')->insert([
                'id' => DB::table('entity_document_types')->max('id') + 1,
                'name' => $docType['name'],
                'code' => $docType['code'],
                'description' => $docType['description'],
                'is_required' => $docType['is_required'],
                'entity_type' => $docType['entity_type'],
                'is_active' => $docType['is_active'],
                'expiration_alert_days' => $docType['expiration_alert_days'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
} 