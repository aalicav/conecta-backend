<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Solicitation;
use App\Models\Payment;
use App\Models\Contract;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\SuriChat;
use App\Models\ExtemporaneousNegotiation;
use App\Models\ValueVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        // Determine the first day of the current month
        $startOfMonth = Carbon::now()->startOfMonth();
        $today = Carbon::today();

        // Get user for context-aware data
        $user = Auth::user();
        $userId = $user->id;

        // Initialize base stats array
        $stats = [
            'appointments' => [
                'total' => 0,
                'pending' => 0,
                'completed' => 0,
            ],
            'solicitations' => [
                'total' => 0,
                'pending' => 0,
                'accepted' => 0,
            ],
            'patients' => [
                'total' => 0,
                'active' => 0,
            ],
            'revenue' => [
                'total' => 0,
                'pending' => 0,
            ],
        ];

        // Role-specific stats
        if ($user->hasRole(['super_admin', 'admin', 'director'])) {
            // Admin/Director view - complete overview
            $stats['appointments']['total'] = Appointment::count();
            $stats['appointments']['pending'] = Appointment::where('status', 'pending')->count();
            $stats['appointments']['completed'] = Appointment::where('status', 'completed')->count();
            
            $stats['solicitations']['total'] = Solicitation::count();
            $stats['solicitations']['pending'] = Solicitation::where('status', 'pending')->count();
            $stats['solicitations']['accepted'] = Solicitation::where('status', 'accepted')->count();
            
            $stats['patients']['total'] = Patient::count();
            $stats['patients']['active'] = Patient::where('created_at', '>=', $startOfMonth)->count();
            
            $stats['revenue']['total'] = Payment::where('status', 'paid')->sum('amount');
            $stats['revenue']['pending'] = Payment::where('status', 'pending')->sum('amount');
            
            // Additional director-specific stats
            if ($user->hasRole(['director'])) {
                $stats['pending_approvals'] = [
                    'contracts' => Contract::where('status', 'awaiting_approval')->count(),
                    'negotiations' => ExtemporaneousNegotiation::where('status', 'pending')->count(),
                    'value_verifications' => ValueVerification::where('status', 'pending')
                        ->where('requester_id', '!=', $userId)
                        ->count(),
                ];
            }
        } elseif ($user->hasRole('commercial')) {
            // Commercial team view
            $stats['contracts'] = [
                'total' => Contract::count(),
                'active' => Contract::where('status', 'active')->count(),
                'pending_approval' => Contract::where('status', 'awaiting_approval')->count(),
                'expired' => Contract::where('end_date', '<', $today)->count(),
                'expiring_soon' => Contract::where('end_date', '>=', $today)
                    ->where('end_date', '<=', $today->copy()->addDays(30))
                    ->count(),
            ];
            
            $stats['negotiations'] = [
                'total' => ExtemporaneousNegotiation::count(),
                'pending' => ExtemporaneousNegotiation::where('status', 'pending')->count(),
                'approved' => ExtemporaneousNegotiation::where('status', 'approved')->count(),
                'rejected' => ExtemporaneousNegotiation::where('status', 'rejected')->count(),
                'pending_addendum' => ExtemporaneousNegotiation::where('status', 'approved')
                    ->where('is_requiring_addendum', true)
                    ->where('addendum_included', false)
                    ->count(),
            ];

            // For Adla specifically - stats about addendums
            if (str_contains(strtolower($user->name), 'adla')) {
                $stats['negotiations']['pending_addendum_details'] = ExtemporaneousNegotiation::where('status', 'approved')
                    ->where('is_requiring_addendum', true)
                    ->where('addendum_included', false)
                    ->with(['contract', 'tuss'])
                    ->get(['id', 'contract_id', 'tuss_id', 'approved_value', 'approved_at']);
            }
            
            $stats['patients']['total'] = Patient::count();
            $stats['revenue']['total'] = Payment::where('status', 'paid')->sum('amount');
        } elseif ($user->hasRole('legal')) {
            // Legal team view
            $stats['contracts'] = [
                'total' => Contract::count(),
                'pending_review' => Contract::where('status', 'awaiting_legal_review')->count(),
                'template_count' => \App\Models\ContractTemplate::count(),
            ];
            
            $stats['addendums'] = [
                'pending' => ExtemporaneousNegotiation::where('status', 'approved')
                    ->where('is_requiring_addendum', true)
                    ->where('addendum_included', false)
                    ->count(),
            ];
        } elseif ($user->hasRole('operational')) {
            // Operational team view
            $stats['appointments']['total'] = Appointment::count();
            $stats['appointments']['pending'] = Appointment::where('status', 'pending')->count();
            $stats['appointments']['completed'] = Appointment::where('status', 'completed')->count();
            $stats['appointments']['today'] = Appointment::whereDate('scheduled_date', $today)->count();
            
            $stats['solicitations']['total'] = Solicitation::count();
            $stats['solicitations']['pending'] = Solicitation::where('status', 'pending')->count();
            
            $stats['patients']['total'] = Patient::count();
            $stats['patients']['active'] = Patient::where('created_at', '>=', $startOfMonth)->count();
        } elseif ($user->hasRole('financial')) {
            // Financial team view
            $stats['revenue'] = [
                'total' => Payment::where('status', 'paid')->sum('amount'),
                'pending' => Payment::where('status', 'pending')->sum('amount'),
                'month_to_date' => Payment::where('status', 'paid')
                    ->where('created_at', '>=', $startOfMonth)
                    ->sum('amount'),
                'last_30_days' => Payment::where('status', 'paid')
                    ->where('created_at', '>=', $today->copy()->subDays(30))
                    ->sum('amount'),
            ];
            
            $stats['professionals'] = [
                'total' => Professional::count(),
                'pending_payment' => Professional::whereHas('payments', function($q) {
                    $q->where('status', 'pending');
                })->count(),
            ];
            
            $stats['patients']['total'] = Patient::count();
        } elseif ($user->hasRole('professional')) {
            // Professional view
            $professionalId = Professional::where('user_id', $userId)->value('id');
            
            if ($professionalId) {
                $stats['appointments']['total'] = Appointment::where('professional_id', $professionalId)->count();
                $stats['appointments']['pending'] = Appointment::where('professional_id', $professionalId)
                    ->where('status', 'pending')
                    ->count();
                $stats['appointments']['completed'] = Appointment::where('professional_id', $professionalId)
                    ->where('status', 'completed')
                    ->count();
                $stats['appointments']['today'] = Appointment::where('professional_id', $professionalId)
                    ->whereDate('scheduled_date', $today)
                    ->count();
                
                $stats['patients']['total'] = Patient::whereHas('appointments', function($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })->count();
                
                $stats['revenue']['total'] = Payment::where('entity_type', 'professional')
                    ->where('entity_id', $professionalId)
                    ->where('status', 'paid')
                    ->sum('amount');
                $stats['revenue']['pending'] = Payment::where('entity_type', 'professional')
                    ->where('entity_id', $professionalId)
                    ->where('status', 'pending')
                    ->sum('amount');
            }
        } elseif ($user->hasRole('clinic')) {
            // Clinic view
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            
            if ($clinicId) {
                $stats['appointments']['total'] = Appointment::where('clinic_id', $clinicId)->count();
                $stats['appointments']['pending'] = Appointment::where('clinic_id', $clinicId)
                    ->where('status', 'pending')
                    ->count();
                $stats['appointments']['completed'] = Appointment::where('clinic_id', $clinicId)
                    ->where('status', 'completed')
                    ->count();
                $stats['appointments']['today'] = Appointment::where('clinic_id', $clinicId)
                    ->whereDate('scheduled_date', $today)
                    ->count();
                
                $stats['patients']['total'] = Patient::whereHas('appointments', function($q) use ($clinicId) {
                    $q->where('clinic_id', $clinicId);
                })->count();
                
                $stats['revenue']['total'] = Payment::where('entity_type', 'clinic')
                    ->where('entity_id', $clinicId)
                    ->where('status', 'paid')
                    ->sum('amount');
                $stats['revenue']['pending'] = Payment::where('entity_type', 'clinic')
                    ->where('entity_id', $clinicId)
                    ->where('status', 'pending')
                    ->sum('amount');
            }
        }

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get upcoming appointments
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUpcomingAppointments(Request $request)
    {
        $limit = $request->input('limit', 5);
        $user = Auth::user();
        $userId = $user->id;

        $query = Appointment::with(['patient', 'procedure'])
            ->whereDate('scheduled_date', '>=', Carbon::today())
            ->orderBy('scheduled_date', 'asc');

        // Apply filters based on user role
        if ($user->hasRole('professional')) {
            $professionalId = Professional::where('user_id', $userId)->value('id');
            if ($professionalId) {
                $query->where('professional_id', $professionalId);
            }
        } elseif ($user->hasRole('clinic')) {
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            if ($clinicId) {
                $query->where('clinic_id', $clinicId);
            }
        }

        $appointments = $query->take($limit)->get();

        // Format the response
        $formattedAppointments = $appointments->map(function ($appointment) {
            return [
                'id' => $appointment->id,
                'patient' => $appointment->patient->name,
                'patient_id' => $appointment->patient_id,
                'time' => $appointment->scheduled_date ? $appointment->scheduled_date->format('H:i') : null,
                'date' => $appointment->scheduled_date ? $appointment->scheduled_date->format('Y-m-d') : $appointment->created_at->format('Y-m-d'),
                'type' => $appointment->procedure ? $appointment->procedure->name : 'Consulta',
                'status' => $appointment->status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedAppointments
        ]);
    }

    /**
     * Get today's appointments
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTodayAppointments()
    {
        $user = Auth::user();
        $userId = $user->id;

        $query = Appointment::with(['patient', 'procedure'])
            ->whereDate('scheduled_date', Carbon::today()->format('Y-m-d'))
            ->orderBy('scheduled_date', 'asc');

        // Apply filters based on user role
        if ($user->hasRole('professional')) {
            $professionalId = Professional::where('user_id', $userId)->value('id');
            if ($professionalId) {
                $query->where('professional_id', $professionalId);
            }
        } elseif ($user->hasRole('clinic')) {
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            if ($clinicId) {
                $query->where('clinic_id', $clinicId);
            }
        }

        $appointments = $query->get();

        // Format the response
        $formattedAppointments = $appointments->map(function ($appointment) {
            return [
                'id' => $appointment->id,
                'patient' => $appointment->patient->name,
                'patient_id' => $appointment->patient_id,
                'time' => $appointment->scheduled_date ? $appointment->scheduled_date->format('H:i') : null,
                'date' => $appointment->scheduled_date ? $appointment->scheduled_date->format('Y-m-d') : $appointment->created_at->format('Y-m-d'),
                'type' => $appointment->procedure ? $appointment->procedure->name : 'Consulta',
                'status' => $appointment->status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedAppointments
        ]);
    }

    /**
     * Get SURI chatbot statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSuriStats()
    {
        $user = Auth::user();
        $userId = $user->id;
        
        // Count messages based on user role
        $query = SuriChat::query();
        
        if ($user->hasRole('professional')) {
            $professionalId = Professional::where('user_id', $userId)->value('id');
            if ($professionalId) {
                $query->where('entity_type', 'professional')->where('entity_id', $professionalId);
            }
        } elseif ($user->hasRole('clinic')) {
            $clinicId = Clinic::where('user_id', $userId)->value('id');
            if ($clinicId) {
                $query->where('entity_type', 'clinic')->where('entity_id', $clinicId);
            }
        } else {
            $query->where('user_id', $userId);
        }
        
        $messageCount = $query->count();
        
        return response()->json([
            'success' => true,
            'data' => [
                'message_count' => $messageCount
            ]
        ]);
    }

    /**
     * Get pending items that require attention based on user role
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPendingItems()
    {
        $user = Auth::user();
        $userId = $user->id;
        $pendingItems = [];
        
        if ($user->hasRole('director')) {
            // Direção: aprovação de contratos, negociações extemporâneas e verificações de valores
            $pendingItems['contracts'] = Contract::where('status', 'awaiting_approval')
                ->with(['healthPlan', 'requester'])
                ->take(5)
                ->get()
                ->map(function($contract) {
                    return [
                        'id' => $contract->id,
                        'type' => 'contract',
                        'title' => "Contrato {$contract->contract_number}",
                        'description' => "Contrato com {$contract->healthPlan->name} aguardando aprovação",
                        'link' => "/contracts/{$contract->id}",
                        'created_at' => $contract->created_at,
                        'priority' => 'high',
                    ];
                });
                
            $pendingItems['negotiations'] = ExtemporaneousNegotiation::where('status', 'pending')
                ->with(['contract', 'tuss', 'requestedBy'])
                ->take(5)
                ->get()
                ->map(function($negotiation) {
                    return [
                        'id' => $negotiation->id,
                        'type' => 'negotiation',
                        'title' => "Negociação Extemporânea #{$negotiation->id}",
                        'description' => "Procedimento {$negotiation->tuss->code} para o contrato {$negotiation->contract->contract_number}",
                        'link' => "/extemporaneous-negotiations/{$negotiation->id}",
                        'created_at' => $negotiation->created_at,
                        'priority' => $negotiation->urgency_level ?? 'medium',
                    ];
                });
                
            $pendingItems['value_verifications'] = ValueVerification::where('status', 'pending')
                ->where('requester_id', '!=', $userId)
                ->with(['requester'])
                ->take(5)
                ->get()
                ->map(function($verification) {
                    return [
                        'id' => $verification->id,
                        'type' => 'value_verification',
                        'title' => "Verificação de Valor #{$verification->id}",
                        'description' => "R$ " . number_format($verification->original_value, 2, ',', '.') . " - {$verification->notes}",
                        'link' => "/value-verifications/{$verification->id}",
                        'created_at' => $verification->created_at,
                        'priority' => 'high',
                    ];
                });
        } elseif ($user->hasRole('commercial')) {
            // Equipe Comercial: contratos aguardando liberação comercial, negociações extemporâneas
            // Verificar se é usuário específico Adla
            if (str_contains(strtolower($user->name), 'adla')) {
                $pendingItems['addendums'] = ExtemporaneousNegotiation::where('status', 'approved')
                    ->where('is_requiring_addendum', true)
                    ->where('addendum_included', false)
                    ->with(['contract', 'tuss'])
                    ->take(10)
                    ->get()
                    ->map(function($negotiation) {
                        return [
                            'id' => $negotiation->id,
                            'type' => 'addendum',
                            'title' => "Aditivo Pendente para Negociação #{$negotiation->id}",
                            'description' => "Procedimento {$negotiation->tuss->code} para o contrato {$negotiation->contract->contract_number}",
                            'link' => "/extemporaneous-negotiations/{$negotiation->id}",
                            'created_at' => $negotiation->approved_at,
                            'priority' => 'high',
                        ];
                    });
            }
            
            $pendingItems['contracts'] = Contract::whereIn('status', ['draft', 'awaiting_commercial_approval'])
                ->with(['healthPlan'])
                ->take(5)
                ->get()
                ->map(function($contract) {
                    return [
                        'id' => $contract->id,
                        'type' => 'contract',
                        'title' => "Contrato {$contract->contract_number}",
                        'description' => "Contrato com {$contract->healthPlan->name} aguardando liberação comercial",
                        'link' => "/contracts/{$contract->id}",
                        'created_at' => $contract->created_at,
                        'priority' => 'medium',
                    ];
                });
                
            $pendingItems['negotiations'] = ExtemporaneousNegotiation::where('status', 'pending')
                ->with(['contract', 'tuss', 'requestedBy'])
                ->take(5)
                ->get()
                ->map(function($negotiation) {
                    return [
                        'id' => $negotiation->id,
                        'type' => 'negotiation',
                        'title' => "Negociação Extemporânea #{$negotiation->id}",
                        'description' => "Procedimento {$negotiation->tuss->code} para o contrato {$negotiation->contract->contract_number}",
                        'link' => "/extemporaneous-negotiations/{$negotiation->id}",
                        'created_at' => $negotiation->created_at,
                        'priority' => $negotiation->urgency_level ?? 'medium',
                    ];
                });
        } elseif ($user->hasRole('legal')) {
            // Equipe Jurídica: contratos aguardando revisão
            $pendingItems['contracts'] = Contract::where('status', 'awaiting_legal_review')
                ->with(['healthPlan', 'requester'])
                ->take(5)
                ->get()
                ->map(function($contract) {
                    return [
                        'id' => $contract->id,
                        'type' => 'contract',
                        'title' => "Contrato {$contract->contract_number}",
                        'description' => "Contrato com {$contract->healthPlan->name} aguardando revisão jurídica",
                        'link' => "/contracts/{$contract->id}",
                        'created_at' => $contract->created_at,
                        'priority' => 'high',
                    ];
                });
                
            // Aditivos pendentes para revisão jurídica
            $pendingItems['addendums'] = ExtemporaneousNegotiation::where('status', 'approved')
                ->where('is_requiring_addendum', true)
                ->where('addendum_included', false)
                ->with(['contract', 'tuss'])
                ->take(5)
                ->get()
                ->map(function($negotiation) {
                    return [
                        'id' => $negotiation->id,
                        'type' => 'addendum',
                        'title' => "Aditivo Pendente para Negociação #{$negotiation->id}",
                        'description' => "Procedimento {$negotiation->tuss->code} para o contrato {$negotiation->contract->contract_number}",
                        'link' => "/extemporaneous-negotiations/{$negotiation->id}",
                        'created_at' => $negotiation->approved_at,
                        'priority' => 'medium',
                    ];
                });
        } elseif ($user->hasRole('operational')) {
            // Equipe Operacional: agendamentos e solicitações pendentes
            $pendingItems['appointments'] = Appointment::where('status', 'pending')
                ->with(['patient', 'procedure'])
                ->take(5)
                ->get()
                ->map(function($appointment) {
                    return [
                        'id' => $appointment->id,
                        'type' => 'appointment',
                        'title' => "Agendamento #{$appointment->id}",
                        'description' => "Paciente {$appointment->patient->name} - " . 
                            ($appointment->procedure ? $appointment->procedure->name : 'Consulta'),
                        'link' => "/appointments/{$appointment->id}",
                        'created_at' => $appointment->created_at,
                        'priority' => 'medium',
                    ];
                });
                
            $pendingItems['solicitations'] = Solicitation::where('status', 'pending')
                ->with(['patient'])
                ->take(5)
                ->get()
                ->map(function($solicitation) {
                    return [
                        'id' => $solicitation->id,
                        'type' => 'solicitation',
                        'title' => "Solicitação #{$solicitation->id}",
                        'description' => "Paciente {$solicitation->patient->name} - {$solicitation->description}",
                        'link' => "/solicitations/{$solicitation->id}",
                        'created_at' => $solicitation->created_at,
                        'priority' => $solicitation->urgency_level ?? 'medium',
                    ];
                });
        } elseif ($user->hasRole('financial')) {
            // Equipe Financeira: pagamentos pendentes e verificações de valores
            $pendingItems['payments'] = Payment::where('status', 'pending')
                ->take(5)
                ->get()
                ->map(function($payment) {
                    return [
                        'id' => $payment->id,
                        'type' => 'payment',
                        'title' => "Pagamento #{$payment->id}",
                        'description' => "R$ " . number_format($payment->amount, 2, ',', '.') . " - {$payment->description}",
                        'link' => "/payments/{$payment->id}",
                        'created_at' => $payment->created_at,
                        'priority' => 'medium',
                    ];
                });
                
            $pendingItems['value_verifications'] = ValueVerification::where(function($query) use ($userId) {
                    $query->where('requester_id', $userId)
                          ->where('status', '!=', 'verified');
                })
                ->take(5)
                ->get()
                ->map(function($verification) {
                    return [
                        'id' => $verification->id,
                        'type' => 'value_verification',
                        'title' => "Verificação de Valor #{$verification->id}",
                        'description' => "R$ " . number_format($verification->original_value, 2, ',', '.') . " - {$verification->notes}",
                        'link' => "/value-verifications/{$verification->id}",
                        'created_at' => $verification->created_at,
                        'priority' => 'high',
                    ];
                });
        }
        
        return response()->json([
            'success' => true,
            'data' => $pendingItems
        ]);
    }
} 