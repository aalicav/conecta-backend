@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #3182ce; margin-bottom: 20px;">Negociação Submetida</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Uma negociação foi submetida para análise e requer sua atenção.</p>
    
    <div style="background-color: #f7fafc; border-left: 4px solid #4299e1; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Criada por:</strong> {{ $negotiation->creator->name }}</p>
        <p><strong>Itens:</strong> {{ $negotiation->items->count() }}</p>
        <p><strong>Data de início:</strong> {{ date('d/m/Y', strtotime($negotiation->start_date)) }}</p>
        <p><strong>Data de término:</strong> {{ date('d/m/Y', strtotime($negotiation->end_date)) }}</p>
    </div>
    
    <p>Por favor, analise os detalhes e responda dentro do prazo estabelecido.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #4299e1; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Revisar Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 