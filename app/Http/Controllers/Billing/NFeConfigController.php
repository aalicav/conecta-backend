<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\NFeService;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NFeConfigController extends Controller
{
    protected $nfeService;

    public function __construct(NFeService $nfeService)
    {
        $this->nfeService = $nfeService;
    }

    /**
     * Get NFe configuration
     */
    public function index()
    {
        try {
            $settings = SystemSetting::where('group', 'nfe')
                ->orderBy('key')
                ->get()
                ->groupBy(function ($item) {
                    return explode('.', $item->key)[1] ?? 'general';
                });

            return response()->json([
                'settings' => $settings,
                'current_config' => $this->nfeService->getConfig(),
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar configurações da NFe: ' . $e->getMessage());
            return response()->json(['message' => 'Erro ao buscar configurações'], 500);
        }
    }

    /**
     * Update NFe configuration
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nfe.environment' => 'nullable|in:1,2',
            'nfe.company_name' => 'nullable|string|max:255',
            'nfe.cnpj' => 'nullable|string|size:14',
            'nfe.state' => 'nullable|string|size:2',
            'nfe.schemes' => 'nullable|string',
            'nfe.version' => 'nullable|string',
            'nfe.ibpt_token' => 'nullable|string',
            'nfe.csc' => 'nullable|string',
            'nfe.csc_id' => 'nullable|string',
            'nfe.certificate_path' => 'nullable|string',
            'nfe.certificate_password' => 'nullable|string',
            'nfe.address.street' => 'nullable|string|max:255',
            'nfe.address.number' => 'nullable|string|max:10',
            'nfe.address.district' => 'nullable|string|max:255',
            'nfe.address.city' => 'nullable|string|max:255',
            'nfe.address.zipcode' => 'nullable|string|size:9',
            'nfe.ie' => 'nullable|string|max:20',
            'nfe.crt' => 'nullable|in:1,2,3',
            'nfe.city_code' => 'nullable|string|size:7',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Update configuration
            $this->nfeService->updateConfig($request->all());

            return response()->json([
                'message' => 'Configurações da NFe atualizadas com sucesso',
                'config' => $this->nfeService->getConfig(),
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar configurações da NFe: ' . $e->getMessage());
            return response()->json(['message' => 'Erro ao atualizar configurações'], 500);
        }
    }

    /**
     * Test NFe configuration
     */
    public function test()
    {
        try {
            $config = $this->nfeService->getConfig();
            
            // Test basic configuration
            $requiredFields = [
                'cnpj' => 'CNPJ',
                'razaosocial' => 'Razão Social',
                'siglaUF' => 'UF',
                'ie' => 'Inscrição Estadual',
            ];

            $missingFields = [];
            foreach ($requiredFields as $field => $label) {
                if (empty($config[$field]) || $config[$field] === '00000000000000') {
                    $missingFields[] = $label;
                }
            }

            if (!empty($missingFields)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Campos obrigatórios não preenchidos: ' . implode(', ', $missingFields),
                ], 400);
            }

            // Test address
            $addressFields = ['street', 'number', 'district', 'city', 'zipcode'];
            $missingAddressFields = [];
            foreach ($addressFields as $field) {
                if (empty($config['address'][$field])) {
                    $missingAddressFields[] = ucfirst($field);
                }
            }

            if (!empty($missingAddressFields)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Campos de endereço obrigatórios não preenchidos: ' . implode(', ', $missingAddressFields),
                ], 400);
            }

            // Test certificate - be more tolerant during development
            $certificatePath = $config['certificate_path'];
            $certificateExists = file_exists(storage_path('app/' . $certificatePath));
            
            if (!$certificateExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certificado não encontrado. Para desenvolvimento, você pode usar um certificado de teste ou configurar um certificado válido.',
                    'certificate_path' => $certificatePath,
                    'development_note' => 'Durante o desenvolvimento, você pode criar um certificado de teste ou usar um certificado válido da SEFAZ.',
                ], 400);
            }

            // Test certificate password
            if (empty($config['certificate_password'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Senha do certificado não configurada',
                ], 400);
            }

            // Try to initialize NFe (this will test the certificate)
            try {
                $isConfigured = $this->nfeService->isConfigured();
                
                if (!$isConfigured) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Erro ao inicializar NFe. Verifique se o certificado é válido e a senha está correta.',
                        'certificate_path' => $certificatePath,
                    ], 400);
                }
            } catch (\Exception $e) {
                // Check if it's a test certificate
                $certificateContent = file_get_contents(storage_path('app/' . $certificatePath));
                if (strpos($certificateContent, 'CERTIFICADO DE TESTE') !== false) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Configuração da NFe está válida (usando certificado de teste para desenvolvimento)',
                        'environment' => $config['tpAmb'] == 1 ? 'Produção' : 'Homologação',
                        'company' => $config['razaosocial'],
                        'cnpj' => $config['cnpj'],
                        'certificate_status' => 'Certificado de Teste (Desenvolvimento)',
                        'note' => 'Para produção, use um certificado válido da SEFAZ.',
                    ]);
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao testar certificado: ' . $e->getMessage(),
                    'certificate_path' => $certificatePath,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Configuração da NFe está válida',
                'environment' => $config['tpAmb'] == 1 ? 'Produção' : 'Homologação',
                'company' => $config['razaosocial'],
                'cnpj' => $config['cnpj'],
                'certificate_status' => 'Válido',
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao testar configuração da NFe: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao testar configuração: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available states
     */
    public function getStates()
    {
        $states = [
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins',
        ];

        return response()->json($states);
    }

    /**
     * Get CRT options
     */
    public function getCrtOptions()
    {
        $crtOptions = [
            '1' => 'Simples Nacional',
            '2' => 'Simples Nacional - excesso de sublimite da receita bruta',
            '3' => 'Regime Normal',
        ];

        return response()->json($crtOptions);
    }

    /**
     * Get environment options
     */
    public function getEnvironmentOptions()
    {
        $environments = [
            '1' => 'Produção',
            '2' => 'Homologação',
        ];

        return response()->json($environments);
    }

    /**
     * Initialize default NFe settings
     */
    public function initializeDefaults()
    {
        try {
            $defaultSettings = [
                'nfe.environment' => '2',
                'nfe.company_name' => 'Empresa Padrão',
                'nfe.cnpj' => '00000000000000',
                'nfe.state' => 'SP',
                'nfe.schemes' => 'PL_009_V4',
                'nfe.version' => '4.00',
                'nfe.ibpt_token' => '',
                'nfe.csc' => '',
                'nfe.csc_id' => '',
                'nfe.certificate_path' => 'certificates/certificate.pfx',
                'nfe.certificate_password' => 'teste123',
                'nfe.address.street' => '',
                'nfe.address.number' => '',
                'nfe.address.district' => '',
                'nfe.address.city' => '',
                'nfe.address.zipcode' => '',
                'nfe.ie' => '',
                'nfe.crt' => '1',
                'nfe.city_code' => '3550308',
            ];

            foreach ($defaultSettings as $key => $value) {
                SystemSetting::updateOrCreate(
                    ['key' => $key, 'group' => 'nfe'],
                    [
                        'value' => $value,
                        'type' => 'string',
                        'description' => $this->getSettingDescription($key),
                        'is_public' => false,
                    ]
                );
            }

            // Reload configuration
            $this->nfeService->reloadConfig();

            return response()->json([
                'message' => 'Configurações padrão da NFe inicializadas com sucesso',
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao inicializar configurações padrão da NFe: ' . $e->getMessage());
            return response()->json(['message' => 'Erro ao inicializar configurações'], 500);
        }
    }

    /**
     * Get setting description
     */
    protected function getSettingDescription($key)
    {
        $descriptions = [
            'nfe.environment' => 'Ambiente da NFe (1-Produção, 2-Homologação)',
            'nfe.company_name' => 'Razão Social da empresa',
            'nfe.cnpj' => 'CNPJ da empresa',
            'nfe.state' => 'Sigla do estado (UF)',
            'nfe.schemes' => 'Schemas da NFe',
            'nfe.version' => 'Versão da NFe',
            'nfe.ibpt_token' => 'Token do IBPT',
            'nfe.csc' => 'CSC (Código de Segurança do Contribuinte)',
            'nfe.csc_id' => 'ID do CSC',
            'nfe.certificate_path' => 'Caminho do certificado digital',
            'nfe.certificate_password' => 'Senha do certificado digital',
            'nfe.address.street' => 'Logradouro do endereço',
            'nfe.address.number' => 'Número do endereço',
            'nfe.address.district' => 'Bairro do endereço',
            'nfe.address.city' => 'Cidade do endereço',
            'nfe.address.zipcode' => 'CEP do endereço',
            'nfe.ie' => 'Inscrição Estadual',
            'nfe.crt' => 'Código de Regime Tributário',
            'nfe.city_code' => 'Código da cidade',
        ];

        return $descriptions[$key] ?? 'Configuração da NFe';
    }
} 