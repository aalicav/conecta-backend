<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchedulingException;
use App\Models\Solicitation;
use App\Models\Clinic;
use App\Models\Professional;
use App\Services\NotificationService;
use App\Services\AppointmentScheduler;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Appointment;
use Carbon\Carbon;
use App\Services\SchedulingConfigService;
use App\Services\SchedulingExceptionService;

class SchedulingExceptionController extends Controller
{
    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * @var AppointmentScheduler
     */
    protected $scheduler;

    /**
     * @var SchedulingExceptionService
     */
    protected $exceptionService;

    /**
     * Create a new controller instance.
     * 
     * @param NotificationService $notificationService
     * @param AppointmentScheduler $scheduler
     * @param SchedulingExceptionService $exceptionService
     */
    public function __construct(NotificationService $notificationService, AppointmentScheduler $scheduler, SchedulingExceptionService $exceptionService)
    {
        $this->middleware('auth:sanctum');
        $this->notificationService = $notificationService;
        $this->scheduler = $scheduler;
        $this->exceptionService = $exceptionService;
    }

    /**
     * Display a listing of the exceptions.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = SchedulingException::with(['solicitation.healthPlan', 'solicitation.patient', 'solicitation.tuss', 'requestedBy', 'approvedBy', 'rejectedBy']);
            
            // Aplicar filtros
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            if ($request->has('solicitation_id')) {
                $query->where('solicitation_id', $request->solicitation_id);
            }
            
            // Usuários com papel de plan_admin ou abaixo só veem suas próprias exceções
            if (Auth::user()->hasRole('plan_admin')) {
                $query->whereHas('solicitation.healthPlan', function ($q) {
                    $q->where('id', Auth::user()->entity_id);
                });
            } elseif (!Auth::user()->hasRole('admin')) {
                $query->where('requested_by', Auth::id());
            }
            
            // Ordenar por data de criação (mais recentes primeiro)
            $query->orderBy('created_at', 'desc');
            
            // Paginar resultados
            $perPage = $request->input('per_page', 15);
            $exceptions = $query->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $exceptions
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar exceções de agendamento: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar exceções de agendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created exception.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validar o request
            $validator = Validator::make($request->all(), [
                'solicitation_id' => 'required|exists:solicitations,id',
                'provider_type_class' => 'required|string|in:App\\Models\\Clinic,App\\Models\\Professional',
                'provider_id' => 'required|integer',
                'justification' => 'required|string|min:10',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Obter a solicitação
            $solicitation = Solicitation::findOrFail($request->solicitation_id);
            
            // Verificar se o usuário tem permissão para criar exceção para esta solicitação
            if (Auth::user()->hasRole('plan_admin') && Auth::user()->entity_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado a criar exceção para esta solicitação'
                ], 403);
            }

            // Verificar se a solicitação está em um status que permite exceções
            if (!$solicitation->isPending() && !$solicitation->isProcessing() && !$solicitation->isFailed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta solicitação não pode receber exceções no estado atual'
                ], 422);
            }

            // Verificar se o provedor existe e obter seu preço para o procedimento
            $providerClass = $request->provider_type_class;
            $provider = $providerClass::findOrFail($request->provider_id);
            
            // Obter o preço do procedimento para este provedor
            $providerPrice = $this->getPriceForProvider($provider, $solicitation->tuss_id);
            
            if ($providerPrice === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este provedor não oferece o procedimento solicitado'
                ], 422);
            }
            
            // Obter o provedor recomendado para comparação
            $recommendedPrice = null;
            
            if ($solicitation->preferred_location_lat && $solicitation->preferred_location_lng) {
                // Usar o AppointmentScheduler injetado para encontrar o melhor provedor
                $recommender = $this->scheduler->findBestProvider(
                    $solicitation, 
                    $solicitation->tuss, 
                    $solicitation->preferred_location_lat, 
                    $solicitation->preferred_location_lng, 
                    $solicitation->max_distance_km ?? 50
                );
                
                if ($recommender) {
                    $recommendedPrice = $recommender['price'] ?? null;
                }
            }
            
            // Criar a exceção
            DB::beginTransaction();
            
            $exception = SchedulingException::create([
                'solicitation_id' => $solicitation->id,
                'provider_type' => $provider->id,
                'provider_id' => $provider->id,
                'provider_type_class' => $providerClass,
                'provider_name' => $provider->name,
                'provider_price' => $providerPrice,
                'recommended_provider_price' => $recommendedPrice,
                'justification' => $request->justification,
                'requested_by' => Auth::id(),
                'status' => SchedulingException::STATUS_PENDING
            ]);
            
            // Notificar administradores sobre a nova exceção
            $this->notificationService->notifyNewSchedulingException($exception);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Exceção de agendamento criada com sucesso, aguardando aprovação',
                'data' => $exception
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar exceção de agendamento: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar exceção de agendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified exception.
     * 
     * @param SchedulingException $exception
     * @return JsonResponse
     */
    public function show(SchedulingException $exception): JsonResponse
    {
        try {
            // Verificar permissão
            if (!$this->canViewException($exception)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado a visualizar esta exceção'
                ], 403);
            }
            
