<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\HealthPlanController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\ProfessionalController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\SolicitationController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Http\Controllers\Api\Admin\SystemSettingAdminController;
use App\Http\Controllers\Api\Admin\SchedulingConfigController;
use App\Http\Controllers\Api\Admin\ProfessionalAdminController;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\Professional\ProfessionalDashboardController;
use App\Http\Controllers\Api\ContractTemplateController;
use App\Http\Controllers\Api\NegotiationController;
use App\Http\Controllers\Api\TussController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Examples\WhatsAppNotificationController;
use App\Http\Controllers\Api\SuriChatbotController;
use App\Http\Controllers\Api\DataPrivacyController;
use App\Http\Controllers\Api\BillingRuleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\WhatsappController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Api\HealthPlanDashboardController;
use App\Http\Controllers\Api\SchedulingExceptionController;
use App\Http\Controllers\Api\EntityDocumentTypeController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\HealthPlanBillingController;
use App\Http\Controllers\Api\MedicalSpecialtyController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Perfil do Usuário
    Route::get('/user/profile', [ProfileController::class, 'getProfile']);
    Route::post('/user/profile', [ProfileController::class, 'updateProfile']);
    Route::post('/user/change-password', [ProfileController::class, 'changePassword']);

    // TUSS Procedures
    Route::prefix('tuss')->group(function () {
        Route::get('/', [TussController::class, 'index']);
        Route::post('/', [TussController::class, 'store'])->middleware('permission:manage_tuss');
        Route::get('/{tuss}', [TussController::class, 'show']);
        Route::put('/{tuss}', [TussController::class, 'update'])->middleware('permission:manage_tuss');
        Route::delete('/{tuss}', [TussController::class, 'destroy'])->middleware('permission:manage_tuss');
        Route::patch('/{tuss}/toggle-active', [TussController::class, 'toggleActive'])->middleware('permission:manage_tuss');
        Route::get('/chapters', [TussController::class, 'getChapters']);
        Route::get('/chapters/{chapter}/groups', [TussController::class, 'getGroups']);
        Route::get('/chapters/{chapter}/groups/{group}/subgroups', [TussController::class, 'getSubgroups']);
    });

    // Health Plans
    Route::prefix('health-plans')->group(function () {
        Route::get('/', [HealthPlanController::class, 'index']);
        Route::post('/', [HealthPlanController::class, 'store']);
        Route::get('/{health_plan}', [HealthPlanController::class, 'show']);
        Route::put('/{health_plan}', [HealthPlanController::class, 'update']);
        Route::delete('/{health_plan}', [HealthPlanController::class, 'destroy']);
        Route::post('/{health_plan}/approve', [HealthPlanController::class, 'approve']);
        Route::post('/{health_plan}/users', [HealthPlanController::class, 'createUser']);
        Route::get('/{health_plan}/procedures', [HealthPlanController::class, 'getProcedures']);
        Route::put('/{health_plan}/procedures', [HealthPlanController::class, 'updateProcedures']);
        Route::post('/{health_plan}/documents', [HealthPlanController::class, 'uploadDocuments']);
    });

    // Health Plans Dashboard
    Route::get('/health-plans/dashboard/stats', [HealthPlanDashboardController::class, 'getStats']);
    Route::get('/health-plans/dashboard/procedures', [HealthPlanDashboardController::class, 'getProcedures']);
    Route::get('/health-plans/dashboard/financial', [HealthPlanDashboardController::class, 'getFinancial']);
    Route::get('/health-plans/dashboard/recent', [HealthPlanDashboardController::class, 'getRecentPlans']);
    Route::get('/health-plans/dashboard/solicitations', [HealthPlanDashboardController::class, 'getRecentSolicitations']);
    
    // Clinics
    Route::apiResource('clinics', ClinicController::class);
    Route::post('/clinics/{clinic}/approve', [ClinicController::class, 'approve'])->middleware('permission:approve clinics');
    Route::post('/clinics/{clinic}/documents', [ClinicController::class, 'uploadDocuments']);
    Route::get('/clinics/{clinic}/branches', [ClinicController::class, 'branches']);
    Route::patch('/clinics/{clinic}/status', [ClinicController::class, 'updateStatus'])->middleware('permission:edit clinics');
    Route::get('/clinics/{clinic}/procedures', [ClinicController::class, 'getProcedures']);
    Route::put('/clinics/{clinic}/procedures', [ClinicController::class, 'updateProcedures'])
        ->middleware(['permission:edit clinics']);
    Route::get('/clinics/{clinic}/professionals', [ClinicController::class, 'professionals']);
    Route::post('/clinics/{clinic}/professionals', [ClinicController::class, 'associateProfessionals'])
        ->middleware(['permission:edit clinics']);
    Route::delete('/clinics/{clinic}/professionals', [ClinicController::class, 'disassociateProfessionals'])
        ->middleware(['permission:edit clinics']);
    Route::get('/clinics/{clinic}/professional-stats', [ClinicController::class, 'professionalStats']);
    
    // Professionals
    Route::apiResource('professionals', ProfessionalController::class);
    Route::post('/professionals/{professional}/approve', [ProfessionalController::class, 'approve'])->middleware('permission:approve professionals');
    Route::post('/professionals/{professional}/documents', [ProfessionalController::class, 'uploadDocuments']);
    Route::delete('/professionals/{professional}/documents/{document}', [ProfessionalController::class, 'deleteDocument']);
    Route::get('/professionals/{professional}/procedures', [ProfessionalController::class, 'getProcedures'])
        ->middleware(['permission:view professionals']);
    Route::put('/professionals/{professional}/procedures', [ProfessionalController::class, 'updateProcedures'])
        ->middleware(['permission:edit professionals']);
    Route::get('/professionals/{professional}/specialties', [ProfessionalController::class, 'getSpecialties'])
        ->middleware(['permission:view professionals']);
    Route::put('/professionals/{professional}/specialties', [ProfessionalController::class, 'updateSpecialties'])
        ->middleware(['permission:edit professionals']);
    Route::get('/professionals/{professional}/contract-data', [ProfessionalController::class, 'getContractData'])
        ->middleware(['permission:view professionals']);
    
    // Patients
    Route::apiResource('patients', PatientController::class);
    
    // Solicitations
    Route::apiResource('solicitations', SolicitationController::class);
    Route::patch('/solicitations/{solicitation}/cancel', [SolicitationController::class, 'cancel']);
    Route::post('/solicitations/{solicitation}/reschedule', [SolicitationController::class, 'reschedule']);
    Route::post('/solicitations/{solicitation}/auto-schedule', [SolicitationController::class, 'forceSchedule']);
    
    // Add route for processing pending solicitations
    Route::post('/solicitations/process-pending', [SolicitationController::class, 'processPending'])->middleware('role:network_manager,super_admin');
    
    // Scheduling Exceptions
    Route::apiResource('scheduling-exceptions', SchedulingExceptionController::class)->only(['index', 'store', 'show']);
    Route::post('/scheduling-exceptions/{scheduling_exception}/approve', [SchedulingExceptionController::class, 'approve'])->middleware('role:admin');
    Route::post('/scheduling-exceptions/{scheduling_exception}/reject', [SchedulingExceptionController::class, 'reject'])->middleware('role:admin');
    
    // Appointments
    Route::prefix('appointments')->group(function () {
        Route::get('/', [AppointmentController::class, 'index']);
        Route::post('/', [AppointmentController::class, 'store']);
        Route::get('/{appointment}', [AppointmentController::class, 'show']);
        Route::put('/{appointment}', [AppointmentController::class, 'update']);
        Route::delete('/{appointment}', [AppointmentController::class, 'destroy']);
        Route::post('/{appointment}/cancel', [AppointmentController::class, 'cancel']);
        Route::post('/{appointment}/confirm', [AppointmentController::class, 'confirm']);
        Route::post('/{appointment}/complete', [AppointmentController::class, 'complete']);
        Route::post('/schedule-automatically', [AppointmentController::class, 'scheduleAutomatically']);
        Route::post('/process-pending', [AppointmentController::class, 'processPendingSolicitations']);
    });
    
    // Contracts
    Route::apiResource('contracts', ContractController::class);
    Route::get('/contracts/{contract}/download', [ContractController::class, 'download']);
    Route::patch('/contracts/{contract}/sign', [ContractController::class, 'sign']);
    Route::post('/contracts/generate', [ContractController::class, 'generate']);
    Route::post('/contracts/{contract}/regenerate', [ContractController::class, 'regenerate']);
    Route::post('/contracts/preview', [ContractController::class, 'preview']);
    Route::post('/contracts/entity-data', [ContractController::class, 'getEntityData']);
    Route::post('/contracts/search-entities', [ContractController::class, 'searchEntities']);
    Route::get('/contracts/approval-workflow', [ContractController::class, 'approvalWorkflow']);
    
    // Autentique Digital Signatures
    Route::post('/contracts/{contract}/send-for-signature', [ContractController::class, 'sendForSignature']);
    Route::post('/contracts/resend-signatures', [ContractController::class, 'resendSignatures']);
    Route::post('/webhooks/autentique', [ContractController::class, 'handleAutentiqueWebhook']);
    
    // Payments
    Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
    Route::post('/payments/{payment}/process', [PaymentController::class, 'process']);
    Route::post('/payments/{payment}/apply-gloss', [PaymentController::class, 'applyGloss'])->middleware('permission:manage financials');
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])->middleware('permission:manage financials');
    Route::post('/payments/{payment}/glosses/{gloss}/revert', [PaymentController::class, 'revertGloss'])->middleware('permission:manage financials');
    
    // Reports
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/solicitations', [ReportController::class, 'solicitations']);
    Route::get('/reports/appointments', [ReportController::class, 'appointments']);
    Route::get('/reports/providers', [ReportController::class, 'providers']);
    Route::get('/reports/health-plans', [ReportController::class, 'healthPlans']);
    Route::get('/reports/financials', [ReportController::class, 'financials'])->middleware('permission:view financial reports');
    Route::get('/reports/performance', [ReportController::class, 'performance']);
    Route::post('/reports/export', [ReportController::class, 'export']);
    Route::get('/reports/generations/{id}/download', [ReportController::class, 'download']);
    Route::post('/reports/{id}/generate', [ReportController::class, 'generate']);
    Route::get('/reports/{id}', [ReportController::class, 'show']);
    
    // System Settings
    Route::get('/system-settings', [SystemSettingController::class, 'index']);
    Route::post('/system-settings', [SystemSettingController::class, 'updateMultiple'])->middleware('permission:edit settings')->name('system-settings.update-multiple');
    Route::post('/system-settings/create', [SystemSettingController::class, 'store'])->middleware('permission:edit settings')->name('system-settings.store');
    Route::get('/system-settings/group/{group}', [SystemSettingController::class, 'getGroup']);
    Route::get('/system-settings/{key}', [SystemSettingController::class, 'show']);
    Route::put('/system-settings/{key}', [SystemSettingController::class, 'update'])->middleware('permission:edit settings');
    Route::delete('/system-settings/{key}', [SystemSettingController::class, 'destroy'])->middleware('permission:edit settings');
    Route::post('/test-email', [NotificationController::class, 'testEmail']);

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread', [NotificationController::class, 'unread']);
        Route::get('/{id}', [NotificationController::class, 'show']);
        Route::patch('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::patch('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::post('/settings', [NotificationController::class, 'updateSettings']);
        Route::get('/settings', [NotificationController::class, 'getSettings']);
        Route::post('/role', [NotificationController::class, 'sendToRole']);
        Route::post('/user', [NotificationController::class, 'sendToUser']);
        Route::get('/unread/count', [NotificationController::class, 'unreadCount']);
        Route::post('/test', [NotificationController::class, 'test']);
        Route::post('/test-email', [NotificationController::class, 'testEmail']);
    });

    // Notifications
    Route::post('/notifications/pending-registrations', [NotificationController::class, 'notifyPendingRegistrations']);

    // Billing
    Route::prefix('billing')->group(function () {
        Route::get('/overview', [BillingController::class, 'overview']);
        Route::get('/transactions', [BillingController::class, 'transactions']);
        Route::get('/transactions/{id}/invoice', [BillingController::class, 'generateInvoice']);
        Route::patch('/transactions/{id}/status', [BillingController::class, 'updateStatus']);
        Route::get('/batches', [BillingController::class, 'index']);
        Route::post('/batches', [BillingController::class, 'generateBatch']);
        Route::get('/batches/{batch}', [BillingController::class, 'show']);
        Route::post('/batches/{batch}/payment', [BillingController::class, 'registerPayment']);
        Route::post('/items/{item}/glosa', [BillingController::class, 'registerGlosa']);
        Route::get('/glosses/{gloss}', [BillingController::class, 'showGlosa']);
        Route::post('/glosses/{gloss}/appeal', [BillingController::class, 'appealGlosa']);
        Route::post('/check-overdue', [BillingController::class, 'checkOverduePayments']);
    });

    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::post('/update', [ProfileController::class, 'update']);
        Route::post('/update-photo', [ProfileController::class, 'updatePhoto']);
        Route::post('/change-password', [ProfileController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// System Settings API Routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Public settings - accessible to all authenticated users
    Route::get('settings/public', [SystemSettingController::class, 'getPublicSettings']);
    
    // Admin-only settings - requires 'manage_settings' permission
    Route::middleware(['can:manage_settings'])->prefix('settings')->group(function () {
        Route::get('/', [SystemSettingController::class, 'index']);
        Route::get('/group/{group}', [SystemSettingController::class, 'getGroup']);
        Route::get('/{key}', [SystemSettingController::class, 'show']);
        Route::put('/{key}', [SystemSettingController::class, 'update']);
        Route::post('/batch', [SystemSettingController::class, 'batchUpdate']);
        Route::post('/reset', [SystemSettingController::class, 'resetToDefaults']);
        Route::post('/reset-group/{group}', [SystemSettingController::class, 'resetGroupToDefaults']);
    });
    
    // Admin system settings advanced management
    Route::middleware(['can:settings.edit'])->prefix('admin/settings')->group(function () {
        Route::get('/groups', [SystemSettingAdminController::class, 'getGroups']);
        Route::get('/stats', [SystemSettingAdminController::class, 'getStats']);
        Route::post('/groups', [SystemSettingAdminController::class, 'createGroup']);
        Route::delete('/groups/{group}', [SystemSettingAdminController::class, 'deleteGroup']);
        Route::get('/export', [SystemSettingAdminController::class, 'export']);
        Route::post('/import', [SystemSettingAdminController::class, 'import']);
        
        // New admin routes
        Route::get('/audit', [SystemSettingAdminController::class, 'getAuditLog']);
        Route::post('/backup', [SystemSettingAdminController::class, 'createBackup']);
        Route::get('/backups', [SystemSettingAdminController::class, 'listBackups']);
        Route::post('/restore/{backup}', [SystemSettingAdminController::class, 'restoreBackup']);
        Route::delete('/backups/{backup}', [SystemSettingAdminController::class, 'deleteBackup']);
    });

    // Admin scheduling configuration
    Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin/scheduling')->group(function () {
        Route::get('/config', [SchedulingConfigController::class, 'getConfig']);
        Route::post('/config', [SchedulingConfigController::class, 'updateConfig']);
        Route::post('/toggle', [SchedulingConfigController::class, 'toggleScheduling']);
    });
});

