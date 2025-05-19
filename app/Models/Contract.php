<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Jobs\ContractExpirationAlert;
use Carbon\Carbon;

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'contract_number',
        'contractable_id',
        'contractable_type',
        'type',
        'template_id',
        'template_data',
        'start_date',
        'end_date',
        'status',
        'file_path',
        'is_signed',
        'signed_at',
        'signature_ip',
        'signature_token',
        'created_by',
        'autentique_document_id',
        'autentique_data',
        'autentique_webhook_data',
        'signed_file_path',
        'alert_days_before_expiration',
        'last_alert_sent_at',
        'alert_count',
        'billing_frequency',
        'payment_term_days',
        'billing_rule_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_signed' => 'boolean',
        'signed_at' => 'datetime',
        'template_data' => 'array',
        'autentique_data' => 'array',
        'autentique_webhook_data' => 'array',
        'last_alert_sent_at' => 'datetime',
        'alert_count' => 'integer',
    ];

    /**
     * Default values for attributes
     *
     * @var array
     */
    protected $attributes = [
        'alert_days_before_expiration' => 90,
        'alert_count' => 0,
        'billing_frequency' => 'monthly',
        'payment_term_days' => 30,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function ($contract) {
            // Generate a unique contract number if not set
            if (!$contract->contract_number) {
                $contract->contract_number = 'CT-' . date('Y') . '-' . str_pad(static::max('id') + 1, 6, '0', STR_PAD_LEFT);
            }
            
            // Set default alert days if not specified
            if (!$contract->alert_days_before_expiration) {
                $contract->alert_days_before_expiration = 90;
            }
        });

        static::created(function ($contract) {
            // Schedule expiration alerts if end date is set
            if ($contract->end_date) {
                $contract->scheduleExpirationAlert();
            }
        });

        static::updated(function ($contract) {
            // When a contract is signed, update the contractable entity
            if ($contract->isDirty('is_signed') && $contract->is_signed) {
                if ($contract->contractable) {
                    $contract->contractable->update([
                        'has_signed_contract' => true
                    ]);
                }
            }
            
            // Reschedule alerts if end date changed
            if ($contract->isDirty('end_date') && $contract->end_date) {
                $contract->scheduleExpirationAlert();
            }
        });
    }

    /**
     * Get the parent contractable model.
     */
    public function contractable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the template this contract was generated from.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    /**
     * Get the user who created this contract.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if the contract is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && 
               $this->is_signed && 
               (!$this->end_date || $this->end_date->isFuture());
    }

    /**
     * Check if the contract is expired.
     */
    public function isExpired(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    /**
     * Check if the contract is about to expire (within 30 days).
     */
    public function isAboutToExpire(): bool
    {
        return $this->end_date && 
               $this->end_date->isFuture() && 
               $this->end_date->diffInDays(now()) <= 30;
    }

    /**
     * Get the full storage path to the contract file.
     */
    public function getFullPathAttribute(): string
    {
        return storage_path('app/' . $this->file_path);
    }

    /**
     * Sign the contract.
     */
    public function sign(string $ipAddress, string $token = null): self
    {
        $this->update([
            'is_signed' => true,
            'signed_at' => now(),
            'signature_ip' => $ipAddress,
            'signature_token' => $token,
            'status' => 'active',
        ]);

        if ($this->contractable) {
            $this->contractable->update([
                'has_signed_contract' => true
            ]);
        }

        return $this;
    }

    /**
     * Scope a query to only include contracts of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include signed contracts.
     */
    public function scopeSigned($query)
    {
        return $query->where('is_signed', true);
    }

    /**
     * Scope a query to only include unsigned contracts.
     */
    public function scopeUnsigned($query)
    {
        return $query->where('is_signed', false);
    }

    /**
     * Scope a query to only include active contracts.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('is_signed', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Scope a query to only include expired contracts.
     */
    public function scopeExpired($query)
    {
        return $query->where('end_date', '<', now());
    }

    /**
     * Scope a query to only include contracts about to expire.
     */
    public function scopeAboutToExpire($query, $days = 30)
    {
        return $query->where('end_date', '>', now())
            ->where('end_date', '<', now()->addDays($days));
    }

    /**
     * Get the approval records for this contract.
     */
    public function approvals()
    {
        return $this->hasMany(ContractApproval::class);
    }

    /**
     * Get the billing rule associated with this contract.
     */
    public function billingRule()
    {
        return $this->belongsTo(BillingRule::class);
    }
    
    /**
     * Schedule an expiration alert for this contract.
     */
    public function scheduleExpirationAlert()
    {
        // Only schedule if contract has an end date
        if (!$this->end_date) {
            return;
        }
        
        // Calculate when to send the first alert (90 days before expiration by default)
        $alertDate = Carbon::parse($this->end_date)->subDays($this->alert_days_before_expiration);
        
        // If the alert date is in the past, don't schedule it
        if ($alertDate->isPast()) {
            return;
        }
        
        // Create a job to send the expiration alert
        ContractExpirationAlert::dispatch($this)
            ->delay($alertDate);
            
        return $this;
    }
    
    /**
     * Send an immediate expiration alert, regardless of the schedule.
     */
    public function sendExpirationAlert()
    {
        dispatch(new ContractExpirationAlert($this));
        
        $this->update([
            'last_alert_sent_at' => now(),
            'alert_count' => $this->alert_count + 1
        ]);
        
        return $this;
    }
    
    /**
     * Send a recurring expiration alert.
     */
    public function sendRecurringExpirationAlert()
    {
        dispatch(new \App\Jobs\RecurringContractExpirationAlert($this));
        
        $this->update([
            'last_alert_sent_at' => now(),
            'alert_count' => $this->alert_count + 1
        ]);
        
        return $this;
    }
    
    /**
     * Schedule the next recurring alert.
     */
    public function scheduleNextRecurringAlert($daysFromNow = 7)
    {
        $nextAlertDate = Carbon::now()->addDays($daysFromNow);
        
        \App\Jobs\RecurringContractExpirationAlert::dispatch($this)
            ->delay($nextAlertDate);
            
        return $this;
    }
    
    /**
     * Get the appropriate recipients for contract alerts.
     */
    public function getAlertRecipients()
    {
        $recipients = collect();
        
        // Add contract creator
        if ($this->created_by) {
            $creator = User::find($this->created_by);
            if ($creator) {
                $recipients->push($creator);
            }
        }
        
        // Add users with commercial role
        $commercialUsers = User::role('commercial')->get();
        $recipients = $recipients->merge($commercialUsers);
        
        // Add contractable entity contacts
        if ($this->contractable) {
            $entity = $this->contractable;
            
            // Add entity admin users based on entity type
            if (strpos($this->contractable_type, 'HealthPlan') !== false) {
                $entityAdmins = User::role('plan_admin')
                    ->where('entity_id', $this->contractable_id)
                    ->get();
                $recipients = $recipients->merge($entityAdmins);
                
                // Also add specific people from the health plan
                if (method_exists($entity, 'getOperationalRepresentativeEmailAttribute') && $entity->operational_representative_email) {
                    $opRep = User::where('email', $entity->operational_representative_email)->first();
                    if ($opRep) {
                        $recipients->push($opRep);
                    }
                }
            } elseif (strpos($this->contractable_type, 'Clinic') !== false) {
                $entityAdmins = User::role('clinic_admin')
                    ->where('entity_id', $this->contractable_id)
                    ->get();
                $recipients = $recipients->merge($entityAdmins);
            }
        }
        
        return $recipients->unique('id');
    }

    /**
     * Get extemporaneous negotiations related to this contract.
     */
    public function extemporaneousNegotiations()
    {
        return $this->hasMany(ExtemporaneousNegotiation::class);
    }
} 