            // Carregar relacionamentos
            $exception->load(['solicitation.healthPlan', 'solicitation.patient', 'solicitation.tuss', 'requestedBy', 'approvedBy', 'rejectedBy']);
            
            return response()->json([
                'success' => true,
                'data' => $exception
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao obter exceção de agendamento: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter exceção de agendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve the specified exception.
     * 
     * @param Request $request
     * @param SchedulingException $exception
     * @return JsonResponse
     */
    public function approve(Request $request, SchedulingException $exception): JsonResponse
    {
        try {
            // Verificar permissão (apenas administradores podem aprovar)
            if (!Auth::user()->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado a aprovar exceções de agendamento'
                ], 403);
            }
            
            // Verificar se a exceção está pendente
            if (!$exception->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta exceção não está pendente'
                ], 422);
            }
            
            DB::beginTransaction();
            
            // Aprovar a exceção
            $exception->approve(Auth::id());
            
            // Carregar a solicitação associada
            $solicitation = $exception->solicitation;
            
            // Agendar com o provedor escolhido na exceção
            $appointment = $this->createAppointmentWithExceptionProvider($exception);
            
            if (!$appointment) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Não foi possível agendar com o provedor escolhido'
                ], 500);
            }
            
            // Atualizar o status da solicitação para agendada
            $solicitation->markAsScheduled(false);
            
            // Notificar sobre a aprovação
            $this->notificationService->notifySchedulingExceptionApproved($exception);
            
            // Notificar sobre o agendamento
            $this->notificationService->notifyAppointmentScheduled($appointment);
            
            DB::commit();
            
            // Retornar resposta com detalhes do agendamento
            return response()->json([
                'success' => true,
                'message' => 'Exceção de agendamento aprovada e agendamento realizado com sucesso',
                'data' => [
                    'exception' => $exception->fresh(['solicitation.healthPlan', 'solicitation.patient', 'solicitation.tuss', 'requestedBy', 'approvedBy']),
                    'appointment' => $appointment->load('provider')
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao aprovar exceção de agendamento: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao aprovar exceção de agendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create appointment with the provider chosen in the exception.
     * 
     * @param SchedulingException $exception
     * @return Appointment|null
     */
    protected function createAppointmentWithExceptionProvider(SchedulingException $exception)
    {
        try {
            $solicitation = $exception->solicitation;
            
            // Determinar tipo de provedor pela classe
            $providerType = $exception->provider_type_class;
            $providerId = $exception->provider_type;
            
            // Buscar o provedor
            $provider = $providerType::find($providerId);
            
            if (!$provider) {
                Log::error("Provedor não encontrado: {$providerType} #{$providerId} para exceção #{$exception->id}");
                return null;
            }
            
            // Encontrar um horário disponível para o agendamento
            // Para essa implementação inicial, vamos apenas agendar para o primeiro dia disponível
            $scheduledDate = $this->findAvailableSlotForProvider(
                $provider, 
                $solicitation->preferred_date_start, 
                $solicitation->preferred_date_end
            );
            
            if (!$scheduledDate) {
                Log::error("Não foi possível encontrar um horário disponível para o provedor {$providerType} #{$providerId} para exceção #{$exception->id}");
                return null;
            }
            
            // Criar o agendamento
            $appointment = new Appointment([
                'solicitation_id' => $solicitation->id,
                'provider_type' => $providerType,
                'provider_id' => $providerId,
                'scheduled_date' => $scheduledDate,
                'status' => Appointment::STATUS_SCHEDULED,
                'created_by' => Auth::id(),
                'notes' => 'Agendamento criado a partir de exceção aprovada #' . $exception->id
            ]);
            
            $appointment->save();
            
            Log::info("Agendamento #{$appointment->id} criado com sucesso para exceção #{$exception->id}");
            
            return $appointment;
        } catch (\Exception $e) {
            Log::error("Erro ao criar agendamento para exceção #{$exception->id}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find available slot for a provider.
     * 
     * @param mixed $provider The provider (Clinic or Professional)
     * @param Carbon $startDate Start date
     * @param Carbon $endDate End date
     * @return Carbon|null Scheduled date or null if no slot available
     */
    protected function findAvailableSlotForProvider($provider, $startDate, $endDate)
    {
        // Em uma implementação real, esta função seria mais complexa e verificaria
        // a agenda do provedor, horários de funcionamento, etc.
        // Para esta implementação básica, vamos apenas retornar o início da data preferida
        // durante o horário comercial (às 14h)
        
        $startDate = Carbon::parse($startDate);
        
        // Não permitir agendamento no passado
        if ($startDate->isPast()) {
            $startDate = Carbon::now()->addDay();
        }
        
        // Definir o horário para 14h (assumindo horário comercial)
        $scheduledDate = $startDate->copy()->setHour(14)->setMinute(0)->setSecond(0);
        
        // Verificar se o horário já está ocupado
        $providerType = get_class($provider);
        $isBooked = Appointment::where('provider_type', $providerType)
            ->where('provider_id', $provider->id)
            ->whereDate('scheduled_date', $scheduledDate->toDateString())
            ->whereTime('scheduled_date', $scheduledDate->toTimeString())
            ->where('status', '!=', Appointment::STATUS_CANCELLED)
            ->exists();
            
        if ($isBooked) {
            // Se estiver ocupado, tente às 15h
            $scheduledDate->addHour();
            
            // Verificar novamente
            $isBooked = Appointment::where('provider_type', $providerType)
                ->where('provider_id', $provider->id)
                ->whereDate('scheduled_date', $scheduledDate->toDateString())
                ->whereTime('scheduled_date', $scheduledDate->toTimeString())
                ->where('status', '!=', Appointment::STATUS_CANCELLED)
                ->exists();
                
            if ($isBooked) {
                // Se ainda estiver ocupado, tente no próximo dia às 14h
                $scheduledDate = $startDate->copy()->addDay()->setHour(14)->setMinute(0)->setSecond(0);
            }
        }
        
        return $scheduledDate;
    }

    /**
     * Reject the specified exception.
     * 
     * @param Request $request
     * @param SchedulingException $exception
     * @return JsonResponse
     */
    public function reject(Request $request, $exception): JsonResponse
    {
        try {
            // Validar o request
            $validator = Validator::make($request->all(), [
                'rejection_reason' => 'required|string|min:5',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Verificar permissão (apenas administradores podem rejeitar)
            $user = Auth::user();
            if (!$user->hasRole(['admin', 'super_admin', 'director'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Não autorizado a rejeitar exceções de agendamento'
                ], 403);
            }
            
            $exception = SchedulingException::findOrFail($exception);
            
            // Verificar se a exceção está pendente
            if (!$exception->isPending()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta exceção não está pendente'
                ], 422);
            }
            
            DB::beginTransaction();
            
            // Rejeitar a exceção
            $exception->reject(Auth::id(), $request->rejection_reason);
            
            // Notificar sobre a rejeição
            $this->notificationService->notifySchedulingExceptionRejected($exception);
            
            // Adicionar um log detalhado explicando o motivo da rejeição
            Log::info("Scheduling exception #{$exception->id} rejected by user #{$user->id} ({$user->name}). Reason: {$request->rejection_reason}");
            
            // Carregar a solicitação associada
            $solicitation = $exception->solicitation;
            
            // Tentar agendar automaticamente com o provedor recomendado
            // Este é o comportamento padrão quando uma exceção é rejeitada
            $appointment = null;
            $autoScheduled = false;
            
            if (SchedulingConfigService::isAutomaticSchedulingEnabled()) {
                // Definir o status da solicitação para 'processing' antes de tentar agendar
                $solicitation->markAsProcessing();
                
                // Tentar agendar automaticamente
                $appointment = $this->scheduler->scheduleAppointment($solicitation);
                
                if ($appointment) {
                    // Agendar com sucesso, marcar solicitação como agendada
                    $solicitation->markAsScheduled(true);
                    $autoScheduled = true;
                    
                    // Notificar sobre o agendamento
                    $this->notificationService->notifyAppointmentScheduled($appointment);
                } else {
                    // Falha no agendamento automático
                    $solicitation->markAsFailed();
                }
            }
            
            DB::commit();
            
            // Resposta apropriada com base no resultado do agendamento automático
            if ($autoScheduled) {
                return response()->json([
                    'success' => true,
                    'message' => 'Exceção de agendamento rejeitada e agendamento automático realizado com sucesso. O sistema selecionou um provedor com melhor custo-benefício conforme diretrizes do Dr. Ítalo.',
                    'data' => [
                        'exception' => $exception->fresh(['solicitation.healthPlan', 'solicitation.patient', 'solicitation.tuss', 'requestedBy', 'rejectedBy']),
                        'appointment' => $appointment->load('provider'),
                        'auto_scheduled' => true
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Exceção de agendamento rejeitada. ' . 
                                 ($solicitation->isFailed() ? 'Não foi possível realizar o agendamento automático. Necessário agendamento manual.' : 'O agendamento automático está desabilitado.'),
                    'data' => [
                        'exception' => $exception->fresh(['solicitation.healthPlan', 'solicitation.patient', 'solicitation.tuss', 'requestedBy', 'rejectedBy']),
                        'auto_scheduled' => false
                    ]
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao rejeitar exceção de agendamento: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar exceção de agendamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the user can view the exception.
     * 
     * @param SchedulingException $exception
     * @return bool
     */
    protected function canViewException(SchedulingException $exception): bool
    {
        $user = Auth::user();
        
        // Administradores podem ver todas as exceções
        if ($user->hasRole('admin')) {
            return true;
        }
        
        // Usuários com papel de plan_admin só podem ver exceções de seu plano
        if ($user->hasRole('plan_admin')) {
            return $exception->solicitation->health_plan_id == $user->entity_id;
        }
        
        // Outros usuários só podem ver exceções que eles mesmos criaram
        return $exception->requested_by == $user->id;
    }

    /**
     * Get price for provider and TUSS code.
     * 
     * @param mixed $provider
     * @param int $tussId
     * @return float|null
     */
    protected function getPriceForProvider($provider, int $tussId): ?float
    {
        // Obter o contrato de preços ativo do provedor
        $contract = $provider->pricingContracts()->active()->first();
        
        if (!$contract) {
            return null;
        }
        
        // Obter o item de preço para o procedimento TUSS específico
        $pricingItem = $contract->pricingItems()->where('tuss_id', $tussId)->first();
        
        return $pricingItem ? $pricingItem->price : null;
    }
}
