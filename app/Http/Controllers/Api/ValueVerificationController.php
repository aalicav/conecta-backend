<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ValueVerification;
use App\Models\BillingItem;
use App\Models\BillingBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;

class ValueVerificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->middleware('auth:api');
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of value verifications.
     */
    public function index(Request $request)
    {
        $query = ValueVerification::with([
            'billingBatch', 
            'billingItem', 
            'appointment', 
            'requester',
            'verifier'
        ])
        ->when($request->status, function ($q, $status) {
            return $q->where('status', $status);
        })
        ->when($request->priority, function ($q, $priority) {
            return $q->where('priority', $priority);
        })
        ->when($request->value_type, function ($q, $type) {
            return $q->where('value_type', $type);
        })
        ->when($request->billing_batch_id, function ($q, $batchId) {
            return $q->where('billing_batch_id', $batchId);
        })
        ->when($request->billing_item_id, function ($q, $itemId) {
            return $q->where('billing_item_id', $itemId);
        })
        ->when($request->overdue, function ($q) {
            return $q->overdue();
        })
        ->when($request->high_priority, function ($q) {
            return $q->highPriority();
        })
        ->when($request->date_from, function ($q, $date) {
            return $q->where('created_at', '>=', $date);
        })
        ->when($request->date_to, function ($q, $date) {
            return $q->where('created_at', '<=', $date);
        });

        $verifications = $query->orderBy('priority', 'desc')
            ->orderBy('due_date', 'asc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => $verifications->items(),
            'meta' => [
                'total' => $verifications->total(),
                'per_page' => $verifications->perPage(),
                'current_page' => $verifications->currentPage(),
                'last_page' => $verifications->lastPage(),
                'statistics' => ValueVerification::getStatistics()
            ]
        ]);
    }

    /**
     * Display the specified value verification.
     */
    public function show(ValueVerification $verification)
    {
        $verification->load([
            'billingBatch', 
            'billingItem', 
            'appointment', 
            'requester',
            'verifier'
        ]);

        return response()->json([
            'data' => $verification
        ]);
    }

    /**
     * Verify a value.
     */
    public function verify(Request $request, ValueVerification $verification)
    {
        $request->validate([
            'verified_value' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'auto_approve' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            // Check if verification can be auto-approved
            if ($request->auto_approve && $verification->canBeAutoApproved()) {
                $verification->autoApprove();
            } else {
                // Manual verification
                $verification->verify(
                    Auth::id(),
                    $request->verified_value,
                    $request->notes
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Valor verificado com sucesso',
                'data' => $verification->load(['billingBatch', 'billingItem', 'appointment'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao verificar valor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a value.
     */
    public function reject(Request $request, ValueVerification $verification)
    {
        $request->validate([
            'notes' => 'required|string|min:10'
        ]);

        try {
            DB::beginTransaction();

            $verification->reject(Auth::id(), $request->notes);

            DB::commit();

            return response()->json([
                'message' => 'Valor rejeitado com sucesso',
                'data' => $verification->load(['billingBatch', 'billingItem', 'appointment'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao rejeitar valor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new value verification for a billing item.
     */
    public function createForBillingItem(Request $request, BillingItem $billingItem)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
            'priority' => 'nullable|in:low,medium,high,critical',
            'due_date' => 'nullable|date|after:today',
            'auto_approve_threshold' => 'nullable|numeric|min:0|max:100'
        ]);

        try {
            DB::beginTransaction();

            $verification = ValueVerification::createFromBillingItem(
                $billingItem,
                $request->reason
            );

            // Override default values if provided
            if ($request->priority) {
                $verification->priority = $request->priority;
            }
            if ($request->due_date) {
                $verification->due_date = $request->due_date;
            }
            if ($request->auto_approve_threshold) {
                $verification->auto_approve_threshold = $request->auto_approve_threshold;
            }

            $verification->save();

            DB::commit();

            return response()->json([
                'message' => 'Verificação de valor criada com sucesso',
                'data' => $verification->load(['billingBatch', 'billingItem', 'appointment'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao criar verificação de valor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process automatic verifications for a billing batch.
     */
    public function processAutoVerifications(BillingBatch $batch)
    {
        try {
            DB::beginTransaction();

            $processed = 0;
            $autoApproved = 0;
            $pending = 0;

            // Get all pending verifications for this batch
            $verifications = $batch->pendingValueVerifications;

            foreach ($verifications as $verification) {
                if ($verification->canBeAutoApproved()) {
                    $verification->autoApprove();
                    $autoApproved++;
                } else {
                    $pending++;
                }
                $processed++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Processamento automático concluído',
                'data' => [
                    'processed' => $processed,
                    'auto_approved' => $autoApproved,
                    'pending' => $pending,
                    'batch_id' => $batch->id
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao processar verificações automáticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get value verification statistics.
     */
    public function statistics(Request $request)
    {
        try {
            $query = ValueVerification::query();

            // Apply filters
            if ($request->billing_batch_id) {
                $query->where('billing_batch_id', $request->billing_batch_id);
            }
            if ($request->date_from) {
                $query->where('created_at', '>=', $request->date_from);
            }
            if ($request->date_to) {
                $query->where('created_at', '<=', $request->date_to);
            }

            $statistics = [
                'total' => $query->count(),
                'pending' => (clone $query)->where('status', ValueVerification::STATUS_PENDING)->count(),
                'verified' => (clone $query)->where('status', ValueVerification::STATUS_VERIFIED)->count(),
                'rejected' => (clone $query)->where('status', ValueVerification::STATUS_REJECTED)->count(),
                'auto_approved' => (clone $query)->where('status', ValueVerification::STATUS_AUTO_APPROVED)->count(),
                'overdue' => (clone $query)->overdue()->count(),
                'high_priority' => (clone $query)->highPriority()->count(),
            ];

            // Get average processing time
            $avgProcessingTime = (clone $query)
                ->whereNotNull('verified_at')
                ->whereNotNull('created_at')
                ->get()
                ->avg(function ($verification) {
                    return $verification->created_at->diffInHours($verification->verified_at);
                });

            $statistics['avg_processing_time_hours'] = round($avgProcessingTime ?? 0, 2);

            // Get value difference statistics
            $valueStats = (clone $query)
                ->whereNotNull('verified_value')
                ->get()
                ->map(function ($verification) {
                    return $verification->getDifferencePercentage();
                });

            $statistics['avg_difference_percentage'] = round($valueStats->avg() ?? 0, 2);
            $statistics['max_difference_percentage'] = round($valueStats->max() ?? 0, 2);

            return response()->json([
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao obter estatísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk verify multiple verifications.
     */
    public function bulkVerify(Request $request)
    {
        $request->validate([
            'verification_ids' => 'required|array|min:1',
            'verification_ids.*' => 'exists:value_verifications,id',
            'verified_value' => 'required|numeric|min:0',
            'notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $verifications = ValueVerification::whereIn('id', $request->verification_ids)
                ->where('status', ValueVerification::STATUS_PENDING)
                ->get();

            $processed = 0;
            $errors = [];

            foreach ($verifications as $verification) {
                try {
                    $verification->verify(
                        Auth::id(),
                        $request->verified_value,
                        $request->notes
                    );
                    $processed++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'verification_id' => $verification->id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'message' => "Processados {$processed} de " . count($request->verification_ids) . " verificações",
                'data' => [
                    'processed' => $processed,
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao processar verificações em lote',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 