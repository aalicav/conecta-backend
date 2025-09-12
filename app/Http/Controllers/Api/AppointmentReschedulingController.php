<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentRescheduling;
use App\Services\WhapiWhatsAppService;
use App\Services\NotificationService;
use App\Services\ReschedulingFinancialService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AppointmentReschedulingController extends Controller
{
    protected $whatsappService;
    protected $notificationService;
    protected $financialService;

    public function __construct(
        WhapiWhatsAppService $whatsappService, 
        NotificationService $notificationService,
        ReschedulingFinancialService $financialService
    )
    {
        $this->middleware('auth:sanctum');
        $this->whatsappService = $whatsappService;
        $this->notificationService = $notificationService;
        $this->financialService = $financialService;
    }

    /**
     * Display a listing of reschedulings
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AppointmentRescheduling::with([
                'originalAppointment' => function ($query) {
                    $query->with(['solicitation.patient', 'solicitation.healthPlan']);
                },
                'newAppointment' => function ($query) {
                    $query->with(['solicitation.patient', 'solicitation.healthPlan']);
                },
                'requestedBy',
                'approvedBy',
                'rejectedBy',
                'originalBillingItem' => function ($query) {
                    $query->withTrashed();
                },
                'newBillingItem' => function ($query) {
                    $query->withTrashed();
                }
            ]);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('reason')) {
                $query->where('reason', $request->reason);
            }

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
            }

            if ($request->has('financial_impact') && $request->financial_impact !== null) {
                $query->where('financial_impact', $request->financial_impact);
            }

            if ($request->has('provider_changed') && $request->provider_changed !== null) {
                $query->where('provider_changed', $request->provider_changed);
            }

            // User permissions
            if (Auth::user()->hasRole('plan_admin') && Auth::user()->entity_id) {
                $query->whereHas('originalAppointment', function ($q) {
                    $q->whereHas('solicitation', function ($sq) {
                        $sq->where('health_plan_id', Auth::user()->entity_id);
                    });
                });
            } elseif (!Auth::user()->hasRole(['admin', 'super_admin'])) {
                $query->where('requested_by', Auth::id());
            }

            // Ensure we only get reschedulings with valid original appointments
            $query->whereHas('originalAppointment');

            $reschedulings = $query->orderBy('created_at', 'desc')
                ->paginate($request->per_page ?? 15);

            // Log the query for debugging
            Log::info('AppointmentRescheduling query executed', [
                'user_id' => Auth::id(),
                'user_roles' => Auth::user()->getRoleNames(),
                'filters' => $request->only(['status', 'reason', 'date_from', 'date_to', 'financial_impact', 'provider_changed']),
                'total_results' => $reschedulings->total()
            ]);

            return response()->json([
                'success' => true,
                'data' => $reschedulings->items(),
                'current_page' => $reschedulings->currentPage(),
                'last_page' => $reschedulings->lastPage(),
                'per_page' => $reschedulings->perPage(),
                'total' => $reschedulings->total()
            ]);

        } catch (\Exception $e) {
            Log::error('Error listing appointment reschedulings: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'user_roles' => Auth::user()->getRoleNames(),
                'filters' => $request->only(['status', 'reason', 'date_from', 'date_to', 'financial_impact', 'provider_changed']),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar reagendamentos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created rescheduling
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'appointment_id' => 'required|exists:appointments,id',
                'new_scheduled_date' => 'nullable|date|after:now', // Made nullable for patient requests
                'reason' => 'required|string|in:' . implode(',', [
                    AppointmentRescheduling::REASON_PAYMENT_NOT_RELEASED,
                    AppointmentRescheduling::REASON_DOCTOR_ABSENT,
                    AppointmentRescheduling::REASON_PATIENT_REQUEST,
                    AppointmentRescheduling::REASON_CLINIC_REQUEST,
                    AppointmentRescheduling::REASON_OTHER
                ]),
                'new_provider_id' => 'nullable|integer',
                'new_provider_type' => 'nullable|string|in:App\\Models\\Clinic,App\\Models\\Professional',
                'new_provider_type_id' => 'nullable|integer',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            $appointment = Appointment::findOrFail($request->appointment_id);

            // Check if appointment can be rescheduled
            if (!$appointment->canBeRescheduled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este agendamento não pode ser reagendado'
                ], 422);
            }

            // Check permissions
            if (!$this->canRescheduleAppointment($appointment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para reagendar este agendamento'
                ], 403);
            }

            DB::beginTransaction();

            // Check if this is a patient request - if so, revert to internal team queue
            if ($request->reason === AppointmentRescheduling::REASON_PATIENT_REQUEST) {
                // Cancel the current appointment
                $appointment->cancel(Auth::user()->id);

                // Revert solicitation to pending status so it goes back to the internal team queue
                $solicitation = $appointment->solicitation;
                $solicitation->status = 'pending';
                $solicitation->scheduled_automatically = false; // Force manual scheduling
                $solicitation->save();

                // Create rescheduling record for tracking purposes
                $rescheduling = AppointmentRescheduling::create([
                    'rescheduling_number' => null, // Will be auto-generated
                    'original_appointment_id' => $appointment->id,
                    'new_appointment_id' => null, // No new appointment yet
                    'reason' => $request->reason,
                    'reason_description' => $request->notes ?: 'Paciente solicitou reagendamento',
                    'status' => AppointmentRescheduling::STATUS_COMPLETED, // Mark as completed since it's just for tracking
                    'original_scheduled_date' => $appointment->scheduled_date,
                    'new_scheduled_date' => null, // Will be set when internal team creates new appointment
                    'original_provider_type' => $appointment->provider_type,
                    'original_provider_type_id' => $appointment->provider_id,
                    'new_provider_type' => null,
                    'new_provider_type_id' => null,
                    'provider_changed' => false,
                    'financial_impact' => false,
                    'original_amount' => 0,
                    'new_amount' => 0,
                    'requested_by' => Auth::id(),
                    'notes' => $request->notes,
                    'whatsapp_sent' => false
                ]);

                // Send notification to internal team
                $this->sendPatientReschedulingRequestNotifications($rescheduling);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Solicitação de reagendamento enviada para a equipe interna. Você será notificado quando um novo agendamento for criado.',
                    'data' => $rescheduling->load([
                        'originalAppointment.solicitation.patient',
                        'requestedBy'
                    ])
                ], 201);
            }

            // Original flow for non-patient requests (admin/internal team rescheduling)
            $newScheduledDate = Carbon::parse($request->new_scheduled_date);
            $newProvider = null;

            // Get new provider if specified
            if ($request->new_provider_type_id && $request->new_provider_type) {
                $providerClass = $request->new_provider_type;
                $newProvider = $providerClass::findOrFail($request->new_provider_type_id);
            } elseif ($request->new_provider_id && $request->new_provider_type) {
                // Fallback for old field names
                $providerClass = $request->new_provider_type;
                $newProvider = $providerClass::findOrFail($request->new_provider_id);
            }

            // Create rescheduling
            $rescheduling = $appointment->reschedule(
                $newScheduledDate,
                Auth::user(),
                $request->reason,
                'Descrição do motivo',
                $newProvider,
                $request->notes
            );

            // Send notifications
            $this->sendReschedulingNotifications($rescheduling);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reagendamento solicitado com sucesso',
                'data' => $rescheduling->load([
                    'originalAppointment.solicitation.patient',
                    'newAppointment.solicitation.patient',
                    'requestedBy'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating appointment rescheduling: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar reagendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified rescheduling
     */
    public function show(AppointmentRescheduling $rescheduling): JsonResponse
    {
        try {
            // Check permissions
            if (!$this->canViewRescheduling($rescheduling)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para visualizar este reagendamento'
                ], 403);
            }

            $rescheduling->load([
                'originalAppointment.solicitation.patient',
                'originalAppointment.solicitation.healthPlan',
                'newAppointment.solicitation.patient',
                'newAppointment.solicitation.healthPlan',
                'requestedBy',
                'approvedBy',
                'rejectedBy',
                'originalBillingItem',
                'newBillingItem'
            ]);

            return response()->json([
                'success' => true,
                'data' => $rescheduling
            ]);

        } catch (\Exception $e) {
            Log::error('Error showing appointment rescheduling: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao visualizar reagendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a rescheduling
     */
    public function approve(Request $request, AppointmentRescheduling $rescheduling): JsonResponse
    {
        try {
            // Check permissions
            if (!Auth::user()->hasRole(['admin', 'super_admin', 'network_manager'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para aprovar reagendamentos'
                ], 403);
            }

            if (!$rescheduling->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este reagendamento não está pendente'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'approval_notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $rescheduling->approve(Auth::user(), $request->approval_notes);

            // Process financial impact
            $this->financialService->processFinancialImpact($rescheduling);

            // Send approval notifications
            $this->sendApprovalNotifications($rescheduling);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reagendamento aprovado com sucesso',
                'data' => $rescheduling->fresh(['approvedBy'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving appointment rescheduling: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao aprovar reagendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a rescheduling
     */
    public function reject(Request $request, AppointmentRescheduling $rescheduling): JsonResponse
    {
        try {
            // Check permissions
            if (!Auth::user()->hasRole(['admin', 'super_admin', 'network_manager'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para rejeitar reagendamentos'
                ], 403);
            }

            if (!$rescheduling->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este reagendamento não está pendente'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'rejection_reason' => 'required|string|min:10|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $rescheduling->reject(Auth::user(), $request->rejection_reason);

            // Reverse financial impact if any
            $this->financialService->reverseFinancialImpact($rescheduling);

            // Send rejection notifications
            $this->sendRejectionNotifications($rescheduling);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reagendamento rejeitado',
                'data' => $rescheduling->fresh(['rejectedBy'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error rejecting appointment rescheduling: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar reagendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete a rescheduling
     */
    public function complete(AppointmentRescheduling $rescheduling): JsonResponse
    {
        try {
            // Check permissions
            if (!Auth::user()->hasRole(['admin', 'super_admin', 'network_manager'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para completar reagendamentos'
                ], 403);
            }

            if (!$rescheduling->isApproved()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este reagendamento não está aprovado'
                ], 422);
            }

            DB::beginTransaction();

            $rescheduling->complete();

            // Mark WhatsApp as sent
            $rescheduling->markWhatsAppSent();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reagendamento concluído com sucesso',
                'data' => $rescheduling->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error completing appointment rescheduling: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao completar reagendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rescheduling statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $query = AppointmentRescheduling::query();

            // Apply date filters
            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
            }

            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
            }

            $statistics = [
                'total' => $query->count(),
                'pending' => (clone $query)->where('status', AppointmentRescheduling::STATUS_PENDING)->count(),
                'approved' => (clone $query)->where('status', AppointmentRescheduling::STATUS_APPROVED)->count(),
                'rejected' => (clone $query)->where('status', AppointmentRescheduling::STATUS_REJECTED)->count(),
                'completed' => (clone $query)->where('status', AppointmentRescheduling::STATUS_COMPLETED)->count(),
                'with_financial_impact' => (clone $query)->where('financial_impact', true)->count(),
                'with_provider_change' => (clone $query)->where('provider_changed', true)->count(),
                'overdue' => (clone $query)->pending()->where('created_at', '<', now()->subDays(7))->count()
            ];

            // Reason breakdown
            $reasonBreakdown = (clone $query)
                ->selectRaw('reason, count(*) as count')
                ->groupBy('reason')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->reason => $item->count];
                });

            $statistics['reason_breakdown'] = $reasonBreakdown;

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting rescheduling statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter estatísticas de reagendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * Check if user can reschedule appointment
     */
    protected function canRescheduleAppointment(Appointment $appointment): bool
    {
        $user = Auth::user();

        // Admins can reschedule any appointment
        if ($user->hasRole(['admin', 'super_admin'])) {
            return true;
        }

        // Network managers can reschedule appointments in their network
        if ($user->hasRole('network_manager')) {
            return true; // Add specific network logic if needed
        }

        // Plan admins can reschedule appointments for their health plan
        if ($user->hasRole('plan_admin')) {
            return $appointment->solicitation->health_plan_id == $user->entity_id;
        }

        // Commercial users can reschedule appointments they created
        if ($user->hasRole('commercial')) {
            return $appointment->created_by == $user->id;
        }

        return false;
    }

    /**
     * Check if user can view rescheduling
     */
    protected function canViewRescheduling(AppointmentRescheduling $rescheduling): bool
    {
        $user = Auth::user();

        // Admins can view any rescheduling
        if ($user->hasRole(['admin', 'super_admin'])) {
            return true;
        }

        // Plan admins can view reschedulings for their health plan
        if ($user->hasRole('plan_admin')) {
            return $rescheduling->originalAppointment->solicitation->health_plan_id == $user->entity_id;
        }

        // Users can view reschedulings they requested
        return $rescheduling->requested_by == $user->id;
    }

    /**
     * Send rescheduling notifications
     */
    protected function sendReschedulingNotifications(AppointmentRescheduling $rescheduling): void
    {
        try {
            // Send WhatsApp notification to patient
            $patient = $rescheduling->originalAppointment->solicitation->patient;
            if ($patient && $patient->phone) {
                $message = $this->buildReschedulingWhatsAppMessage($rescheduling);
                $this->whatsappService->sendMessage($patient->phone, $message);
            }

            // Send database notification to admins
            $this->notificationService->notifyReschedulingRequested($rescheduling);

        } catch (\Exception $e) {
            Log::error('Error sending rescheduling notifications: ' . $e->getMessage());
        }
    }

    /**
     * Send notifications for patient rescheduling requests
     */
    protected function sendPatientReschedulingRequestNotifications(AppointmentRescheduling $rescheduling): void
    {
        try {
            // Send WhatsApp notification to patient confirming their request
            $patient = $rescheduling->originalAppointment->solicitation->patient;
            if ($patient && $patient->phone) {
                $message = $this->buildPatientReschedulingRequestMessage($rescheduling);
                $this->whatsappService->sendMessage($patient->phone, $message);
            }

            // Send database notification to internal team (admins, network managers)
            $this->notificationService->notifyPatientReschedulingRequest($rescheduling);

        } catch (\Exception $e) {
            Log::error('Error sending patient rescheduling request notifications: ' . $e->getMessage());
        }
    }

    /**
     * Build WhatsApp message for rescheduling
     */
    protected function buildReschedulingWhatsAppMessage(AppointmentRescheduling $rescheduling): string
    {
        $originalDate = $rescheduling->original_scheduled_date->format('d/m/Y H:i');
        $newDate = $rescheduling->new_scheduled_date->format('d/m/Y H:i');
        $reason = $rescheduling->reason_label;

        return "🔄 *Reagendamento de Consulta*\n\n" .
               "Sua consulta foi reagendada:\n" .
               "📅 *Data original:* {$originalDate}\n" .
               "📅 *Nova data:* {$newDate}\n" .
               "📝 *Motivo:* {$reason}\n" .
               "📋 *Descrição:* {$rescheduling->reason_description}\n\n" .
               "Aguarde a confirmação da clínica. Você receberá uma notificação quando o reagendamento for aprovado.";
    }

    /**
     * Build WhatsApp message for patient rescheduling request confirmation
     */
    protected function buildPatientReschedulingRequestMessage(AppointmentRescheduling $rescheduling): string
    {
        $originalDate = $rescheduling->original_scheduled_date->format('d/m/Y H:i');

        return "📅 *Solicitação de Reagendamento Recebida*\n\n" .
               "Recebemos sua solicitação de reagendamento:\n" .
               "📅 *Consulta original:* {$originalDate}\n" .
               "📝 *Motivo:* {$rescheduling->reason_description}\n\n" .
               "Sua solicitação foi enviada para nossa equipe interna.\n" .
               "Entraremos em contato em breve com as novas opções de agendamento.\n\n" .
               "Agradecemos sua compreensão!";
    }

    /**
     * Get professionals and clinics by specialty for rescheduling
     */
    public function getProvidersBySpecialty(Request $request): JsonResponse
    {
        try {
            $specialty = $request->get('specialty');
            $city = $request->get('city');
            $state = $request->get('state');

            if (!$specialty) {
                return response()->json([
                    'success' => false,
                    'message' => 'Especialidade é obrigatória'
                ], 400);
            }

            $professionals = collect();
            $clinics = collect();

            // Get professionals with the specialty
            $professionalQuery = \App\Models\Professional::where('specialty', 'like', '%' . $specialty . '%')
                ->where('status', 'active')
                ->with(['clinic', 'user']);

            if ($city) {
                $professionalQuery->where('city', 'like', '%' . $city . '%');
            }

            if ($state) {
                $professionalQuery->where('state', 'like', '%' . $state . '%');
            }

            $professionals = $professionalQuery->get()->map(function ($professional) {
                return [
                    'id' => $professional->id,
                    'name' => $professional->name,
                    'specialty' => $professional->specialty,
                    'council_number' => $professional->council_number,
                    'council_state' => $professional->council_state,
                    'clinic_id' => $professional->clinic_id,
                    'clinic_name' => $professional->clinic?->name,
                    'city' => $professional->city,
                    'state' => $professional->state,
                    'provider_type' => 'professional',
                    'provider_type_id' => $professional->id
                ];
            });

            // Get clinics that have professionals with the specialty
            $clinicQuery = \App\Models\Clinic::whereHas('professionals', function ($query) use ($specialty) {
                $query->where('specialty', 'like', '%' . $specialty . '%')
                      ->where('status', 'active');
            });

            if ($city) {
                $clinicQuery->where('city', 'like', '%' . $city . '%');
            }

            if ($state) {
                $clinicQuery->where('state', 'like', '%' . $state . '%');
            }

            $clinics = $clinicQuery->with(['professionals' => function ($query) use ($specialty) {
                $query->where('specialty', 'like', '%' . $specialty . '%')
                      ->where('status', 'active');
            }])->get()->map(function ($clinic) {
                $specialtyProfessionals = $clinic->professionals->map(function ($professional) {
                    return [
                        'id' => $professional->id,
                        'name' => $professional->name,
                        'specialty' => $professional->specialty,
                        'council_number' => $professional->council_number
                    ];
                });

                return [
                    'id' => $clinic->id,
                    'name' => $clinic->name,
                    'cnpj' => $clinic->cnpj,
                    'city' => $clinic->city,
                    'state' => $clinic->state,
                    'address' => $clinic->address,
                    'provider_type' => 'clinic',
                    'provider_type_id' => $clinic->id,
                    'professionals' => $specialtyProfessionals
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'professionals' => $professionals,
                    'clinics' => $clinics,
                    'total_professionals' => $professionals->count(),
                    'total_clinics' => $clinics->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting providers by specialty: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar prestadores por especialidade'
            ], 500);
        }
    }
}
