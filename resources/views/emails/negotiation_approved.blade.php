@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #38a169; margin-bottom: 20px;">Negociação Aprovada Internamente</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Informamos que uma negociação recebeu aprovação interna completa.</p>
    
    <div style="background-color: #f0fff4; border-left: 4px solid #68d391; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Aprovação inicial:</strong> {{ date('d/m/Y H:i', strtotime($negotiation->approved_at)) }}</p>
        <p><strong>Aprovação final:</strong> {{ date('d/m/Y H:i', strtotime($negotiation->director_approved_at)) }}</p>
        <p><strong>Aprovado por:</strong> {{ $approver->name }}</p>
        
        @if(isset($negotiation->approval_notes) && $negotiation->approval_notes)
            <p><strong>Observações (aprovação inicial):</strong> {{ $negotiation->approval_notes }}</p>
        @endif
        
        @if(isset($negotiation->director_approval_notes) && $negotiation->director_approval_notes)
            <p><strong>Observações (aprovação final):</strong> {{ $negotiation->director_approval_notes }}</p>
        @endif
    </div>
    
    <p>A negociação agora está aprovada e pronta para ser implementada. Por favor, proceda com os próximos passos para conclusão do processo.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #38a169; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 