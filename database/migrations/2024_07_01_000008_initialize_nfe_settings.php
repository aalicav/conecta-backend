<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\SystemSetting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaultSettings = [
            [
                'key' => 'nfe.environment',
                'value' => '2',
                'type' => 'string',
                'description' => 'Ambiente da NFe (1-Produção, 2-Homologação)',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.company_name',
                'value' => 'Empresa Padrão',
                'type' => 'string',
                'description' => 'Razão Social da empresa',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.cnpj',
                'value' => '00000000000000',
                'type' => 'string',
                'description' => 'CNPJ da empresa',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.state',
                'value' => 'SP',
                'type' => 'string',
                'description' => 'Sigla do estado (UF)',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.schemes',
                'value' => 'PL_009_V4',
                'type' => 'string',
                'description' => 'Schemas da NFe',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.version',
                'value' => '4.00',
                'type' => 'string',
                'description' => 'Versão da NFe',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.ibpt_token',
                'value' => '',
                'type' => 'string',
                'description' => 'Token do IBPT',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.csc',
                'value' => '',
                'type' => 'string',
                'description' => 'CSC (Código de Segurança do Contribuinte)',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.csc_id',
                'value' => '',
                'type' => 'string',
                'description' => 'ID do CSC',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.certificate_path',
                'value' => 'certificates/certificate.pfx',
                'type' => 'string',
                'description' => 'Caminho do certificado digital',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.certificate_password',
                'value' => '',
                'type' => 'string',
                'description' => 'Senha do certificado digital',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.address.street',
                'value' => '',
                'type' => 'string',
                'description' => 'Logradouro do endereço',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.address.number',
                'value' => '',
                'type' => 'string',
                'description' => 'Número do endereço',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.address.district',
                'value' => '',
                'type' => 'string',
                'description' => 'Bairro do endereço',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.address.city',
                'value' => '',
                'type' => 'string',
                'description' => 'Cidade do endereço',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.address.zipcode',
                'value' => '',
                'type' => 'string',
                'description' => 'CEP do endereço',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.ie',
                'value' => '',
                'type' => 'string',
                'description' => 'Inscrição Estadual',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.crt',
                'value' => '1',
                'type' => 'string',
                'description' => 'Código de Regime Tributário',
                'group' => 'nfe',
                'is_public' => false,
            ],
            [
                'key' => 'nfe.city_code',
                'value' => '3550308',
                'type' => 'string',
                'description' => 'Código da cidade',
                'group' => 'nfe',
                'is_public' => false,
            ],
        ];

        foreach ($defaultSettings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key'], 'group' => $setting['group']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SystemSetting::where('group', 'nfe')->delete();
    }
}; 