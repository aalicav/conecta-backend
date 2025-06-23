<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Report Types
    |--------------------------------------------------------------------------
    |
    | This array defines the available report types and their configurations.
    |
    */
    'types' => [
        'appointments' => [
            'name' => 'Relatório de Agendamentos',
            'description' => 'Relatório detalhado de agendamentos com filtros por data, status, cidade e estado.',
            'formats' => ['pdf', 'csv', 'xlsx'],
            'filters' => [
                'start_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Inicial'
                ],
                'end_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Final'
                ],
                'status' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Status',
                    'options' => [
                        'scheduled' => 'Agendado',
                        'confirmed' => 'Confirmado',
                        'completed' => 'Concluído',
                        'cancelled' => 'Cancelado',
                        'missed' => 'Faltou'
                    ]
                ],
                'city' => [
                    'type' => 'text',
                    'required' => false,
                    'label' => 'Cidade'
                ],
                'state' => [
                    'type' => 'text',
                    'required' => false,
                    'label' => 'Estado'
                ],
                'health_plan_id' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Plano de Saúde',
                    'options_from' => 'health_plans'
                ],
                'professional_id' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Profissional',
                    'options_from' => 'professionals'
                ],
                'clinic_id' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Clínica',
                    'options_from' => 'clinics'
                ]
            ]
        ],
        'professionals' => [
            'name' => 'Relatório de Profissionais',
            'description' => 'Relatório detalhado de profissionais com filtros por especialidade, cidade e estado.',
            'formats' => ['pdf', 'csv', 'xlsx'],
            'filters' => [
                'status' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Status',
                    'options' => [
                        'pending' => 'Pendente',
                        'approved' => 'Aprovado',
                        'rejected' => 'Rejeitado'
                    ]
                ],
                'specialty' => [
                    'type' => 'text',
                    'required' => false,
                    'label' => 'Especialidade'
                ],
                'city' => [
                    'type' => 'text',
                    'required' => false,
                    'label' => 'Cidade'
                ],
                'state' => [
                    'type' => 'text',
                    'required' => false,
                    'label' => 'Estado'
                ],
                'clinic_id' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Clínica',
                    'options_from' => 'clinics'
                ],
                'start_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Inicial do Cadastro'
                ],
                'end_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Final do Cadastro'
                ]
            ]
        ],
        'clinics' => [
            'name' => 'Relatório de Clínicas',
            'description' => 'Relatório detalhado de clínicas com filtros por cidade e estado.',
            'formats' => ['pdf', 'csv', 'xlsx'],
            'filters' => [
                'status' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Status',
                    'options' => [
                        'pending' => 'Pendente',
                        'approved' => 'Aprovado',
                        'rejected' => 'Rejeitado'
                    ]
                ],
                'city' => [
                    'type' => 'text',
                    'required' => false,
                    'label' => 'Cidade'
                ],
                'state' => [
                    'type' => 'text',
                    'required' => false,
                    'label' => 'Estado'
                ],
                'start_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Inicial do Cadastro'
                ],
                'end_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Final do Cadastro'
                ]
            ]
        ],
        'financial' => [
            'name' => 'Relatório Financeiro',
            'description' => 'Relatório detalhado financeiro com filtros por data e status.',
            'formats' => ['pdf', 'csv', 'xlsx'],
            'filters' => [
                'start_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Inicial'
                ],
                'end_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Final'
                ],
                'status' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Status',
                    'options' => [
                        'pending' => 'Pendente',
                        'paid' => 'Pago',
                        'overdue' => 'Atrasado'
                    ]
                ],
                'health_plan_id' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Plano de Saúde',
                    'options_from' => 'health_plans'
                ]
            ]
        ],
        'billing' => [
            'name' => 'Relatório de Faturamento',
            'description' => 'Relatório detalhado de faturamento com filtros por data e status.',
            'formats' => ['pdf', 'csv', 'xlsx'],
            'filters' => [
                'start_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Inicial'
                ],
                'end_date' => [
                    'type' => 'date',
                    'required' => false,
                    'label' => 'Data Final'
                ],
                'status' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Status',
                    'options' => [
                        'pending' => 'Pendente',
                        'paid' => 'Pago',
                        'overdue' => 'Atrasado'
                    ]
                ],
                'health_plan_id' => [
                    'type' => 'select',
                    'required' => false,
                    'label' => 'Plano de Saúde',
                    'options_from' => 'health_plans'
                ]
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Storage
    |--------------------------------------------------------------------------
    |
    | This option controls where the generated reports will be stored.
    |
    */
    'storage' => [
        'disk' => 'public',
        'path' => 'reports',
        'retention_days' => 30, // How long to keep generated reports
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Formats
    |--------------------------------------------------------------------------
    |
    | This array defines the available export formats and their configurations.
    |
    */
    'formats' => [
        'pdf' => [
            'name' => 'PDF',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'options' => [
                'paper' => 'a4',
                'orientation' => 'portrait'
            ]
        ],
        'csv' => [
            'name' => 'CSV',
            'mime_type' => 'text/csv',
            'extension' => 'csv',
            'options' => [
                'delimiter' => ',',
                'enclosure' => '"',
                'line_ending' => "\r\n",
                'use_bom' => true,
                'include_header' => true
            ]
        ],
        'xlsx' => [
            'name' => 'Excel',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'extension' => 'xlsx',
            'options' => [
                'include_header' => true,
                'auto_size_columns' => true
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Permissions
    |--------------------------------------------------------------------------
    |
    | This array defines which roles can access which report types.
    |
    */
    'permissions' => [
        'appointments' => ['admin', 'clinic_admin', 'professional', 'plan_admin'],
        'professionals' => ['admin', 'clinic_admin', 'plan_admin'],
        'clinics' => ['admin', 'plan_admin'],
        'financial' => ['admin', 'plan_admin'],
        'billing' => ['admin', 'plan_admin']
    ]
]; 