// Admin professional routes
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin/professionals')->group(function () {
    Route::get('/stats', [ProfessionalAdminController::class, 'getStats']);
    Route::post('/batch-update', [ProfessionalAdminController::class, 'batchUpdate']);
    Route::post('/{professional}/create-account', [ProfessionalAdminController::class, 'createAccount']);
    Route::get('/export', [ProfessionalAdminController::class, 'export']);
});

// Professional dashboard routes
Route::middleware(['auth:sanctum', 'role:professional'])->prefix('professional/dashboard')->group(function () {
    Route::get('/', [ProfessionalDashboardController::class, 'index']);
    Route::get('/appointments', [ProfessionalDashboardController::class, 'appointments']);
    Route::get('/profile', [ProfessionalDashboardController::class, 'profile']);
    Route::get('/stats', [ProfessionalDashboardController::class, 'stats']);
});

// Contract Templates
Route::middleware(['auth:sanctum'])->prefix('contract-templates')->group(function () {
    Route::get('/', [ContractTemplateController::class, 'index']);
    Route::post('/', [ContractTemplateController::class, 'store'])->middleware('permission:manage contracts');
    Route::get('/{id}', [ContractTemplateController::class, 'show']);
    Route::put('/{id}', [ContractTemplateController::class, 'update'])->middleware('permission:manage contracts');
    Route::delete('/{id}', [ContractTemplateController::class, 'destroy'])->middleware('permission:manage contracts');
    Route::post('/{id}/preview', [ContractTemplateController::class, 'preview']);
    Route::get('/placeholders/{entityType}', [ContractTemplateController::class, 'getPlaceholders']);
    Route::post('/{id}/export/pdf', [ContractTemplateController::class, 'exportPdf']);
    Route::post('/{id}/export/docx', [ContractTemplateController::class, 'exportDocx']);
});

