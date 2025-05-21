@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #e53e3e; margin-bottom: 20px;">Negociação Cancelada</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Informamos que uma negociação na qual você está envolvido(a) foi cancelada.</p>
    
    <div style="background-color: #fff5f5; border-left: 4px solid #fc8181; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Cancelada por:</strong> {{ $canceller->name }}</p>
        <p><strong>Data de cancelamento:</strong> {{ date('d/m/Y H:i') }}</p>
        @if(isset($reason) && $reason)
            <p><strong>Motivo:</strong> {{ $reason }}</p>
        @endif
    </div>
    
    <p>Se você tiver alguma dúvida sobre este cancelamento, entre em contato com a equipe responsável.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #718096; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Detalhes</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 