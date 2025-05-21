@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #805ad5; margin-bottom: 20px;">Status da Negociação Revertido</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Informamos que o status de uma negociação foi revertido para um estado anterior.</p>
    
    <div style="background-color: #f8f4ff; border-left: 4px solid #b794f4; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Status anterior:</strong> {{ ucfirst($previousStatus) }}</p>
        <p><strong>Novo status:</strong> {{ ucfirst($currentStatus) }}</p>
        <p><strong>Revertido por:</strong> {{ $reverter->name }}</p>
        <p><strong>Data da reversão:</strong> {{ date('d/m/Y H:i') }}</p>
        
        @if($reason)
            <p><strong>Motivo da reversão:</strong> {{ $reason }}</p>
        @endif
    </div>
    
    <p>Por favor, verifique a negociação e, se necessário, tome as medidas apropriadas de acordo com o novo status.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #805ad5; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 