// Negotiations
Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('negotiations')->group(function () {
        Route::get('/', [NegotiationController::class, 'index'])
            ->middleware('permission:view negotiations');
        Route::post('/', [NegotiationController::class, 'store'])
            ->middleware('permission:create negotiations');
        Route::get('/{negotiation}', [NegotiationController::class, 'show'])
            ->middleware('permission:view negotiations');
        Route::put('/{negotiation}', [NegotiationController::class, 'update'])
            ->middleware('permission:edit negotiations');
        Route::post('/{negotiation}/submit', [NegotiationController::class, 'submit'])
            ->middleware('permission:approve negotiations');
        Route::post('/{negotiation}/submit-approval', [NegotiationController::class, 'submitForApproval'])
            ->middleware('permission:approve negotiations');
        Route::post('/{negotiation}/process-external-approval', [NegotiationController::class, 'processExternalApproval']);
        Route::post('/{negotiation}/process-approval', [NegotiationController::class, 'processApproval'])
            ->middleware('permission:approve negotiations');
        Route::post('/{negotiation}/formalize', [NegotiationController::class, 'markAsFormalized'])
            ->middleware('permission:formalize negotiations');
        Route::post('/{negotiation}/mark-complete', [NegotiationController::class, 'markAsComplete'])
            ->middleware('permission:edit negotiations');
        Route::post('/{negotiation}/mark-partially-complete', [NegotiationController::class, 'markAsPartiallyComplete'])
            ->middleware('permission:edit negotiations');
        Route::post('/{negotiation}/cancel', [NegotiationController::class, 'cancel'])
            ->middleware('permission:edit negotiations');
        Route::post('/{negotiation}/generate-contract', [NegotiationController::class, 'generateContract'])
            ->middleware('permission:edit negotiations');
        Route::post('/{negotiation}/resend-notifications', [NegotiationController::class, 'resendNotifications'])
            ->middleware('permission:edit negotiations');
        Route::post('/{negotiation}/batch-counter', [NegotiationController::class, 'batchCounterOffer'])
            ->middleware('permission:edit negotiations');
        Route::get('/announcements', [NegotiationController::class, 'getAnnouncements']);
        Route::get('/{negotiation}/approval-history', [NegotiationController::class, 'getApprovalHistory'])
            ->middleware('permission:view negotiation history');
        
        // Items routes
        Route::post('/items/{item}/respond', [NegotiationController::class, 'respondToItem'])
            ->middleware('permission:edit negotiations');
        Route::post('/items/{item}/counter', [NegotiationController::class, 'counterItem'])
            ->middleware('permission:edit negotiations');

        // Ciclos de renegociação
        Route::post('/{negotiation}/cycles', [NegotiationController::class, 'startNewCycle'])
            ->middleware(['permission:edit negotiations']);

        // Rollback no fluxo
        Route::post('/{negotiation}/rollback', [NegotiationController::class, 'rollbackStatus'])
            ->middleware(['permission:edit negotiations']);

        // Bifurcação de fluxo
        Route::post('/{negotiation}/fork', [NegotiationController::class, 'forkNegotiation'])
            ->middleware(['permission:edit negotiations']);
    });
});

