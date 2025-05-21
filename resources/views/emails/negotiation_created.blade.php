@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #3182ce; margin-bottom: 20px;">Nova Negociação Criada</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Uma nova negociação foi criada e requer sua atenção.</p>
    
    <div style="background-color: #f7fafc; border-left: 4px solid #4299e1; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Criada por:</strong> {{ $negotiation->creator->name }}</p>
        <p><strong>Data de início:</strong> {{ date('d/m/Y', strtotime($negotiation->start_date)) }}</p>
        <p><strong>Data de término:</strong> {{ date('d/m/Y', strtotime($negotiation->end_date)) }}</p>
        @if($negotiation->description)
            <p><strong>Descrição:</strong> {{ $negotiation->description }}</p>
        @endif
    </div>
    
    <p>Clique no botão abaixo para visualizar os detalhes da negociação:</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #4299e1; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 