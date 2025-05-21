@extends('emails.layout')

@section('content')
<div style="padding: 20px;">
    <h2 style="color: #dd6b20; margin-bottom: 20px;">Negociação Parcialmente Aprovada</h2>
    
    <p>Olá {{ $recipient->name }},</p>
    
    <p>Informamos que uma negociação foi parcialmente aprovada e requer sua atenção.</p>
    
    <div style="background-color: #fffaf0; border-left: 4px solid #ed8936; padding: 15px; margin: 20px 0;">
        <p><strong>Título:</strong> {{ $negotiation->title }}</p>
        <p><strong>Entidade:</strong> {{ $negotiation->negotiable->name }}</p>
        <p><strong>Aprovada por:</strong> {{ $approver->name }}</p>
        <p><strong>Data de aprovação parcial:</strong> {{ date('d/m/Y H:i', strtotime($negotiation->approved_at)) }}</p>
        
        <div style="margin-top: 15px;">
            <p><strong>Resumo dos itens:</strong></p>
            <ul style="margin-top: 5px;">
                <li>Itens aprovados: {{ $approvedItemsCount }}</li>
                <li>Itens rejeitados: {{ $rejectedItemsCount }}</li>
                <li>Total de itens: {{ $totalItemsCount }}</li>
            </ul>
        </div>
        
        @if(isset($negotiation->approval_notes) && $negotiation->approval_notes)
            <p><strong>Observações:</strong> {{ $negotiation->approval_notes }}</p>
        @endif
    </div>
    
    <p>É necessário revisar os itens que não foram aprovados e possivelmente iniciar um novo ciclo de negociação para eles.</p>
    
    <div style="text-align: center;">
        <a href="{{ $actionUrl }}" class="button" style="background-color: #ed8936; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 4px; font-weight: 600; display: inline-block;">Revisar Negociação</a>
    </div>
    
    <p style="margin-top: 20px;">Atenciosamente,<br>Equipe {{ config('app.name') }}</p>
</div>
@endsection 