// WhatsApp notifications routes
Route::middleware(['auth:sanctum'])->prefix('whatsapp')->group(function () {
    Route::post('/appointment-reminder', [WhatsAppNotificationController::class, 'sendAppointmentReminder']);
    Route::post('/appointment-confirmation', [WhatsAppNotificationController::class, 'sendAppointmentConfirmation']);
    Route::post('/appointment-cancellation', [WhatsAppNotificationController::class, 'sendAppointmentCancellation']);
    Route::post('/nps-survey', [WhatsAppNotificationController::class, 'sendNpsSurvey']);
    Route::post('/appointment-notifications', [WhatsAppNotificationController::class, 'sendAppointmentNotifications']);
});

// SURI Chatbot Integration
Route::prefix('chatbot')->group(function () {
    Route::post('/webhook', [SuriChatbotController::class, 'webhook']);
    
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/send', [SuriChatbotController::class, 'sendMessage']);
    });
});

// LGPD Data Privacy
Route::middleware(['auth:sanctum'])->prefix('privacy')->group(function () {
    Route::get('/consents', [DataPrivacyController::class, 'getConsents']);
    Route::post('/consents', [DataPrivacyController::class, 'storeConsent']);
    Route::delete('/consents/{id}', [DataPrivacyController::class, 'revokeConsent']);
    Route::post('/export-data', [DataPrivacyController::class, 'requestDataExport']);
    Route::post('/request-deletion', [DataPrivacyController::class, 'requestAccountDeletion']);
    Route::get('/info', [DataPrivacyController::class, 'getPrivacyInfo']);
});

