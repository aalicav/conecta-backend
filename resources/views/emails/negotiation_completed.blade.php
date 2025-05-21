@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #38a169; margin-bottom: 20px;">Negociação Concluída com Sucesso</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Temos o prazer de informar que uma negociação foi concluída com sucesso!</p>
    
    <div style="background-color: #f0fff4; border-left: 4px solid #68d391; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Data de conclusão:</strong> {{ date('d/m/Y', strtotime($negotiation->completed_at)) }}</p>
        <p><strong>Vigência:</strong> {{ date('d/m/Y', strtotime($negotiation->start_date)) }} a {{ date('d/m/Y', strtotime($negotiation->end_date)) }}</p>
        
        <div style="margin-top: 15px;">
            <p><strong>Resumo dos itens:</strong></p>
            <ul style="margin-top: 5px;">
                <li>Total de itens aprovados: {{ $negotiation->items->count() }}</li>
                <li>Valor total negociado: R$ {{ number_format($totalValue, 2, ',', '.') }}</li>
            </ul>
        </div>
    </div>
    
    <p>A documentação contratual será gerada em breve e enviada para as partes envolvidas.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #38a169; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Ver Detalhes</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 