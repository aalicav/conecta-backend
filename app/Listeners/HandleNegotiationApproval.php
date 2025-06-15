<?php

namespace App\Listeners;

use App\Events\NegotiationApproved;
use App\Jobs\ProcessNegotiationFormalization;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleNegotiationApproval implements ShouldQueue
{
    protected $notificationService;

    /**
     * Create the event listener.
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     *
     * @param  \App\Events\NegotiationApproved  $event
     * @return void
     */
    public function handle(NegotiationApproved $event)
    {
        $negotiation = $event->negotiation;

        // Send notification to the creator
        $this->notificationService->sendToUser($negotiation->creator_id, [
            'title' => 'Negociação Aprovada',
            'body' => "Sua negociação #{$negotiation->id} foi aprovada.",
            'action_link' => "/negotiations/{$negotiation->id}",
            'priority' => 'high'
        ]);

        // Send notification to commercial team about formalization needed
        $this->notificationService->sendToRole('commercial', [
            'title' => 'Negociação Aguardando Formalização',
            'body' => "A negociação #{$negotiation->id} foi aprovada e precisa ser formalizada.",
            'action_link' => "/negotiations/{$negotiation->id}",
            'priority' => 'high'
        ]);

        // Record in approval history
        $negotiation->recordApprovalHistory(
            'commercial',
            'approved',
            $negotiation->approved_by,
            'Negotiation approved'
        );

        // Dispatch the formalization job
        ProcessNegotiationFormalization::dispatch($negotiation)
            ->delay(now()->addMinutes(5)); // Give some time for the notifications to be sent
    }
} 