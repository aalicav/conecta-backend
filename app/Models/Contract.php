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

    // Status do contrato conforme fluxo do Dr. Italo
    const STATUS_DRAFT = 'draft';                   // Editável pelo comercial
    const STATUS_PENDING_COPO = 'pending_copo';     // Aguardando análise do comitê
    const STATUS_PENDING_LEGAL = 'pending_legal';   // Aguardando análise jurídica
    const STATUS_PENDING_COMMERCIAL = 'pending_commercial'; // Aguardando validação comercial
    const STATUS_PENDING_DIRECTOR = 'pending_director';     // Aguardando direção
    const STATUS_APPROVED = 'approved';             // Aprovado internamente
    const STATUS_PENDING_SIGNATURE = 'pending_signature';   // Aguardando assinaturas
    const STATUS_SIGNED = 'signed';                 // Assinado
    const STATUS_ACTIVE = 'active';                 // Ativo/Operacional
    const STATUS_REJECTED = 'rejected';             // Rejeitado

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'contract_number',
        'negotiation_id',
        'contractable_id',
        'contractable_type',
        'type',
        'template_id',
        'template_data',
        'contract_content',
        'start_date',
        'end_date',
        'status',
        'file_path',
        'is_signed',
        'signed_at',
        'signature_ip',
        'signature_token',
        'created_by',
        'updated_by',
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
        // Campos para o fluxo de aprovação
        'submitted_at',
        'submitted_by',
        'copo_approved_at',
        'copo_approved_by',
        'legal_approved_at',
        'legal_approved_by',
        'commercial_approved_at',
        'commercial_approved_by',
        'director_approved_at',
        'director_approved_by',
        'signature_id',
        'sent_for_signature_at',
        'sent_for_signature_by',
        'rejection_reason',
        'rejected_at',
        'rejected_by',
        'activated_at',
        'activated_by'
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
        // Cast para os novos campos
        'submitted_at' => 'datetime',
        'copo_approved_at' => 'datetime',
        'legal_approved_at' => 'datetime',
        'commercial_approved_at' => 'datetime',
        'director_approved_at' => 'datetime',
        'sent_for_signature_at' => 'datetime',
        'rejected_at' => 'datetime',
        'activated_at' => 'datetime'
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
        'status' => 'draft',
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
            
            // Set created_by if not set
            if (!$contract->created_by && auth()->check()) {
                $contract->created_by = auth()->id();
            }
        });

        static::created(function ($contract) {
            // Schedule expiration alerts if end date is set
            if ($contract->end_date) {
                $contract->scheduleExpirationAlert();
            }
            
            // Registrar log de criação
            ContractLog::create([
                'contract_id' => $contract->id,
                'action' => 'created',
                'status' => $contract->status,
                'user_id' => $contract->created_by,
                'details' => 'Contrato criado'
            ]);
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
            
            // Quando status mudar para ativo, atualizar a negociação associada
            if ($contract->isDirty('status') && $contract->status === self::STATUS_ACTIVE) {
                if ($contract->negotiation) {
                    $contract->negotiation->update([
                        'has_active_contract' => true,
                        'active_contract_id' => $contract->id
                    ]);
                }
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
     * Relação com a negociação
     */
    public function negotiation()
    {
        return $this->belongsTo(Negotiation::class);
    }

    /**
     * Get the user who created this contract.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    
    /**
     * Get the user who submitted this contract for approval.
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
    
    /**
     * Get the user who approved at the copo step.
     */
    public function copoApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'copo_approved_by');
    }
    
    /**
     * Get the user who approved at the legal step.
     */
    public function legalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legal_approved_by');
    }
    
    /**
     * Get the user who approved at the commercial step.
     */
    public function commercialApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commercial_approved_by');
    }
    
    /**
     * Get the user who approved at the director step.
     */
    public function directorApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'director_approved_by');
    }
    
    /**
     * Get the user who sent this contract for signature.
     */
    public function sentForSignatureBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_for_signature_by');
    }
    
    /**
     * Get the user who rejected this contract.
     */
    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
    
    /**
     * Get the user who activated this contract.
     */
    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * Check if the contract is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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
            'status' => self::STATUS_SIGNED,
        ]);

        if ($this->contractable) {
            $this->contractable->update([
                'has_signed_contract' => true
            ]);
        }
        
        // Registrar log de assinatura
        ContractLog::create([
            'contract_id' => $this->id,
            'action' => 'signed',
            'status' => $this->status,
            'user_id' => auth()->check() ? auth()->id() : null,
            'details' => 'Contrato assinado'
        ]);

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
        return $query->where('status', self::STATUS_ACTIVE);
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
     * Scope a query to only include contracts in a specific status.
     */
    public function scopeInStatus($query, $status)
    {
        return $query->where('status', $status);
    }
    
    /**
     * Scope a query to only include draft contracts.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }
    
    /**
     * Scope a query to only include contracts pending approval.
     */
    public function scopePendingApproval($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_COPO,
            self::STATUS_PENDING_LEGAL,
            self::STATUS_PENDING_COMMERCIAL,
            self::STATUS_PENDING_DIRECTOR
        ]);
    }
    
    /**
     * Scope a query to only include approved contracts (but not signed yet).
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
    
    /**
     * Scope a query to only include contracts pending signature.
     */
    public function scopePendingSignature($query)
    {
        return $query->where('status', self::STATUS_PENDING_SIGNATURE);
    }
    
    /**
     * Scope a query to only include rejected contracts.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Get the approval records for this contract.
     */
    public function approvals()
    {
        return $this->hasMany(ContractApproval::class);
    }
    
    /**
     * Get the rejection records for this contract.
     */
    public function rejections()
    {
        return $this->hasMany(ContractRejection::class);
    }
    
    /**
     * Get the signers for this contract.
     */
    public function signers()
    {
        return $this->hasMany(ContractSigner::class);
    }
    
    /**
     * Get the logs for this contract.
     */
    public function logs()
    {
        return $this->hasMany(ContractLog::class);
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
    
    /**
     * Determina a etapa atual do fluxo
     */
    public function determineCurrentStep()
    {
        return match($this->status) {
            self::STATUS_DRAFT => 'draft',
            self::STATUS_PENDING_COPO => 'pending_copo',
            self::STATUS_PENDING_LEGAL => 'pending_legal',
            self::STATUS_PENDING_COMMERCIAL => 'pending_commercial',
            self::STATUS_PENDING_DIRECTOR => 'pending_director',
            self::STATUS_APPROVED => 'approved',
            self::STATUS_PENDING_SIGNATURE => 'pending_signature',
            self::STATUS_SIGNED => 'signed',
            self::STATUS_ACTIVE => 'active',
            self::STATUS_REJECTED => 'rejected',
            default => 'unknown'
        };
    }
} 