// Billing Rules
Route::middleware(['auth:sanctum'])->prefix('billing-rules')->group(function () {
    Route::get('/', [BillingRuleController::class, 'index']);
    Route::post('/', [BillingRuleController::class, 'store'])->middleware('permission:manage financials');
    Route::get('/{id}', [BillingRuleController::class, 'show']);
    Route::put('/{id}', [BillingRuleController::class, 'update'])->middleware('permission:manage financials');
    Route::delete('/{id}', [BillingRuleController::class, 'destroy'])->middleware('permission:manage financials');
    Route::patch('/{id}/toggle-active', [BillingRuleController::class, 'toggleActive'])->middleware('permission:manage financials');
    Route::post('/applicable', [BillingRuleController::class, 'getApplicableRules']);
    Route::post('/simulate', [BillingRuleController::class, 'simulateBilling']);
});

// Dashboard routes
Route::middleware(['auth:sanctum'])->prefix('dashboard')->group(function () {
    Route::get('/stats', [DashboardController::class, 'getStats']);
    Route::get('/appointments/upcoming', [DashboardController::class, 'getUpcomingAppointments']);
    Route::get('/appointments/today', [DashboardController::class, 'getTodayAppointments']);
    Route::get('/suri/stats', [DashboardController::class, 'getSuriStats']);
    Route::get('/pending/items', [DashboardController::class, 'getPendingItems']);
});

