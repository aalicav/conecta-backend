<?php

namespace App\Services;

use App\Models\BillingBatch;
use App\Models\HealthPlan;
use App\Models\Contract;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class NFeService
{
    protected $tools;
    protected $config;

    public function __construct()
    {
        $this->initializeNFe();
    }

    /**
     * Initialize NFe configuration and tools
     */
    protected function initializeNFe()
    {
        $this->config = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => config('nfe.environment', 2), // 1-Produção, 2-Homologação
            'razaosocial' => config('nfe.company_name'),
            'cnpj' => config('nfe.cnpj'),
            'siglaUF' => config('nfe.state'),
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
            'tokenIBPT' => config('nfe.ibpt_token'),
            'CSC' => config('nfe.csc'),
            'CSCid' => config('nfe.csc_id'),
        ];

        $certificate = Storage::get(config('nfe.certificate_path'));
        $certificatePassword = config('nfe.certificate_password');

        $certificate = Certificate::readPfx($certificate, $certificatePassword);
        $this->tools = new Tools(json_encode($this->config), $certificate);
    }

    /**
     * Generate NFe for a billing batch
     */
    public function generateNFe(BillingBatch $batch)
    {
        try {
            $nfe = new Make();
            $nfe->taginfNFe($this->getNFeInfo($batch));
            $nfe->tagide($this->getNFeIde($batch));
            $nfe->tagemit($this->getNFeEmit());
            $nfe->tagdest($this->getNFeDest($batch));
            $nfe->tagdet($this->getNFeItems($batch));
            $nfe->tagICMSTot($this->getNFeTotal($batch));
            $nfe->tagtransp($this->getNFeTransp());
            $nfe->tagpag($this->getNFePayment($batch));

            $xml = $nfe->getXML();
            $this->tools->sefazEnviaLote([$xml]);

            // Save NFe information to database
            $batch->update([
                'nfe_number' => $this->tools->getNumeroNFe(),
                'nfe_key' => $this->tools->getChaveNFe(),
                'nfe_xml' => $xml,
                'nfe_status' => 'issued'
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error generating NFe: ' . $e->getMessage(), [
                'batch_id' => $batch->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get NFe basic information
     */
    protected function getNFeInfo(BillingBatch $batch)
    {
        return [
            'Id' => 'NFe' . $this->tools->getChaveNFe(),
            'versao' => '4.00'
        ];
    }

    /**
     * Get NFe identification information
     */
    protected function getNFeIde(BillingBatch $batch)
    {
        return [
            'cUF' => $this->getStateCode(),
            'cNF' => $this->tools->getNumeroNFe(),
            'natOp' => 'PRESTAÇÃO DE SERVIÇOS MÉDICOS',
            'mod' => '55',
            'serie' => '1',
            'nNF' => $this->tools->getNumeroNFe(),
            'dhEmi' => date('Y-m-d\TH:i:sP'),
            'tpNF' => '1',
            'idDest' => '1',
            'cMunFG' => $this->getCityCode(),
            'tpImp' => '1',
            'tpEmis' => '1',
            'cDV' => $this->tools->getDigitoVerificador(),
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
                'xLgr' => config('nfe.address.street'),
                'nro' => config('nfe.address.number'),
                'xBairro' => config('nfe.address.district'),
                'cMun' => $this->getCityCode(),
                'xMun' => config('nfe.address.city'),
                'UF' => $this->config['siglaUF'],
                'CEP' => config('nfe.address.zipcode'),
                'cPais' => '1058',
                'xPais' => 'BRASIL'
            ],
            'IE' => config('nfe.ie'),
            'CRT' => config('nfe.crt', '1')
        ];
    }

    /**
     * Get NFe recipient information
     */
    protected function getNFeDest(BillingBatch $batch)
    {
        $healthPlan = $batch->healthPlan;
        $contract = $batch->contract;

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
            $items[] = [
                'nItem' => $nItem++,
                'prod' => [
                    'cProd' => $item->appointment->procedure->code,
                    'xProd' => $item->appointment->procedure->name,
                    'NCM' => '85149090',
                    'CFOP' => '5933',
                    'uCom' => 'UN',
                    'qCom' => '1.0000',
                    'vUnCom' => number_format($item->amount, 2, '.', ''),
                    'vProd' => number_format($item->amount, 2, '.', ''),
                    'indTot' => '1'
                ],
                'imposto' => [
                    'vTotTrib' => '0.00',
                    'ICMS' => [
                        'ICMS00' => [
                            'orig' => '0',
                            'CST' => '41',
                            'modBC' => '0',
                            'vBC' => '0.00',
                            'pICMS' => '0.00',
                            'vICMS' => '0.00'
                        ]
                    ],
                    'PIS' => [
                        'CST' => '07',
                        'vBC' => '0.00',
                        'pPIS' => '0.00',
                        'vPIS' => '0.00'
                    ],
                    'COFINS' => [
                        'CST' => '07',
                        'vBC' => '0.00',
                        'pCOFINS' => '0.00',
                        'vCOFINS' => '0.00'
                    ]
                ]
            ];
        }

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
            'vProd' => number_format($batch->total_amount, 2, '.', ''),
            'vNF' => number_format($batch->total_amount, 2, '.', ''),
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
            'vPag' => number_format($batch->total_amount, 2, '.', '')
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
        return config('nfe.city_code', '3550308');
    }
} 