<?php

namespace App\Notifications\Messages;

class WhatsAppMessage
{
    public string $templateName;
    public array $parameters = [];
    public string $recipientPhone;

    /**
     * Set the Twilio template name.
     */
    public function template(string $templateName): self
    {
        $this->templateName = $templateName;
        return $this;
    }

    /**
     * Set the parameters for the template.
     */
    public function parameters(array $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }

    /**
     * Set the recipient's phone number.
     */
    public function to(string $recipientPhone): self
    {
        if (empty($recipientPhone)) {
            throw new \InvalidArgumentException('Recipient phone number cannot be empty or null');
        }
        
        $this->recipientPhone = $recipientPhone;
        return $this;
    }
} 