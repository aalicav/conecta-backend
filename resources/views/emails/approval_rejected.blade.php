@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #e53e3e; margin-bottom: 20px;">Aprovação de Negociação Rejeitada</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Informamos que uma solicitação de aprovação de negociação foi rejeitada.</p>
    
    <div style="background-color: #fff5f5; border-left: 4px solid #fc8181; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Rejeitada por:</strong> {{ $rejector->name }}</p>
        <p><strong>Data da rejeição:</strong> {{ date('d/m/Y H:i', strtotime($negotiation->rejected_at)) }}</p>
        
        @if($negotiation->rejection_reason)
            <p><strong>Motivo da rejeição:</strong> {{ $negotiation->rejection_reason }}</p>
        @endif
    </div>
    
    <p>Por favor, revise os detalhes da negociação, faça as alterações necessárias e submeta novamente para aprovação quando estiver pronta.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #718096; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Revisar Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 