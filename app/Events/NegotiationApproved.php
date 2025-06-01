<?php

namespace App\Events;

use App\Models\Negotiation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NegotiationApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $negotiation;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function __construct(Negotiation $negotiation)
    {
        $this->negotiation = $negotiation;
    }
} 