@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #3182ce; margin-bottom: 20px;">Resposta a Item de Negociação</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Um item da negociação "{{ $negotiation->title }}" recebeu uma resposta.</p>
    
    <div style="background-color: #f7fafc; border-left: 4px solid #4299e1; padding: 15px; margin: 20px 0;">
        <p><strong>Negociação:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Procedimento:</strong> {{ $item->tuss->code }} - {{ $item->tuss->name }}</p>
        <p><strong>Valor proposto:</strong> R$ {{ number_format($item->proposed_value, 2, ',', '.') }}</p>
        
        @if($item->status == 'approved')
            <p><strong>Status:</strong> <span style="color: #38a169; font-weight: bold;">Aprovado</span></p>
            <p><strong>Valor aprovado:</strong> R$ {{ number_format($item->approved_value, 2, ',', '.') }}</p>
        @elseif($item->status == 'rejected')
            <p><strong>Status:</strong> <span style="color: #e53e3e; font-weight: bold;">Rejeitado</span></p>
        @elseif($item->status == 'counter_offered')
            <p><strong>Status:</strong> <span style="color: #dd6b20; font-weight: bold;">Contraproposta</span></p>
            <p><strong>Valor contraproposto:</strong> R$ {{ number_format($item->approved_value, 2, ',', '.') }}</p>
        @endif
        
        @if($item->notes)
            <p><strong>Observações:</strong> {{ $item->notes }}</p>
        @endif
        
        <p><strong>Respondido por:</strong> {{ $responder->name }}</p>
        <p><strong>Data da resposta:</strong> {{ date('d/m/Y H:i', strtotime($item->responded_at)) }}</p>
    </div>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #4299e1; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 