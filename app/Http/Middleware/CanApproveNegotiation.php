<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Negotiation;

class CanApproveNegotiation
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $negotiation = $request->route('negotiation');
        
        if (!$negotiation) {
            return response()->json([
                'message' => 'Negotiation not found'
            ], 404);
        }

        $user = $request->user();

        // Check if user has permission to approve negotiations
        if (!$user->hasPermissionTo('approve negotiations')) {
            return response()->json([
                'message' => 'You do not have permission to approve negotiations'
            ], 403);
        }

        // Check if user is not the creator of the negotiation (except for super_admin)
        if ($negotiation->creator_id === $user->id && !$user->hasRole('super_admin')) {
            return response()->json([
                'message' => 'You cannot approve your own negotiation'
            ], 403);
        }

        // Check if negotiation is in the correct status
        if ($negotiation->approval_level !== 'pending_approval') {
            return response()->json([
                'message' => 'This negotiation is not pending approval'
            ], 422);
        }

        return $next($request);
    }
} 