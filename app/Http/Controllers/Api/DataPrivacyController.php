<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataConsent;
use App\Models\DataExportRequest;
use App\Models\DataDeletionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DataPrivacyController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get user's consent records
     */
    public function getConsents(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $consents = DataConsent::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
                
            return response()->json([
                'success' => true,
                'data' => $consents
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving consents: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve consent information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record a new user consent
     */
    public function storeConsent(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'consent_type' => 'required|string|in:data_processing,marketing,third_party,cookies,health_data',
                'consent_given' => 'required|boolean',
                'entity_type' => 'nullable|string',
                'entity_id' => 'nullable|required_with:entity_type|integer',
                'consent_text' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            
            // Revoke previous consents of same type
            $previousConsents = DataConsent::where('user_id', $user->id)
                ->where('consent_type', $request->consent_type)
                ->where('entity_type', $request->entity_type)
                ->where('entity_id', $request->entity_id)
                ->whereNull('revoked_at')
                ->get();
                
            foreach ($previousConsents as $consent) {
                $consent->revoked_at = now();
                $consent->save();
            }
            
            // Create new consent record
            $consent = new DataConsent([
                'user_id' => $user->id,
                'consent_type' => $request->consent_type,
                'consent_given' => $request->consent_given,
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'consent_text' => $request->consent_text,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'consented_at' => $request->consent_given ? now() : null,
            ]);
            
            $consent->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Consent recorded successfully',
                'data' => $consent
            ]);
        } catch (\Exception $e) {
            Log::error('Error recording consent: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to record consent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revoke a specific consent
     */
    public function revokeConsent(int $id): JsonResponse
    {
        try {
            $user = Auth::user();
            
            $consent = DataConsent::where('user_id', $user->id)
                ->where('id', $id)
                ->first();
                
            if (!$consent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Consent not found'
                ], 404);
            }
            
            $consent->revoked_at = now();
            $consent->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Consent revoked successfully',
                'data' => $consent
            ]);
        } catch (\Exception $e) {
            Log::error('Error revoking consent: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke consent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request data export (LGPD right to access)
     */
    public function requestDataExport(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Check for existing pending requests
            $pendingRequest = DataExportRequest::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->first();
                
            if ($pendingRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending data export request',
                    'data' => [
                        'request_id' => $pendingRequest->id,
                        'requested_at' => $pendingRequest->created_at,
                        'status' => $pendingRequest->status
                    ]
                ], 422);
            }
            
            // Create new request
            $exportRequest = new DataExportRequest([
                'user_id' => $user->id,
                'status' => 'pending',
                'requested_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
            
            $exportRequest->save();
            
            // Queue the export job (in a real implementation)
            // Export job will run in background and notify user when ready
            
            return response()->json([
                'success' => true,
                'message' => 'Data export request received. You will be notified when your data is ready for download.',
                'data' => [
                    'request_id' => $exportRequest->id,
                    'requested_at' => $exportRequest->created_at
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing data export request: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to process data export request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request account deletion (LGPD right to erasure)
     */
    public function requestAccountDeletion(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'confirmation' => 'required|string|in:DELETE ACCOUNT',
                'reason' => 'nullable|string',
                'password' => 'required|string'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid confirmation',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $user = Auth::user();
            
            // Verify password
            if (!password_verify($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password verification failed'
                ], 401);
            }
            
            // Check for pending requests
            $pendingRequest = DataDeletionRequest::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->first();
                
            if ($pendingRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending account deletion request',
                    'data' => [
                        'request_id' => $pendingRequest->id,
                        'requested_at' => $pendingRequest->created_at,
                        'status' => $pendingRequest->status
                    ]
                ], 422);
            }
            
            // Create deletion request
            $deletionRequest = new DataDeletionRequest([
                'user_id' => $user->id,
                'reason' => $request->reason,
                'status' => 'pending',
                'requested_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
            
            $deletionRequest->save();
            
            // Notify admins about deletion request
            
            return response()->json([
                'success' => true,
                'message' => 'Account deletion request submitted. Our team will process your request within 5 business days.',
                'data' => [
                    'request_id' => $deletionRequest->id,
                    'requested_at' => $deletionRequest->requested_at
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error requesting account deletion: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit account deletion request',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get privacy policy and terms information
     */
    public function getPrivacyInfo(): JsonResponse
    {
        try {
            $privacyInfo = [
                'privacy_policy' => [
                    'version' => '1.2',
                    'updated_at' => '2023-08-15',
                    'url' => config('app.url') . '/privacy-policy'
                ],
                'terms_of_service' => [
                    'version' => '1.1',
                    'updated_at' => '2023-06-20',
                    'url' => config('app.url') . '/terms'
                ],
                'data_controller' => [
                    'name' => 'Medlar Soluções em Saúde',
                    'email' => 'dpo@medlar.com.br',
                    'phone' => '+55 (00) 0000-0000'
                ],
                'dpo_contact' => 'privacy@medlar.com.br'
            ];
            
            return response()->json([
                'success' => true,
                'data' => $privacyInfo
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving privacy information: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve privacy information',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 