// WhatsApp routes
Route::middleware(['auth:sanctum'])->prefix('whatsapp')->group(function () {
    Route::get('messages', [WhatsappController::class, 'index']);
    Route::get('messages/{id}', [WhatsappController::class, 'show']);
    Route::post('send/text', [WhatsappController::class, 'sendText']);
    Route::post('send/media', [WhatsappController::class, 'sendMedia']);
    Route::post('send/template', [WhatsappController::class, 'sendTemplate']);
    Route::post('test/template', [WhatsappController::class, 'testTemplate']);
    Route::post('test/conecta-template', [WhatsappController::class, 'testConectaTemplate']);
    Route::post('test/simple', [WhatsappController::class, 'sendSimpleTest']);
    Route::post('resend/{id}', [WhatsappController::class, 'resend']);
    Route::get('statistics', [WhatsappController::class, 'statistics']);
    Route::match(['get', 'post'], 'webhook', [WhatsappController::class, 'webhook'])->withoutMiddleware('auth:sanctum');
});

// Additional route outside the middleware group for testing
Route::post('/api/whatsapp/test/simple', [App\Http\Controllers\Api\WhatsappController::class, 'sendSimpleTest']);

// Auth routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/user', [AuthController::class, 'user'])->middleware('auth:sanctum');
    
    // Password reset routes
    Route::post('/password/reset-request', [AuthController::class, 'requestPasswordReset']);
    Route::post('/password/validate-token', [AuthController::class, 'validateResetToken']);
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);
});

// Audit Log routes
Route::middleware(['auth:sanctum', 'permission:view audit logs'])->prefix('audit-logs')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\AuditController::class, 'index']);
    Route::get('/statistics', [App\Http\Controllers\Api\AuditController::class, 'statistics']);
    Route::get('/{id}', [App\Http\Controllers\Api\AuditController::class, 'show']);
    Route::post('/model', [App\Http\Controllers\Api\AuditController::class, 'getModelAudit']);
});

// Contract Approval routes
Route::prefix('contract-approvals')->group(function () {
    Route::get('/', 'Api\ContractApprovalController@index');
    Route::post('/{id}/submit', 'Api\ContractApprovalController@submitForApproval');
    Route::post('/{id}/legal-review', 'Api\ContractApprovalController@legalReview');
    Route::post('/{id}/commercial-review', 'Api\ContractApprovalController@commercialReview');
    Route::post('/{id}/director-approval', 'Api\ContractApprovalController@directorApproval');
    Route::get('/{id}/history', 'Api\ContractApprovalController@approvalHistory');
});

