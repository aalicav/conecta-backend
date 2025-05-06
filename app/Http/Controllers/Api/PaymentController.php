<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentGloss;
use App\Models\PaymentRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of payments.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Payment::query();

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('payment_type')) {
                $query->where('payment_type', $request->input('payment_type'));
            }

            if ($request->has('payment_method')) {
                $query->where('payment_method', $request->input('payment_method'));
            }

            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            }

            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            }

            if ($request->has('min_amount')) {
                $query->where('amount', '>=', $request->input('min_amount'));
            }

            if ($request->has('max_amount')) {
                $query->where('amount', '<=', $request->input('max_amount'));
            }

            if ($request->has('reference_id')) {
                $query->where('reference_id', 'like', '%' . $request->input('reference_id') . '%');
            }

            // Sort options
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            // Pagination
            $perPage = $request->input('per_page', 15);
            $payments = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $payments,
                'meta' => [
                    'total' => $payments->total(),
                    'per_page' => $payments->perPage(),
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch payments: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified payment.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $payment = Payment::with(['glosses', 'refunds'])->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $payment
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch payment: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'payment_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch payment',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Process a payment.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function process(Request $request, $id)
    {
        try {
            $payment = Payment::findOrFail($id);

            // Check if payment can be processed
            if ($payment->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment cannot be processed. Current status: ' . $payment->status
                ], 422);
            }

            $validated = $request->validate([
                'payment_method' => 'required|string|in:credit_card,debit_card,bank_transfer,pix,cash',
                'payment_gateway' => 'nullable|string',
                'gateway_reference' => 'nullable|string',
                'gateway_response' => 'nullable|array',
                'notes' => 'nullable|string'
            ]);

            DB::beginTransaction();

            // Process the payment
            $payment->process($validated, Auth::id());

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment processed successfully',
                'data' => $payment
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to process payment: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'payment_id' => $id,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process payment',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Apply a gloss to a payment.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyGloss(Request $request, $id)
    {
        try {
            $payment = Payment::findOrFail($id);

            // Check if payment can have a gloss applied
            if (!in_array($payment->status, ['completed', 'partially_refunded'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot apply gloss to payment with status: ' . $payment->status
                ], 422);
            }

            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01|max:' . $payment->total_amount,
                'reason' => 'required|string',
                'gloss_code' => 'nullable|string',
                'is_appealable' => 'boolean',
                'notes' => 'nullable|string'
            ]);

            DB::beginTransaction();

            // Apply the gloss
            $gloss = $payment->applyGloss($validated, Auth::id());

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Gloss applied successfully',
                'data' => [
                    'payment' => $payment,
                    'gloss' => $gloss
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to apply gloss: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'payment_id' => $id,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to apply gloss',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Refund a payment (partially or fully).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function refund(Request $request, $id)
    {
        try {
            $payment = Payment::findOrFail($id);

            // Check if payment can be refunded
            if (!$payment->canBeRefunded()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment cannot be refunded. Current status: ' . $payment->status
                ], 422);
            }

            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01|max:' . $payment->refundable_amount,
                'reason' => 'required|string',
                'gateway_reference' => 'nullable|string',
                'gateway_response' => 'nullable|array',
                'notes' => 'nullable|string'
            ]);

            DB::beginTransaction();

            // Process the refund
            $refund = $payment->refund($validated, Auth::id());

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment refunded successfully',
                'data' => [
                    'payment' => $payment,
                    'refund' => $refund
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to refund payment: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'payment_id' => $id,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to refund payment',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Revert a gloss applied to a payment.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $paymentId
     * @param  int  $glossId
     * @return \Illuminate\Http\JsonResponse
     */
    public function revertGloss(Request $request, $paymentId, $glossId)
    {
        try {
            $payment = Payment::findOrFail($paymentId);
            $gloss = PaymentGloss::findOrFail($glossId);

            // Ensure the gloss belongs to the specified payment
            if ($gloss->payment_id !== $payment->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The specified gloss does not belong to this payment'
                ], 422);
            }

            // Check if gloss can be reverted
            if (!$gloss->canBeReverted()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Gloss cannot be reverted. Current status: ' . $gloss->status
                ], 422);
            }

            $validated = $request->validate([
                'notes' => 'nullable|string'
            ]);

            DB::beginTransaction();

            // Revert the gloss
            $gloss->revert(Auth::id(), $validated['notes'] ?? null);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Gloss reverted successfully',
                'data' => [
                    'payment' => $payment,
                    'gloss' => $gloss
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to revert gloss: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'payment_id' => $paymentId,
                'gloss_id' => $glossId,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to revert gloss',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
} 