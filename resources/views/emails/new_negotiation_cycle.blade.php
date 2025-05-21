@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #3182ce; margin-bottom: 20px;">Novo Ciclo de Negociação Iniciado</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Um novo ciclo de negociação foi iniciado para "{{ $negotiation->title }}".</p>
    
    <div style="background-color: #f7fafc; border-left: 4px solid #4299e1; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Ciclo atual:</strong> {{ $negotiation->negotiation_cycle }}</p>
        <p><strong>Iniciado por:</strong> {{ $initiator->name }}</p>
        <p><strong>Data de início do ciclo:</strong> {{ date('d/m/Y H:i') }}</p>
        
        <div style="margin-top: 15px;">
            <p><strong>Status do ciclo anterior:</strong> {{ ucfirst($previousStatus) }}</p>
            <p><strong>Resumo de itens pendentes:</strong> {{ $pendingItemsCount }} de {{ $totalItemsCount }}</p>
        </div>
    </div>
    
    <p>Por favor, revise os itens pendentes desta negociação e forneça suas respostas o mais breve possível.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #4299e1; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 