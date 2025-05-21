@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #dd6b20; margin-bottom: 20px;">Contraproposta Recebida</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Recebemos uma contraproposta para um item da negociação "{{ $negotiation->title }}".</p>
    
    <div style="background-color: #fffaf0; border-left: 4px solid #ed8936; padding: 15px; margin: 20px 0;">
        <p><strong>Negociação:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Procedimento:</strong> {{ $item->tuss->code }} - {{ $item->tuss->name }}</p>
        <p><strong>Valor originalmente proposto:</strong> R$ {{ number_format($item->proposed_value, 2, ',', '.') }}</p>
        <p><strong>Valor contraproposto:</strong> R$ {{ number_format($item->approved_value, 2, ',', '.') }}</p>
        <p><strong>Diferença:</strong> {{ number_format(($item->approved_value - $item->proposed_value) / $item->proposed_value * 100, 2, ',', '.') }}%</p>
        
        @if($item->notes)
            <p><strong>Observações:</strong> {{ $item->notes }}</p>
        @endif
        
        <p><strong>Contraproposta por:</strong> {{ $counterOfferor->name }}</p>
        <p><strong>Data da contraproposta:</strong> {{ date('d/m/Y H:i') }}</p>
    </div>
    
    <p>Por favor, revise esta contraproposta e tome as medidas necessárias.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #ed8936; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Analisar Contraproposta</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 