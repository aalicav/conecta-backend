@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #4c51bf; margin-bottom: 20px;">Negociação Bifurcada</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Informamos que uma negociação foi bifurcada em múltiplas negociações.</p>
    
    <div style="background-color: #f0f0ff; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0;">
        <p><strong>Negociação original:</strong> {{ $originalNegotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $originalNegotiation->negotiable->name }}</p>
        <p><strong>Bifurcada por:</strong> {{ $forker->name }}</p>
        <p><strong>Data da bifurcação:</strong> {{ date('d/m/Y H:i', strtotime($originalNegotiation->forked_at)) }}</p>
        <p><strong>Total de novas negociações:</strong> {{ count($forkedNegotiations) }}</p>
        
        <div style="margin-top: 15px;">
            <p><strong>Novas negociações:</strong></p>
            <ul style="margin-top: 5px;">
                @foreach($forkedNegotiations as $index => $forkedNegotiation)
                    <li>
                        <strong>{{ $index + 1 }}. {{ $forkedNegotiation->title }}</strong><br>
                        <span style="font-size: 0.9em; color: #718096;">{{ $forkedNegotiation->items->count() }} itens | ID: {{ $forkedNegotiation->id }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    
    <p>Cada negociação agora seguirá seu próprio fluxo de aprovação. Por favor, acompanhe cada uma delas separadamente.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #667eea; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Negociações</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 