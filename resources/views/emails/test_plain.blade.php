Email de Teste - {{ config('app.name') }}

{{ $content }}

Este é um email de teste enviado em: {{ now()->format('d/m/Y H:i:s') }}

Se você recebeu este email, a configuração do servidor de email está funcionando corretamente.

{{ config('app.name') }} 