<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewWhatsAppMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $phone;
    public $message;
    public $profileName;
    public $timestamp;

    /**
     * Create a new event instance.
     *
     * @param string $phone
     * @param string $message
     * @param string|null $profileName
     * @return void
     */
    public function __construct(string $phone, string $message, ?string $profileName = null)
    {
        $this->phone = $phone;
        $this->message = $message;
        $this->profileName = $profileName;
        $this->timestamp = now();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('whatsapp-messages');
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'phone' => $this->phone,
            'message' => $this->message,
            'profile_name' => $this->profileName,
            'timestamp' => $this->timestamp->toISOString(),
            'type' => 'new_message',
        ];
    }

    /**
     * Get the event name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'whatsapp.message.received';
    }
} 