<?php

namespace App\Policies;

use App\Models\User;
use App\Models\BillingBatch;
use Illuminate\Auth\Access\HandlesAuthorization;

class BillingBatchPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any billing batches.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['admin', 'financial_admin', 'plan_admin']);
    }

    /**
     * Determine whether the user can view the billing batch.
     */
    public function view(User $user, BillingBatch $billingBatch): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('financial_admin')) {
            return true;
        }

        if ($user->hasRole('plan_admin')) {
            return $user->entity_type === 'health_plan' && 
                   $user->entity_id === $billingBatch->entity_id;
        }

        return false;
    }

    /**
     * Determine whether the user can create billing batches.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['admin', 'financial_admin']);
    }

    /**
     * Determine whether the user can update the billing batch.
     */
    public function update(User $user, BillingBatch $billingBatch): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('financial_admin')) {
            return true;
        }

        if ($user->hasRole('plan_admin')) {
            return $user->entity_type === 'health_plan' && 
                   $user->entity_id === $billingBatch->entity_id &&
                   $billingBatch->status === 'pending';
        }

        return false;
    }

    /**
     * Determine whether the user can delete the billing batch.
     */
    public function delete(User $user, BillingBatch $billingBatch): bool
    {
        return $user->hasRole(['admin', 'financial_admin']) && 
               $billingBatch->status === 'pending';
    }

    /**
     * Determine whether the user can export billing data.
     */
    public function export(User $user): bool
    {
        return $user->hasRole(['admin', 'financial_admin', 'plan_admin']);
    }

    /**
     * Determine whether the user can manage glosas.
     */
    public function manageGlosas(User $user, BillingBatch $billingBatch): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('financial_admin')) {
            return true;
        }

        if ($user->hasRole('plan_admin')) {
            return $user->entity_type === 'health_plan' && 
                   $user->entity_id === $billingBatch->entity_id;
        }

        return false;
    }

    /**
     * Determine whether the user can upload payment proofs.
     */
    public function uploadPaymentProof(User $user, BillingBatch $billingBatch): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('financial_admin')) {
            return true;
        }

        if ($user->hasRole('plan_admin')) {
            return $user->entity_type === 'health_plan' && 
                   $user->entity_id === $billingBatch->entity_id &&
                   in_array($billingBatch->status, ['pending', 'overdue']);
        }

        return false;
    }
} 