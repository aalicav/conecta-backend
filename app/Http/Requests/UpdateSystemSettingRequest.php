<?php

namespace App\Http\Requests;

use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateSystemSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('edit settings');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $setting = SystemSetting::where('key', $this->route('key'))->first();
        
        if (!$setting) {
            return [];
        }

        $rules = [
            'value' => 'required',
            'is_public' => 'sometimes|boolean',
            'description' => 'sometimes|nullable|string',
        ];

        // Add type-specific validation
        switch ($setting->data_type) {
            case 'boolean':
                $rules['value'] = 'required|boolean';
                break;
            case 'integer':
                $rules['value'] = 'required|integer';
                break;
            case 'float':
                $rules['value'] = 'required|numeric';
                break;
            case 'array':
            case 'json':
                $rules['value'] = 'required|array';
                break;
        }

        return $rules;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Handle 'true'/'false' strings for boolean values
        if (is_string($this->value) && 
            in_array(strtolower($this->value), ['true', 'false']) &&
            $this->route('key')) {
            
            $setting = SystemSetting::where('key', $this->route('key'))->first();
            
            if ($setting && $setting->data_type === 'boolean') {
                $this->merge(['value' => strtolower($this->value) === 'true']);
            }
        }
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation(): void
    {
        $key = $this->route('key');
        $setting = SystemSetting::where('key', $key)->first();
        
        if (!$setting) {
            return;
        }

        // Format the value based on data type before DB storage
        $value = $this->value;
        
        if ($setting->data_type === 'array' || $setting->data_type === 'json') {
            // If the value is already an array, we'll convert it to JSON
            $this->merge(['value' => is_array($value) ? json_encode($value) : $value]);
        } elseif ($setting->data_type === 'boolean') {
            // If the value is a boolean, convert to 'true'/'false' string
            $this->merge(['value' => $value ? 'true' : 'false']);
        } elseif (in_array($setting->data_type, ['integer', 'float'])) {
            // Ensure numeric values are properly typed
            $this->merge(['value' => (string) $value]);
        }
    }
} 