<?php

namespace App\Notifications\Messages;

class WhatsAppMessage
{
    public string $templateName;
    public array $parameters = [];
    public array $variables = [];
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
        $this->variables = $parameters; // Keep in sync for backwards compatibility
        return $this;
    }

    /**
     * Set the variables for the template (alias for parameters).
     */
    public function variables(array $variables): self
    {
        $this->variables = $variables;
        $this->parameters = $variables; // Keep in sync for backwards compatibility
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

    /**
     * Get the template variables (prioritize variables over parameters).
     */
    public function getVariables(): array
    {
        return !empty($this->variables) ? $this->variables : $this->parameters;
    }
} 