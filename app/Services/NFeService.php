<?php

namespace App\Services;

use App\Models\BillingBatch;
use App\Models\HealthPlan;
use App\Models\Contract;
use App\Models\SystemSetting;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class NFeService
{
    protected $tools;
    protected $config;
    protected $initialized = false;

    public function __construct()
    {
        // Don't initialize immediately to avoid certificate errors
        $this->config = $this->getNFeConfig();
    }

    /**
     * Initialize NFe configuration and tools
     */
    protected function initializeNFe()
    {
        if ($this->initialized) {
            return;
        }

        try {
            $certificatePath = $this->config['certificate_path'];
            $certificatePassword = $this->config['certificate_password'];

            // Check if certificate file exists
            if (!Storage::exists($certificatePath)) {
                Log::warning('NFe certificate not found: ' . $certificatePath);
                throw new \Exception('Certificado não encontrado no caminho: ' . $certificatePath);
            }

            $certificate = Storage::get($certificatePath);
            
            if (empty($certificate)) {
                Log::warning('NFe certificate file is empty: ' . $certificatePath);
                throw new \Exception('Arquivo do certificado está vazio: ' . $certificatePath);
            }

            // Check if this is a test certificate (for development)
            if (strpos($certificate, 'CERTIFICADO DE TESTE') !== false) {
                Log::info('Using test certificate for development');
                $this->initialized = true;
                return;
            }

            // Try to read the actual certificate
            try {
                $certificate = Certificate::readPfx($certificate, $certificatePassword);
                $this->tools = new Tools(json_encode($this->config), $certificate);
                $this->initialized = true;
            } catch (\Exception $e) {
                Log::error('Error reading certificate: ' . $e->getMessage());
                throw new \Exception('Erro ao ler certificado: ' . $e->getMessage());
            }

        } catch (\Exception $e) {
            Log::error('Error initializing NFe: ' . $e->getMessage());
            throw new \Exception('Erro ao inicializar NFe: ' . $e->getMessage());
        }
    }

    /**
     * Get NFe configuration from database
     */
    protected function getNFeConfig()
    {
        $settings = SystemSetting::where('group', 'nfe')->get()->keyBy('key');
        
        return [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => (int)($settings->get('nfe.environment', 2)?->value ?? 2), // 1-Produção, 2-Homologação
            'razaosocial' => $settings->get('nfe.company_name')?->value ?? 'Empresa Padrão',
            'cnpj' => $settings->get('nfe.cnpj')?->value ?? '00000000000000',
            'siglaUF' => $settings->get('nfe.state')?->value ?? 'SP',
            'schemes' => $settings->get('nfe.schemes')?->value ?? 'PL_009_V4',
            'versao' => $settings->get('nfe.version')?->value ?? '4.00',
            'tokenIBPT' => $settings->get('nfe.ibpt_token')?->value ?? '',
            'CSC' => $settings->get('nfe.csc')?->value ?? '',
            'CSCid' => $settings->get('nfe.csc_id')?->value ?? '',
            'certificate_path' => $settings->get('nfe.certificate_path')?->value ?? 'certificates/certificate.pfx',
            'certificate_password' => $settings->get('nfe.certificate_password')?->value ?? '',
            'address' => [
                'street' => $settings->get('nfe.address.street')?->value ?? '',
                'number' => $settings->get('nfe.address.number')?->value ?? '',
                'district' => $settings->get('nfe.address.district')?->value ?? '',
                'city' => $settings->get('nfe.address.city')?->value ?? '',
                'zipcode' => $settings->get('nfe.address.zipcode')?->value ?? '',
            ],
            'ie' => $settings->get('nfe.ie')?->value ?? '',
            'crt' => $settings->get('nfe.crt')?->value ?? '1',
            'city_code' => $settings->get('nfe.city_code')?->value ?? '3550308',
        ];
    }

    /**
     * Reload configuration from database
     */
    public function reloadConfig()
    {
        $this->config = $this->getNFeConfig();
        $this->initialized = false; // Reset initialization flag
        $this->initializeNFe();
    }

    /**
     * Get current configuration
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig($newConfig)
    {
        foreach ($newConfig as $key => $value) {
            if (strpos($key, 'nfe.') === 0) {
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
        }

        // Reload configuration
        $this->reloadConfig();
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

    /**
     * Check if NFe is properly configured
     */
    public function isConfigured()
    {
        try {
            $this->initializeNFe();
            return $this->initialized;
        } catch (\Exception $e) {
            Log::warning('NFe não está configurada corretamente: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate NFe for a billing batch
     */
    public function generateNFe($data)
    {
        try {
            $this->initializeNFe();
            
            if (!$this->initialized) {
                throw new \Exception('NFe não está configurada corretamente');
            }

            $batch = BillingBatch::find($data['billing_batch_id']);
            if (!$batch) {
                throw new \Exception('Lote de faturamento não encontrado');
            }

            $nfeNumber = $this->generateNFeNumber();
            $cNF = $this->generateCNF($nfeNumber);

            $nfe = new Make();
            $nfe->taginfNFe((object)$this->getNFeInfo($batch, $nfeNumber));
            $nfe->tagide((object)$this->getNFeIde($batch, $nfeNumber, $cNF));
            $nfe->tagemit((object)$this->getNFeEmit());
            $nfe->tagdest((object)$this->getNFeDest($batch));
            foreach ($this->getNFeItems($batch) as $item) {
                $nfe->tagprod((object)$item);
            }
            $nfe->tagICMSTot((object)$this->getNFeTotal($batch));
            $nfe->tagtransp((object)$this->getNFeTransp());
            $nfe->tagpag((object)$this->getNFePayment($batch));

            $errors = $nfe->getErrors();
            if (!empty($errors)) {
                Log::error('Erros ao gerar NFe: ' . print_r($errors, true));
                return [
                    'success' => false,
                    'error' => 'Erros ao gerar NFe: ' . implode('; ', $errors)
                ];
            }

            $xml = '';
            try {
                $xml = $nfe->getXML();
            } catch (\Exception $e) {
                Log::error('Error generating NFe: ' . $e->getMessage(), [
                    'error' => $e->getMessage(),
                    'errors' => $nfe->getErrors(),
                ]);
                return [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }

            // Extract NFe key from XML
            $nfeKey = $nfe->getChave();
            
            if (!$nfeKey) {
                Log::error('Could not extract NFe key from XML');
                return [
                    'success' => false,
                    'error' => 'Não foi possível extrair a chave da NFe do XML'
                ];
            }

            // $this->tools->sefazEnviaLote([$xml]);

            return [
                'success' => true,
                'nfe_number' => $nfeNumber,
                'nfe_key' => $nfeKey,
                'xml_path' => $xml,
                'status' => 'issued',
                'protocol' => null,
                'authorization_date' => now()
            ];
        } catch (\Exception $e) {
            Log::error('Error generating NFe: ' . $e->getMessage(), [
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate NFe number
     */
    protected function generateNFeNumber()
    {
        // Generate a number that fits in 9 digits
        // Use current date (8 digits) + random number (1 digit)
        $datePart = date('Ymd'); // 8 digits
        $randomPart = rand(1, 9); // 1 digit
        return $datePart . $randomPart; // 9 digits total
    }

    /**
     * Generate NFe key
     */
    protected function generateNFeKey($nfeNumber, $cNF, $tpEmis = '1')
    {
        $uf = $this->getStateCode();
        $date = date('ym');
        $cnpj = preg_replace('/[^0-9]/', '', $this->config['cnpj']);
        $model = '55';
        $series = '001';
        $number = str_pad($nfeNumber, 9, '0', STR_PAD_LEFT);
        $cNF = str_pad($cNF, 8, '0', STR_PAD_LEFT);
        $tpEmis = (string)$tpEmis;

        // Ensure exact lengths
        if (strlen($cNF) !== 8) {
            Log::error('cNF length incorrect: ' . strlen($cNF) . ' - value: ' . $cNF);
            $cNF = str_pad($cNF, 8, '0', STR_PAD_LEFT);
        }
        if (strlen($tpEmis) !== 1) {
            Log::error('tpEmis length incorrect: ' . strlen($tpEmis) . ' - value: ' . $tpEmis);
            $tpEmis = substr($tpEmis, 0, 1);
        }
        if (strlen($number) !== 9) {
            Log::error('Number length incorrect: ' . strlen($number) . ' - value: ' . $number);
            $number = str_pad($number, 9, '0', STR_PAD_LEFT);
        }

        $key = $uf . $date . $cnpj . $model . $series . $number . $cNF . $tpEmis;

        // Debug logging
        Log::info('NFe Key Debug', [
            'uf' => $uf,
            'date' => $date,
            'cnpj' => $cnpj,
            'model' => $model,
            'series' => $series,
            'number' => $number,
            'cNF' => $cNF,
            'tpEmis' => $tpEmis,
            'key_before_dv' => $key,
            'key_length' => strlen($key),
            'expected_length' => 43
        ]);

        // Verify key length
        if (strlen($key) !== 43) {
            Log::error('Key length is incorrect! Expected 43, got ' . strlen($key));
            Log::error('Key breakdown: UF(' . strlen($uf) . ') + Date(' . strlen($date) . ') + CNPJ(' . strlen($cnpj) . ') + Model(' . strlen($model) . ') + Series(' . strlen($series) . ') + Number(' . strlen($number) . ') + cNF(' . strlen($cNF) . ') + tpEmis(' . strlen($tpEmis) . ') = ' . strlen($key));
        }

        // Calculate check digit
        $sum = 0;
        $weights = [2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5];
        for ($i = 0; $i < 43; $i++) {
            $sum += intval($key[$i]) * $weights[$i];
        }
        $remainder = $sum % 11;
        $checkDigit = ($remainder < 2) ? 0 : (11 - $remainder);

        $finalKey = $key . $checkDigit;
        Log::info('NFe Key Final', [
            'final_key' => $finalKey,
            'check_digit' => $checkDigit,
            'final_length' => strlen($finalKey)
        ]);

        return $finalKey;
    }

    /**
     * Get NFe basic information
     */
    protected function getNFeInfo(BillingBatch $batch, $nfeNumber)
    {
        return [
            'Id' => '', // Let NFePHP generate the ID automatically
            'versao' => '4.00'
        ];
    }

    /**
     * Get NFe identification information
     */
    protected function getNFeIde(BillingBatch $batch, $nfeNumber, $cNF)
    {
        return [
            'cUF' => $this->getStateCode(),
            'cNF' => $cNF,
            'natOp' => 'PRESTAÇÃO DE SERVIÇOS MÉDICOS',
            'mod' => '55',
            'serie' => '001',
            'nNF' => str_pad($nfeNumber, 9, '0', STR_PAD_LEFT),
            'dhEmi' => date('Y-m-d\TH:i:sP'),
            'tpNF' => '1',
            'idDest' => '1',
            'cMunFG' => $this->getCityCode(),
            'tpImp' => '1',
            'tpEmis' => '1',
            'cDV' => '', // Let NFePHP calculate the check digit
            'tpAmb' => $this->config['tpAmb'],
            'finNFe' => '1',
            'indFinal' => '1',
            'indPres' => '1',
            'procEmi' => '0',
            'verProc' => '1.0'
        ];
    }

    /**
     * Get NFe emitter information
     */
    protected function getNFeEmit()
    {
        return [
            'CNPJ' => $this->config['cnpj'],
            'xNome' => $this->config['razaosocial'],
            'enderEmit' => [
                'xLgr' => $this->config['address']['street'],
                'nro' => $this->config['address']['number'],
                'xBairro' => $this->config['address']['district'],
                'cMun' => $this->getCityCode(),
                'xMun' => $this->config['address']['city'],
                'UF' => $this->config['siglaUF'],
                'CEP' => $this->config['address']['zipcode'],
                'cPais' => '1058',
                'xPais' => 'BRASIL'
            ],
            'IE' => $this->config['ie'],
            'CRT' => $this->config['crt']
        ];
    }

    /**
     * Get NFe recipient information
     */
    protected function getNFeDest(BillingBatch $batch)
    {
        $healthPlan = HealthPlan::findOrFail($batch->entity_id);

        return [
            'CNPJ' => $healthPlan->cnpj,
            'xNome' => $healthPlan->name,
            'enderDest' => [
                'xLgr' => $healthPlan->address_street,
                'nro' => $healthPlan->address_number,
                'xBairro' => $healthPlan->address_district,
                'cMun' => $healthPlan->address_city_code,
                'xMun' => $healthPlan->address_city,
                'UF' => $healthPlan->address_state,
                'CEP' => $healthPlan->address_zipcode,
                'cPais' => '1058',
                'xPais' => 'BRASIL'
            ],
            'indIEDest' => '1',
            'IE' => $healthPlan->ie
        ];
    }

    /**
     * Get NFe items information
     */
    protected function getNFeItems(BillingBatch $batch)
    {
        $items = [];
        $nItem = 1;

        foreach ($batch->items as $item) {
            $valor = (float)($item->total_amount ?? $item->unit_price ?? 0.01); // nunca zero
            $descricao = trim($item->description ?? $item->tuss_description ?? 'Serviço médico');
            $codigo = trim($item->tuss_code ?? '00000001'); // nunca vazio
            $ncm = preg_match('/^\d{8}$/', $item->ncm ?? '') ? $item->ncm : '99999999'; // 8 dígitos
            $cfop = $item->cfop ?? '5933'; // padrão para serviço
            $unidade = $item->unit ?? 'UN'; // unidade padrão
            $quantidade = (float)($item->quantity ?? 1.0000); // nunca zero

            $items[] = [
                'nItem'     => $nItem++,
                'cProd'     => (string)$codigo,
                'cEAN'      => '', // ou 'SEM GTIN'
                'xProd'     => (string)$descricao,
                'NCM'       => (string)$ncm,
                'CFOP'      => (string)$cfop,
                'uCom'      => (string)$unidade,
                'qCom'      => number_format($quantidade, 4, '.', ''),
                'vUnCom'    => number_format($valor, 2, '.', ''),
                'vProd'     => number_format($valor, 2, '.', ''),
                'cEANTrib'  => '', // ou 'SEM GTIN'
                'uTrib'     => (string)$unidade,
                'qTrib'     => number_format($quantidade, 4, '.', ''),
                'vUnTrib'   => number_format($valor, 2, '.', ''),
                'indTot'    => 1,
            ];
        }

        Log::info('Items: ' . print_r($items, true));

        return $items;
    }

    /**
     * Get NFe total information
     */
    protected function getNFeTotal(BillingBatch $batch)
    {
        return [
            'vBC' => '0.00',
            'vICMS' => '0.00',
            'vProd' => number_format((float)$batch->total_amount, 2, '.', ''),
            'vNF' => number_format((float)$batch->total_amount, 2, '.', ''),
            'vTotTrib' => '0.00'
        ];
    }

    /**
     * Get NFe transport information
     */
    protected function getNFeTransp()
    {
        return [
            'modFrete' => '9'
        ];
    }

    /**
     * Get NFe payment information
     */
    protected function getNFePayment(BillingBatch $batch)
    {
        return [
            'tPag' => '90',
            'vPag' => number_format((float)$batch->total_amount, 2, '.', '')
        ];
    }

    /**
     * Get state code
     */
    protected function getStateCode()
    {
        $states = [
            'AC' => '12', 'AL' => '27', 'AP' => '16', 'AM' => '13', 'BA' => '29',
            'CE' => '23', 'DF' => '53', 'ES' => '32', 'GO' => '52', 'MA' => '21',
            'MT' => '51', 'MS' => '50', 'MG' => '31', 'PA' => '15', 'PB' => '25',
            'PR' => '41', 'PE' => '26', 'PI' => '22', 'RJ' => '33', 'RN' => '24',
            'RS' => '43', 'RO' => '11', 'RR' => '14', 'SC' => '42', 'SP' => '35',
            'SE' => '28', 'TO' => '17'
        ];

        return $states[$this->config['siglaUF']] ?? '35';
    }

    /**
     * Get city code
     */
    protected function getCityCode()
    {
        return $this->config['city_code'];
    }

    /**
     * Cancel NFe
     */
    public function cancelNFe(BillingBatch $batch, $reason = 'Cancelamento solicitado pelo contribuinte')
    {
        try {
            // Verificar se a NFe pode ser cancelada
            if ($batch->nfe_status !== 'issued' && $batch->nfe_status !== 'authorized') {
                throw new \Exception('NFe não pode ser cancelada no status atual');
            }

            // Gerar evento de cancelamento
            $cancelEvent = [
                'nNF' => $batch->nfe_number,
                'serie' => '1',
                'nProt' => $batch->nfe_protocol ?? '000000000000000',
                'xJust' => substr($reason, 0, 255), // Máximo 255 caracteres
            ];

            // Enviar evento de cancelamento para SEFAZ
            $response = $this->tools->sefazCancela(
                $batch->nfe_key,
                $batch->nfe_protocol ?? '000000000000000',
                $reason
            );

            // Verificar resposta da SEFAZ
            if ($response['status'] === 'OK') {
                // Atualizar status da NFe
                $batch->update([
                    'nfe_status' => 'cancelled',
                    'nfe_cancellation_date' => now(),
                    'nfe_cancellation_reason' => $reason,
                    'nfe_cancellation_protocol' => $response['protocol'] ?? null,
                ]);

                return [
                    'success' => true,
                    'message' => 'NFe cancelada com sucesso',
                    'protocol' => $response['protocol'] ?? null,
                ];
            } else {
                throw new \Exception('Erro na SEFAZ: ' . ($response['message'] ?? 'Erro desconhecido'));
            }

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar NFe: ' . $e->getMessage(), [
                'batch_id' => $batch->id,
                'nfe_number' => $batch->nfe_number,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel NFe by substitution (for duplicate NFes)
     * Used when there are 2 NFes representing the same operation
     * One normal and one in contingency
     */
    public function cancelNFeBySubstitution(BillingBatch $batch, $substituteNFeKey, $reason = 'Cancelamento por substituição - NFe duplicada')
    {
        try {
            // Verificar se a NFe pode ser cancelada
            if ($batch->nfe_status !== 'issued' && $batch->nfe_status !== 'authorized') {
                throw new \Exception('NFe não pode ser cancelada no status atual');
            }

            // Verificar se a chave da NFe substituta é válida
            if (strlen($substituteNFeKey) !== 44) {
                throw new \Exception('Chave da NFe substituta inválida');
            }

            // Verificar se a NFe substituta existe no banco
            $substituteNFe = BillingBatch::where('nfe_key', $substituteNFeKey)
                ->where('nfe_status', 'authorized')
                ->first();

            if (!$substituteNFe) {
                throw new \Exception('NFe substituta não encontrada ou não autorizada');
            }

            // Verificar se a NFe substituta não foi emitida há mais de 168 horas (7 dias)
            $emissionDate = $substituteNFe->nfe_authorization_date ?? $substituteNFe->created_at;
            $hoursDiff = now()->diffInHours($emissionDate);
            
            if ($hoursDiff > 168) {
                throw new \Exception('NFe substituta foi emitida há mais de 168 horas (7 dias)');
            }

            // Verificar se os valores são compatíveis (com tolerância de 1%)
            $valueDiff = abs($batch->total_amount - $substituteNFe->total_amount);
            $tolerance = $batch->total_amount * 0.01; // 1% de tolerância
            
            if ($valueDiff > $tolerance) {
                throw new \Exception('Valor da NFe substituta difere do valor da NFe a ser cancelada');
            }

            // Verificar se o destinatário é o mesmo
            if ($batch->health_plan_id !== $substituteNFe->health_plan_id) {
                throw new \Exception('Destinatário da NFe substituta difere do destinatário da NFe a ser cancelada');
            }

            // Enviar evento de cancelamento por substituição para SEFAZ
            $response = $this->tools->sefazCancelaPorSubstituicao(
                $batch->nfe_key,
                $reason,
                $batch->nfe_protocol ?? '000000000000000',
                $substituteNFeKey,
                config('app.name', 'Sistema Médico') // versão do aplicativo
            );

            // Verificar resposta da SEFAZ
            if ($response['status'] === 'OK') {
                // Atualizar status da NFe
                $batch->update([
                    'nfe_status' => 'cancelled_by_substitution',
                    'nfe_cancellation_date' => now(),
                    'nfe_cancellation_reason' => $reason,
                    'nfe_cancellation_protocol' => $response['protocol'] ?? null,
                    'nfe_substitute_key' => $substituteNFeKey,
                ]);

                return [
                    'success' => true,
                    'message' => 'NFe cancelada por substituição com sucesso',
                    'protocol' => $response['protocol'] ?? null,
                    'substitute_nfe_key' => $substituteNFeKey,
                ];
            } else {
                throw new \Exception('Erro na SEFAZ: ' . ($response['message'] ?? 'Erro desconhecido'));
            }

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar NFe por substituição: ' . $e->getMessage(), [
                'batch_id' => $batch->id,
                'nfe_number' => $batch->nfe_number,
                'substitute_nfe_key' => $substituteNFeKey,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate a random 8-digit CNF (Código Numérico da NF-e)
     * This CNF must be different from the NNF (Número da NF-e).
     */
    protected function generateCNF($nNF) {
        do {
            $cNF = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while ($cNF == $nNF);
        return $cNF;
    }

    /**
     * Extract NFe key from XML string.
     * This method is needed because the NFePHP library generates the key in the XML.
     * We need to parse the XML to find it.
     */
    protected function extractNFeKeyFromXML($xml)
    {
        $xml = simplexml_load_string($xml);
        if ($xml === false) {
            Log::error('Failed to parse XML for key extraction.');
            return null;
        }

        // Look for the taginfNFe and its Id attribute
        $nfeId = $xml->taginfNFe->Id;
        if (empty($nfeId)) {
            Log::error('NFe Id not found in XML.');
            return null;
        }

        // The key is the last part of the Id, after "NFe"
        $key = substr($nfeId, 3); // Remove "NFe" prefix
        return $key;
    }

    /**
     * Create NFe draft without sending to SEFAZ
     */
    public function createNFeDraft($data)
    {
        try {
            Log::info('Creating NFe draft', ['data' => $data]);
            
            $billingBatch = BillingBatch::findOrFail($data['billing_batch_id']);
            
            // Generate NFe number and key
            $nNF = $this->generateNFeNumber();
            $cNF = $this->generateCNF($nNF);
            $nfeKey = $this->generateNFeKey($nNF, $cNF);
            
            // Create XML content
            $xml = $this->createNFeXML($billingBatch, $nNF, $cNF, $nfeKey);
            
            // Save XML to storage
            $xmlPath = 'nfe/xml/' . date('Y/m/') . 'nfe_' . $nNF . '.xml';
            Storage::put($xmlPath, $xml);
            
            Log::info('NFe draft created successfully', [
                'nfe_number' => $nNF,
                'nfe_key' => $nfeKey,
                'xml_path' => $xmlPath
            ]);
            
            return [
                'success' => true,
                'nfe_number' => $nNF,
                'nfe_key' => $nfeKey,
                'xml_path' => $xmlPath,
                'status' => 'pending'
            ];
            
        } catch (\Exception $e) {
            Log::error('Error creating NFe draft', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Erro ao criar rascunho da NFe: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send NFe to SEFAZ for authorization
     */
    public function sendToSefaz(BillingBatch $nfe)
    {
        try {
            Log::info('Sending NFe to SEFAZ', ['nfe_id' => $nfe->id, 'nfe_number' => $nfe->nfe_number]);
            
            // Initialize NFe tools
            $this->initializeNFe();
            
            if (!$this->initialized) {
                throw new \Exception('NFe não foi inicializada corretamente');
            }
            
            // Read XML content
            $xmlContent = Storage::get($nfe->nfe_xml);
            if (!$xmlContent) {
                throw new \Exception('XML da NFe não encontrado');
            }
            
            // Send to SEFAZ
            $response = $this->tools->sefazEnviaLote([$xmlContent], $nfe->nfe_number);
            
            if ($response['status'] === 'ok') {
                // Extract protocol from response
                $protocol = $this->extractProtocolFromResponse($response);
                $authorizationDate = now();
                
                Log::info('NFe sent to SEFAZ successfully', [
                    'nfe_id' => $nfe->id,
                    'protocol' => $protocol,
                    'authorization_date' => $authorizationDate
                ]);
                
                return [
                    'success' => true,
                    'status' => 'authorized',
                    'protocol' => $protocol,
                    'authorization_date' => $authorizationDate
                ];
            } else {
                $errorMessage = $response['message'] ?? 'Erro desconhecido ao enviar para SEFAZ';
                Log::error('Error sending NFe to SEFAZ', [
                    'nfe_id' => $nfe->id,
                    'error' => $errorMessage,
                    'response' => $response
                ]);
                
                return [
                    'success' => false,
                    'error' => $errorMessage
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('Error sending NFe to SEFAZ', [
                'nfe_id' => $nfe->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Erro ao enviar NFe para SEFAZ: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create NFe XML content
     */
    protected function createNFeXML(BillingBatch $batch, $nNF, $cNF, $nfeKey)
    {
        $nfe = new Make();
        
        // Add NFe information
        $nfe->tagide((object) $this->getNFeIde($batch, $nNF, $cNF));
        $nfe->tagemit((object) $this->getNFeEmit());
        $nfe->tagdest((object) $this->getNFeDest($batch));
        // $nfe->tagdet($this->getNFeItems($batch));
        // $nfe->tagtotal($this->getNFeTotal($batch));
        $nfe->tagtransp((object) $this->getNFeTransp());
        $nfe->tagpag((object) $this->getNFePayment($batch));
        
        // Generate XML
        $xml = $nfe->getXML();
        
        return $xml;
    }

    /**
     * Extract protocol from SEFAZ response
     */
    protected function extractProtocolFromResponse($response)
    {
        // This is a simplified implementation
        // In a real scenario, you would parse the XML response from SEFAZ
        return 'PROTOCOLO_' . date('YmdHis') . '_' . rand(1000, 9999);
    }
} 