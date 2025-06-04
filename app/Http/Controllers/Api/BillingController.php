<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Billing;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use PDF;

class BillingController extends Controller
{
    public function overview(Request $request)
    {
        try {
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $healthPlanId = $request->health_plan_id;

            $query = Transaction::query()
                ->whereBetween('created_at', [$startDate, $endDate]);

            if ($healthPlanId) {
                $query->where('health_plan_id', $healthPlanId);
            }

            $transactions = $query->get();

            // Calculate total revenue
            $totalRevenue = $transactions->sum('amount');

            // Calculate pending payments
            $pendingPayments = $transactions
                ->where('status', 'pending')
                ->sum('amount');

            // Get active subscriptions count
            $activeSubscriptions = DB::table('subscriptions')
                ->where('status', 'active')
                ->when($healthPlanId, function ($query) use ($healthPlanId) {
                    return $query->where('health_plan_id', $healthPlanId);
                })
                ->count();

            // Calculate growth rate
            $previousPeriodStart = (clone $startDate)->subMonth();
            $previousPeriodEnd = (clone $endDate)->subMonth();
            
            $previousRevenue = Transaction::query()
                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->when($healthPlanId, function ($query) use ($healthPlanId) {
                    return $query->where('health_plan_id', $healthPlanId);
                })
                ->sum('amount');

            $growthRate = $previousRevenue > 0 
                ? (($totalRevenue - $previousRevenue) / $previousRevenue) * 100 
                : 0;

            // Get revenue trend
            $revenueTrend = Transaction::query()
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(amount) as value')
                )
                ->whereBetween('created_at', [$startDate, $endDate])
                ->when($healthPlanId, function ($query) use ($healthPlanId) {
                    return $query->where('health_plan_id', $healthPlanId);
                })
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_revenue' => $totalRevenue,
                    'pending_payments' => $pendingPayments,
                    'active_subscriptions' => $activeSubscriptions,
                    'growth_rate' => round($growthRate, 2),
                    'revenue_trend' => $revenueTrend,
                    'clinic_revenue' => $transactions->where('entity_type', 'clinic')->sum('amount'),
                    'professional_revenue' => $transactions->where('entity_type', 'professional')->sum('amount'),
                    'health_plan_revenue' => $transactions->where('entity_type', 'health_plan')->sum('amount'),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching billing overview',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function transactions(Request $request)
    {
        try {
            $query = Transaction::query()
                ->with(['entity', 'subscription'])
                ->when($request->start_date && $request->end_date, function ($query) use ($request) {
                    return $query->whereBetween('created_at', [
                        Carbon::parse($request->start_date),
                        Carbon::parse($request->end_date)
                    ]);
                })
                ->when($request->health_plan_id, function ($query) use ($request) {
                    return $query->where('health_plan_id', $request->health_plan_id);
                })
                ->when($request->status, function ($query) use ($request) {
                    return $query->where('status', $request->status);
                })
                ->when($request->type, function ($query) use ($request) {
                    return $query->where('type', $request->type);
                });

            $transactions = $query->orderBy('created_at', 'desc')->paginate($request->per_page ?? 10);

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generateInvoice(Request $request, $transactionId)
    {
        try {
            $transaction = Transaction::with(['entity', 'subscription'])->findOrFail($transactionId);

            // Generate PDF invoice using a template
            $pdf = PDF::loadView('invoices.template', [
                'transaction' => $transaction,
                'company' => [
                    'name' => config('app.name'),
                    'address' => config('app.address'),
                    'phone' => config('app.phone'),
                    'email' => config('app.email'),
                ]
            ]);

            return $pdf->download("invoice-{$transactionId}.pdf");
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $transactionId)
    {
        try {
            $transaction = Transaction::findOrFail($transactionId);
            
            $request->validate([
                'status' => 'required|in:pending,paid,cancelled,refunded'
            ]);

            $transaction->update([
                'status' => $request->status,
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transaction status updated successfully',
                'data' => $transaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating transaction status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 