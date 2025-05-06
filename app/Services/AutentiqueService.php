<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Contract;
use App\Models\User;
use App\Notifications\ContractSignatureRequestedNotification;
use App\Notifications\ContractSignedNotification;

class AutentiqueService
{
    protected $apiUrl;
    protected $apiToken;
    
    public function __construct()
    {
        $this->apiUrl = config('services.autentique.url', 'https://api.autentique.com.br/v2/graphql');
        $this->apiToken = config('services.autentique.token');
    }
    
    /**
     * Send a contract to Autentique for digital signature
     *
     * @param Contract $contract
     * @param array $signers Array of signers with name, email, and CPF
     * @return array
     */
    public function sendContractForSignature(Contract $contract, array $signers)
    {
        try {
            // Get contract PDF file
            $pdfContent = Storage::get($contract->file_path);
            
            // Prepare signers for Autentique
            $autentiqueSigners = [];
            foreach ($signers as $signer) {
                $autentiqueSigners[] = [
                    'email' => $signer['email'],
                    'name' => $signer['name'] ?? '',
                    'action' => 'SIGN',
                    'cpf' => $signer['cpf'] ?? null,
                    'birthday' => $signer['birthday'] ?? null,
                ];
            }
            
            // Prepare GraphQL mutation
            $query = 'mutation CreateDocumentMutation($document: DocumentInput!, $signers: [SignerInput!]!, $file: Upload!) {
                createDocument(document: $document, signers: $signers, file: $file) {
                    id
                    name
                    created_at
                    signatures {
                        public_id
                        name
                        email
                        created_at
                        action {
                            name
                        }
                        link {
                            short_link
                        }
                        user {
                            id
                            name
                            email
                        }
                    }
                }
            }';
            
            $variables = [
                'document' => [
                    'name' => "Contrato {$contract->contract_number}",
                ],
                'signers' => $autentiqueSigners,
                'file' => null
            ];
            
            $operations = json_encode([
                'query' => $query,
                'variables' => $variables
            ]);
            
            $map = json_encode(['file' => ['variables.file']]);
            
            // Prepare multipart request
            $response = Http::withToken($this->apiToken)
                ->attach('file', $pdfContent, 'contract.pdf')
                ->post($this->apiUrl, [
                    'operations' => $operations,
                    'map' => $map,
                ]);
            
            $result = $response->json();
            
            if (isset($result['errors'])) {
                Log::error('Autentique API Error', [
                    'errors' => $result['errors'],
                    'contract_id' => $contract->id
                ]);
                
                return [
                    'success' => false,
                    'message' => $result['errors'][0]['message'] ?? 'Error sending contract to Autentique',
                    'data' => null
                ];
            }
            
            $documentData = $result['data']['createDocument'];
            
            // Store Autentique document ID and signature IDs in contract
            $contract->autentique_document_id = $documentData['id'];
            $contract->autentique_data = [
                'document' => $documentData,
                'signatures' => collect($documentData['signatures'])->pluck('public_id')->toArray()
            ];
            $contract->save();
            
            // Log success
            Log::info('Contract sent to Autentique successfully', [
                'contract_id' => $contract->id,
                'autentique_document_id' => $documentData['id']
            ]);
            
            return [
                'success' => true,
                'message' => 'Contract sent for signature successfully',
                'data' => $documentData
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to send contract to Autentique', [
                'exception' => $e->getMessage(),
                'contract_id' => $contract->id
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to send contract to Autentique: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Get document details from Autentique
     *
     * @param string $documentId
     * @return array
     */
    public function getDocumentDetails($documentId)
    {
        try {
            $query = 'query {
                document(id: "' . $documentId . '") {
                    id
                    name
                    refusable
                    sortable
                    created_at
                    files {
                        original
                        signed
                    }
                    signatures {
                        public_id
                        name
                        email
                        created_at
                        action {
                            name
                        }
                        link {
                            short_link
                        }
                        user {
                            id
                            name
                            email
                        }
                        email_events {
                            sent
                            opened
                            delivered
                            refused
                            reason
                        }
                        viewed {
                            ip
                            port
                            reason
                            created_at
                            geolocation {
                                country
                                countryISO
                                state
                                stateISO
                                city
                                zipcode
                                latitude
                                longitude
                            }
                        }
                        signed {
                            ip
                            port
                            reason
                            created_at
                            geolocation {
                                country
                                countryISO
                                state
                                stateISO
                                city
                                zipcode
                                latitude
                                longitude
                            }
                        }
                        rejected {
                            ip
                            port
                            reason
                            created_at
                            geolocation {
                                country
                                countryISO
                                state
                                stateISO
                                city
                                zipcode
                                latitude
                                longitude
                            }
                        }
                    }
                }
            }';
            
            $response = Http::withToken($this->apiToken)
                ->post($this->apiUrl, [
                    'query' => $query
                ]);
            
            $result = $response->json();
            
            if (isset($result['errors'])) {
                Log::error('Autentique API Error when fetching document', [
                    'errors' => $result['errors'],
                    'document_id' => $documentId
                ]);
                
                return [
                    'success' => false,
                    'message' => $result['errors'][0]['message'] ?? 'Error fetching document from Autentique',
                    'data' => null
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Document details retrieved successfully',
                'data' => $result['data']['document']
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch document from Autentique', [
                'exception' => $e->getMessage(),
                'document_id' => $documentId
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to fetch document from Autentique: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Resend signature requests for specific signers
     *
     * @param array $signatureIds
     * @return array
     */
    public function resendSignatures(array $signatureIds)
    {
        try {
            $query = 'mutation {
                resendSignatures(public_ids: ["' . implode('","', $signatureIds) . '"])
            }';
            
            $response = Http::withToken($this->apiToken)
                ->post($this->apiUrl, [
                    'query' => $query
                ]);
            
            $result = $response->json();
            
            if (isset($result['errors'])) {
                Log::error('Autentique API Error when resending signatures', [
                    'errors' => $result['errors'],
                    'signature_ids' => $signatureIds
                ]);
                
                return [
                    'success' => false,
                    'message' => $result['errors'][0]['message'] ?? 'Error resending signature requests',
                    'data' => null
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Signature requests resent successfully',
                'data' => $result['data']['resendSignatures']
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to resend signature requests', [
                'exception' => $e->getMessage(),
                'signature_ids' => $signatureIds
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to resend signature requests: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * Process webhook data received from Autentique
     *
     * @param array $data
     * @return void
     */
    public function processWebhook($data)
    {
        try {
            // First, determine what type of event this is from the data
            $documentId = $data['documento']['uuid'] ?? null;
            
            if (!$documentId) {
                Log::error('Invalid webhook data: Missing document ID', ['data' => $data]);
                return;
            }
            
            // Find the contract with this Autentique document ID
            $contract = Contract::where('autentique_document_id', $documentId)->first();
            
            if (!$contract) {
                Log::error('Contract not found for Autentique document', [
                    'autentique_document_id' => $documentId
                ]);
                return;
            }
            
            // Store the webhook data for reference
            $contract->autentique_webhook_data = $data;
            
            // Check if all parties have signed
            $allSigned = true;
            $parties = $data['partes'] ?? [];
            
            foreach ($parties as $party) {
                if (empty($party['assinado'])) {
                    $allSigned = false;
                    break;
                }
            }
            
            // If all signatures are completed, update contract status and download signed document
            if ($allSigned) {
                // Download the signed document
                $signedDocumentUrl = $data['arquivo']['assinado'] ?? null;
                
                if ($signedDocumentUrl) {
                    $signedPdf = Http::get($signedDocumentUrl)->body();
                    $signedFilePath = 'contracts/signed/' . $contract->id . '/' . uniqid() . '.pdf';
                    Storage::put($signedFilePath, $signedPdf);
                    
                    $contract->signed_file_path = $signedFilePath;
                }
                
                // Mark the contract as signed
                $contract->is_signed = true;
                $contract->signed_at = now();
                $contract->status = 'active';
                
                // Notify super admins about completed signature
                $this->notifySuperAdmins($contract);
            }
            
            $contract->save();
            
            Log::info('Processed Autentique webhook successfully', [
                'contract_id' => $contract->id,
                'autentique_document_id' => $documentId,
                'all_signed' => $allSigned
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error processing Autentique webhook', [
                'exception' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }
    
    /**
     * Send notifications to super admin users
     *
     * @param Contract $contract
     * @return void
     */
    protected function notifySuperAdmins(Contract $contract)
    {
        $superAdmins = User::role('super_admin')->get();
        
        foreach ($superAdmins as $admin) {
            $admin->notify(new ContractSignedNotification($contract));
        }
    }
} 