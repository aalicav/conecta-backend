<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NFe Environment
    |--------------------------------------------------------------------------
    |
    | 1 = Production
    | 2 = Homologation
    |
    */
    'environment' => env('NFE_ENVIRONMENT', 2),

    /*
    |--------------------------------------------------------------------------
    | Company Information
    |--------------------------------------------------------------------------
    |
    | Basic company information for NFe emission
    |
    */
    'company_name' => env('NFE_COMPANY_NAME'),
    'cnpj' => env('NFE_CNPJ'),
    'ie' => env('NFE_IE'),
    'crt' => env('NFE_CRT', '1'), // 1-Simples Nacional, 2-Simples Nacional com excesso, 3-Regime Normal

    /*
    |--------------------------------------------------------------------------
    | Address Information
    |--------------------------------------------------------------------------
    |
    | Company address information for NFe emission
    |
    */
    'address' => [
        'street' => env('NFE_ADDRESS_STREET'),
        'number' => env('NFE_ADDRESS_NUMBER'),
        'district' => env('NFE_ADDRESS_DISTRICT'),
        'city' => env('NFE_ADDRESS_CITY'),
        'city_code' => env('NFE_ADDRESS_CITY_CODE'),
        'state' => env('NFE_ADDRESS_STATE'),
        'zipcode' => env('NFE_ADDRESS_ZIPCODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate Information
    |--------------------------------------------------------------------------
    |
    | Digital certificate information for NFe signing
    |
    */
    'certificate_path' => env('NFE_CERTIFICATE_PATH', 'certificates/certificate.pfx'),
    'certificate_password' => env('NFE_CERTIFICATE_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Additional Settings
    |--------------------------------------------------------------------------
    |
    | Additional settings for NFe emission
    |
    */
    'ibpt_token' => env('NFE_IBPT_TOKEN'),
    'csc' => env('NFE_CSC'),
    'csc_id' => env('NFE_CSC_ID'),
]; 