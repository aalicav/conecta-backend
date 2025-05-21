@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #3182ce; margin-bottom: 20px;">Aprovação de Negociação Necessária</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Uma negociação requer sua aprovação.</p>
    
    <div style="background-color: #f7fafc; border-left: 4px solid #4299e1; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Nível de aprovação:</strong> {{ ucfirst($approvalLevel) }}</p>
        <p><strong>Solicitado por:</strong> {{ $negotiation->creator->name }}</p>
        <p><strong>Data de solicitação:</strong> {{ date('d/m/Y H:i', strtotime($negotiation->submitted_for_approval_at)) }}</p>
        @if(isset($negotiation->submission_notes) && $negotiation->submission_notes)
            <p><strong>Observações:</strong> {{ $negotiation->submission_notes }}</p>
        @endif
    </div>
    
    <p>Por favor, revise os detalhes e tome uma decisão sobre esta negociação o mais breve possível.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #4299e1; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Revisar e Aprovar</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 