// Extemporaneous Negotiation routes
Route::prefix('extemporaneous-negotiations')->group(function () {
    Route::get('/', 'Api\ExtemporaneousNegotiationController@index');
    Route::post('/', 'Api\ExtemporaneousNegotiationController@store');
    Route::get('/{id}', 'Api\ExtemporaneousNegotiationController@show');
    Route::post('/{id}/approve', 'Api\ExtemporaneousNegotiationController@approve');
    Route::post('/{id}/reject', 'Api\ExtemporaneousNegotiationController@reject');
    Route::post('/{id}/addendum', 'Api\ExtemporaneousNegotiationController@markAsAddendumIncluded');
    Route::get('/contract/{contractId}', 'Api\ExtemporaneousNegotiationController@listByContract');
});

// Specialty Negotiation routes
Route::middleware(['auth:sanctum'])->prefix('specialty-negotiations')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\SpecialtyNegotiationController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\SpecialtyNegotiationController::class, 'store'])->middleware('permission:create negotiations');
    Route::get('/{id}', [App\Http\Controllers\Api\SpecialtyNegotiationController::class, 'show']);
    Route::post('/{id}/submit', [App\Http\Controllers\Api\SpecialtyNegotiationController::class, 'submit'])->middleware('permission:create negotiations');
    Route::post('/{id}/approve', [App\Http\Controllers\Api\SpecialtyNegotiationController::class, 'approve'])->middleware('permission:manage negotiations');
    Route::post('/{id}/reject', [App\Http\Controllers\Api\SpecialtyNegotiationController::class, 'reject'])->middleware('permission:manage negotiations');
    Route::get('/pricing/{entity_type}/{entity_id}', [App\Http\Controllers\Api\SpecialtyNegotiationController::class, 'getProcedurePricing']);
});

// Value Verification routes
Route::middleware(['auth:sanctum'])->prefix('value-verifications')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\ValueVerificationController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\ValueVerificationController::class, 'store'])->middleware('permission:create value_verifications');
    Route::get('/{id}', [App\Http\Controllers\Api\ValueVerificationController::class, 'show']);
    Route::post('/{id}/verify', [App\Http\Controllers\Api\ValueVerificationController::class, 'verify'])->middleware('role:director,super_admin');
    Route::post('/{id}/reject', [App\Http\Controllers\Api\ValueVerificationController::class, 'reject'])->middleware('role:director,super_admin');
    Route::get('/pending/count', [App\Http\Controllers\Api\ValueVerificationController::class, 'getPendingCount']);
});

// Entity Document Types
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/entity-document-types', [EntityDocumentTypeController::class, 'index']);
    Route::post('/entity-document-types', [EntityDocumentTypeController::class, 'store']);
    Route::put('/entity-document-types/{documentType}', [EntityDocumentTypeController::class, 'update']);
    Route::delete('/entity-document-types/{documentType}', [EntityDocumentTypeController::class, 'destroy']);
});

// Appointment routing
Route::prefix('appointments')->group(function () {
    Route::get('/', 'App\Http\Controllers\Api\AppointmentController@index');
    Route::get('/pending-confirmation', 'App\Http\Controllers\Api\AppointmentController@pendingConfirmation');
    Route::get('/{id}', 'App\Http\Controllers\Api\AppointmentController@getAppointmentDetails');
    Route::post('/schedule', 'App\Http\Controllers\Api\AppointmentController@scheduleAutomatically');
    Route::post('/exception', 'App\Http\Controllers\Api\AppointmentController@requestException');
    Route::put('/{id}/confirm', 'App\Http\Controllers\Api\AppointmentController@confirm');
    Route::get('/{id}/guide', 'App\Http\Controllers\Api\AppointmentController@generateGuide');
    Route::get('/{id}/guide/download', 'App\Http\Controllers\Api\AppointmentController@downloadGuide');
    Route::post('/{id}/attendance', 'App\Http\Controllers\Api\AppointmentController@confirmAttendance');
    Route::post('/{id}/cancel', 'App\Http\Controllers\Api\AppointmentController@cancel');
});

