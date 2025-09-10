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

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('reason')) {
                $query->where('reason', $request->reason);
            }

            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            if ($request->has('financial_impact')) {
                $query->where('financial_impact', $request->financial_impact);
            }

            if ($request->has('provider_changed')) {
                $query->where('provider_changed', $request->provider_changed);
            }

            // User permissions
            if (Auth::user()->hasRole('plan_admin')) {
                $query->whereHas('originalAppointment.solicitation', function ($q) {
                    $q->where('health_plan_id', Auth::user()->entity_id);
                });
            } elseif (!Auth::user()->hasRole(['admin', 'super_admin'])) {
                $query->where('requested_by', Auth::id());
            }

            $reschedulings = $query->orderBy('created_at', 'desc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $reschedulings
            ]);

        } catch (\Exception $e) {
            Log::error('Error listing appointment reschedulings: ' . $e->getMessage());
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
                'new_scheduled_date' => 'required|date|after:now',
                'reason' => 'required|string|in:' . implode(',', [
                    AppointmentRescheduling::REASON_PAYMENT_NOT_RELEASED,
                    AppointmentRescheduling::REASON_DOCTOR_ABSENT,
                    AppointmentRescheduling::REASON_PATIENT_REQUEST,
                    AppointmentRescheduling::REASON_CLINIC_REQUEST,
                    AppointmentRescheduling::REASON_OTHER
                ]),
                'reason_description' => 'required|string|min:10',
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
                $request->reason_description,
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
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
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