// Scheduling exceptions routing
Route::prefix('scheduling-exceptions')->group(function () {
    Route::get('/', 'App\Http\Controllers\Api\SchedulingExceptionController@index');
    Route::get('/{id}', 'App\Http\Controllers\Api\SchedulingExceptionController@show');
    Route::post('/{id}/approve', 'App\Http\Controllers\Api\SchedulingExceptionController@approve');
    Route::post('/{id}/reject', 'App\Http\Controllers\Api\SchedulingExceptionController@reject');
});

/*
|--------------------------------------------------------------------------
| User Management Routes (Super Admin Only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::get('/users', [App\Http\Controllers\Api\UserManagementController::class, 'index']);
    Route::post('/users', [App\Http\Controllers\Api\UserManagementController::class, 'store']);
    Route::get('/users/{user}', [App\Http\Controllers\Api\UserManagementController::class, 'show']);
    Route::put('/users/{user}', [App\Http\Controllers\Api\UserManagementController::class, 'update']);
    Route::delete('/users/{user}', [App\Http\Controllers\Api\UserManagementController::class, 'destroy']);
    
    Route::get('/roles', [App\Http\Controllers\Api\UserManagementController::class, 'getRoles']);
    Route::get('/permissions', [App\Http\Controllers\Api\UserManagementController::class, 'getPermissions']);
    
    Route::put('/users/{user}/roles', [App\Http\Controllers\Api\UserManagementController::class, 'updateRoles']);
    Route::put('/users/{user}/permissions', [App\Http\Controllers\Api\UserManagementController::class, 'updatePermissions']);
});

// Novas rotas para confirmação e verificação de agendamentos
Route::get('/appointments/pending-confirmations', [AppointmentController::class, 'getPendingConfirmations'])
    ->middleware(['auth:sanctum', 'permission:manage_appointments']);
    
Route::post('/appointments/{id}/confirm-48h', [AppointmentController::class, 'confirm48h'])
    ->middleware(['auth:sanctum', 'permission:manage_appointments']);
    
Route::post('/appointments/{id}/send-guide', [AppointmentController::class, 'sendGuide'])
    ->middleware(['auth:sanctum', 'permission:manage_appointments']);
    
Route::get('/appointments/{id}/download-guide', [AppointmentController::class, 'downloadGuide'])
    ->middleware(['auth:sanctum', 'permission:manage_appointments']);
    
Route::post('/appointments/{id}/generate-verification', [AppointmentController::class, 'generateVerificationToken'])
    ->middleware(['auth:sanctum', 'permission:manage_appointments']);
    
// Rotas públicas para verificação de agendamentos (acessíveis por token)
Route::get('/appointments/verify/{token}', [AppointmentController::class, 'verifyAppointment']);
Route::post('/appointments/confirm/{token}', [AppointmentController::class, 'confirmAppointment']);

// Health Plan Billing Routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/health-plans/{healthPlanId}/billing', [HealthPlanBillingController::class, 'index']);
    Route::get('/health-plans/{healthPlanId}/billing/export', [HealthPlanBillingController::class, 'exportCsv']);
    Route::get('/health-plans/{healthPlanId}/billing/overview', [HealthPlanBillingController::class, 'overview']);
});

/*
|--------------------------------------------------------------------------
| Rotas de Especialidades Médicas
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    Route::prefix('medical-specialties')->group(function () {
        // Rotas públicas (apenas autenticação necessária)
        Route::get('/', [MedicalSpecialtyController::class, 'index']);
        
        // Rotas que requerem permissões específicas
        Route::middleware('permission:manage medical specialties')->group(function () {
            Route::post('/', [MedicalSpecialtyController::class, 'store']);
            Route::put('/{specialty}', [MedicalSpecialtyController::class, 'update']);
        });

        // Rotas relacionadas a negociações
        Route::middleware('permission:manage negotiations')->group(function () {
            Route::get('/{specialty}/negotiations', [MedicalSpecialtyController::class, 'getActiveNegotiations']);
            Route::post('/prices/{price}/approve', [MedicalSpecialtyController::class, 'approvePrice']);
        });
    });
});

// Professional scheduling response
Route::post('/appointments/professional-response', [AppointmentController::class, 'handleProfessionalResponse'])
    ->middleware(['auth:sanctum', 'role:professional']); 