<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ContractTemplate;
use App\Models\User;

class ContractTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = User::where('email', 'admin@example.com')->first();
        
        if (!$adminUser) {
            $adminUser = User::first();
        }

        $creatorId = $adminUser ? $adminUser->id : 1;

        // Template for Health Plan
        ContractTemplate::create([
            'name' => 'Contrato Padrão - Plano de Saúde',
            'entity_type' => 'health_plan',
            'content' => $this->getHealthPlanTemplate(),
            'placeholders' => json_encode([
                'date',
                'contract_number',
                'start_date',
                'end_date',
                'health_plan.name',
                'health_plan.company_name',
                'health_plan.registration_number',
                'health_plan.email',
                'health_plan.phone',
                'health_plan.address',
                'negotiation.title',
                'negotiation.start_date',
                'negotiation.end_date'
            ]),
            'is_active' => true,
            'created_by' => $creatorId,
        ]);

        // Template for Professional
        ContractTemplate::create([
            'name' => 'Contrato Padrão - Profissional',
            'entity_type' => 'professional',
            'content' => $this->getProfessionalTemplate(),
            'placeholders' => json_encode([
                'date',
                'contract_number',
                'start_date',
                'end_date',
                'professional.name',
                'professional.email',
                'professional.phone',
                'professional.specialization',
                'professional.license_number',
                'negotiation.title',
                'negotiation.start_date',
                'negotiation.end_date'
            ]),
            'is_active' => true,
            'created_by' => $creatorId,
        ]);

        // Template for Clinic
        ContractTemplate::create([
            'name' => 'Contrato Padrão - Clínica',
            'entity_type' => 'clinic',
            'content' => $this->getClinicTemplate(),
            'placeholders' => json_encode([
                'date',
                'contract_number',
                'start_date',
                'end_date',
                'clinic.name',
                'clinic.registration_number',
                'clinic.email',
                'clinic.phone',
                'clinic.address',
                'clinic.director',
                'negotiation.title',
                'negotiation.start_date',
                'negotiation.end_date'
            ]),
            'is_active' => true,
            'created_by' => $creatorId,
        ]);

        // New Contract Template with more detailed sections
        ContractTemplate::create([
            'name' => 'Contrato Completo - Plano de Saúde',
            'entity_type' => 'health_plan',
            'content' => $this->getDetailedHealthPlanTemplate(),
            'placeholders' => json_encode([
                'date',
                'contract_number',
                'start_date',
                'end_date',
                'health_plan.name',
                'health_plan.company_name',
                'health_plan.registration_number',
                'health_plan.email',
                'health_plan.phone',
                'health_plan.address',
                'negotiation.title',
                'negotiation.start_date',
                'negotiation.end_date',
                'procedures'
            ]),
            'is_active' => true,
            'created_by' => $creatorId,
        ]);
    }

    private function getHealthPlanTemplate(): string
    {
        return <<<HTML
<h2 style="text-align: center;">CONTRATO DE PRESTAÇÃO DE SERVIÇOS - PLANO DE SAÚDE</h2>

<p>
    <strong>CONTRATO Nº:</strong> {{contract_number}}<br>
    <strong>DATA:</strong> {{date}}
</p>

<p>Por este instrumento particular, de um lado <strong>{{health_plan.name}}</strong>, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº {{health_plan.registration_number}}, com sede no endereço {{health_plan.address}}, doravante denominada CONTRATANTE, e de outro lado a INVICTA MEDICAL SERVICES, pessoa jurídica de direito privado, doravante denominada CONTRATADA, têm entre si justo e contratado o seguinte:</p>

<h3>CLÁUSULA PRIMEIRA - DO OBJETO</h3>

<p>O presente contrato tem por objeto a prestação de serviços médicos pela CONTRATADA aos beneficiários da CONTRATANTE, conforme especificações contidas no Anexo I deste instrumento.</p>

<h3>CLÁUSULA SEGUNDA - DO PRAZO</h3>

<p>O presente contrato terá vigência de 12 (doze) meses, com início em {{start_date}} e término em {{end_date}}, podendo ser renovado mediante termo aditivo.</p>

<h3>CLÁUSULA TERCEIRA - DOS SERVIÇOS</h3>

<p>A CONTRATADA disponibilizará aos beneficiários da CONTRATANTE os seguintes serviços:</p>
<ul>
    <li>Atendimento médico eletivo em consultório;</li>
    <li>Atendimento médico de urgência e emergência;</li>
    <li>Realização de exames e procedimentos conforme tabela negociada.</li>
</ul>

<h3>CLÁUSULA QUARTA - DOS PREÇOS E CONDIÇÕES DE PAGAMENTO</h3>

<p>Os serviços prestados serão remunerados conforme tabela de procedimentos negociada entre as partes, que integra o presente contrato como Anexo II.</p>

<p>Os pagamentos serão efetuados mensalmente, mediante apresentação de fatura detalhada dos atendimentos realizados no período.</p>

<h3>CLÁUSULA QUINTA - TABELA DE PROCEDIMENTOS</h3>

<p>Os procedimentos e valores acordados nesta negociação ({{negotiation.title}}) têm validade de {{negotiation.start_date}} até {{negotiation.end_date}}.</p>

<table border="1" cellpadding="5" cellspacing="0" width="100%">
    <tr>
        <th>Código</th>
        <th>Procedimento</th>
        <th>Valor</th>
    </tr>
    <!-- Tabela de procedimentos será preenchida dinamicamente -->
    <tr>
        <td colspan="3">Os valores específicos serão inseridos durante a geração do contrato.</td>
    </tr>
</table>

<h3>CLÁUSULA SEXTA - DAS DISPOSIÇÕES GERAIS</h3>

<p>E, por estarem justas e contratadas, as partes assinam o presente contrato em duas vias de igual teor e forma.</p>

<div style="display: flex; justify-content: space-between; margin-top: 50px; text-align: center;">
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>{{health_plan.name}}</strong><br>
        CONTRATANTE</p>
    </div>
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>INVICTA MEDICAL SERVICES</strong><br>
        CONTRATADA</p>
    </div>
</div>
HTML;
    }

    private function getProfessionalTemplate(): string
    {
        return <<<HTML
<h2 style="text-align: center;">CONTRATO DE CREDENCIAMENTO - PROFISSIONAL</h2>

<p>
    <strong>CONTRATO Nº:</strong> {{contract_number}}<br>
    <strong>DATA:</strong> {{date}}
</p>

<p>Por este instrumento particular, de um lado <strong>INVICTA MEDICAL SERVICES</strong>, pessoa jurídica de direito privado, doravante denominada CONTRATANTE, e de outro lado <strong>Dr(a). {{professional.name}}</strong>, profissional da área de saúde, inscrito(a) no Conselho Profissional sob o nº {{professional.license_number}}, doravante denominado(a) CONTRATADO(A), têm entre si justo e contratado o seguinte:</p>

<h3>CLÁUSULA PRIMEIRA - DO OBJETO</h3>

<p>O presente contrato tem por objeto o credenciamento do(a) CONTRATADO(A) para prestação de serviços de saúde na especialidade de {{professional.specialization}} aos beneficiários encaminhados pela CONTRATANTE.</p>

<h3>CLÁUSULA SEGUNDA - DO PRAZO</h3>

<p>O presente contrato terá vigência de 12 (doze) meses, com início em {{start_date}} e término em {{end_date}}, podendo ser renovado mediante termo aditivo.</p>

<h3>CLÁUSULA TERCEIRA - DAS OBRIGAÇÕES</h3>

<p>O(A) CONTRATADO(A) se compromete a:</p>
<ul>
    <li>Atender os beneficiários encaminhados pela CONTRATANTE;</li>
    <li>Cumprir os protocolos e diretrizes estabelecidos;</li>
    <li>Manter atualizados seus dados cadastrais e documentação profissional.</li>
</ul>

<h3>CLÁUSULA QUARTA - DOS HONORÁRIOS</h3>

<p>Os serviços prestados pelo(a) CONTRATADO(A) serão remunerados conforme tabela de procedimentos negociada, que integra o presente contrato como Anexo I.</p>

<h3>CLÁUSULA QUINTA - TABELA DE PROCEDIMENTOS</h3>

<p>Os procedimentos e valores acordados nesta negociação ({{negotiation.title}}) têm validade de {{negotiation.start_date}} até {{negotiation.end_date}}.</p>

<table border="1" cellpadding="5" cellspacing="0" width="100%">
    <tr>
        <th>Código</th>
        <th>Procedimento</th>
        <th>Valor</th>
    </tr>
    <!-- Tabela de procedimentos será preenchida dinamicamente -->
    <tr>
        <td colspan="3">Os valores específicos serão inseridos durante a geração do contrato.</td>
    </tr>
</table>

<h3>CLÁUSULA SEXTA - DAS DISPOSIÇÕES GERAIS</h3>

<p>E, por estarem justas e contratadas, as partes assinam o presente contrato em duas vias de igual teor e forma.</p>

<div style="display: flex; justify-content: space-between; margin-top: 50px; text-align: center;">
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>INVICTA MEDICAL SERVICES</strong><br>
        CONTRATANTE</p>
    </div>
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>Dr(a). {{professional.name}}</strong><br>
        CONTRATADO(A)</p>
    </div>
</div>
HTML;
    }

    private function getClinicTemplate(): string
    {
        return <<<HTML
<h2 style="text-align: center;">CONTRATO DE CREDENCIAMENTO - CLÍNICA</h2>

<p>
    <strong>CONTRATO Nº:</strong> {{contract_number}}<br>
    <strong>DATA:</strong> {{date}}
</p>

<p>Por este instrumento particular, de um lado <strong>INVICTA MEDICAL SERVICES</strong>, pessoa jurídica de direito privado, doravante denominada CONTRATANTE, e de outro lado <strong>{{clinic.name}}</strong>, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº {{clinic.registration_number}}, com sede no endereço {{clinic.address}}, doravante denominada CONTRATADA, neste ato representada por seu responsável técnico, Dr(a). {{clinic.director}}, têm entre si justo e contratado o seguinte:</p>

<h3>CLÁUSULA PRIMEIRA - DO OBJETO</h3>

<p>O presente contrato tem por objeto o credenciamento da CONTRATADA para prestação de serviços de saúde aos beneficiários encaminhados pela CONTRATANTE.</p>

<h3>CLÁUSULA SEGUNDA - DO PRAZO</h3>

<p>O presente contrato terá vigência de 12 (doze) meses, com início em {{start_date}} e término em {{end_date}}, podendo ser renovado mediante termo aditivo.</p>

<h3>CLÁUSULA TERCEIRA - DAS OBRIGAÇÕES</h3>

<p>A CONTRATADA se compromete a:</p>
<ul>
    <li>Atender os beneficiários encaminhados pela CONTRATANTE;</li>
    <li>Manter as instalações em perfeitas condições de higiene e segurança;</li>
    <li>Cumprir os protocolos e diretrizes estabelecidos.</li>
</ul>

<h3>CLÁUSULA QUARTA - DOS VALORES E PAGAMENTOS</h3>

<p>Os serviços prestados pela CONTRATADA serão remunerados conforme tabela de procedimentos negociada, que integra o presente contrato como Anexo I.</p>

<h3>CLÁUSULA QUINTA - TABELA DE PROCEDIMENTOS</h3>

<p>Os procedimentos e valores acordados nesta negociação ({{negotiation.title}}) têm validade de {{negotiation.start_date}} até {{negotiation.end_date}}.</p>

<table border="1" cellpadding="5" cellspacing="0" width="100%">
    <tr>
        <th>Código</th>
        <th>Procedimento</th>
        <th>Valor</th>
    </tr>
    <!-- Tabela de procedimentos será preenchida dinamicamente -->
    <tr>
        <td colspan="3">Os valores específicos serão inseridos durante a geração do contrato.</td>
    </tr>
</table>

<h3>CLÁUSULA SEXTA - DAS DISPOSIÇÕES GERAIS</h3>

<p>E, por estarem justas e contratadas, as partes assinam o presente contrato em duas vias de igual teor e forma.</p>

<div style="display: flex; justify-content: space-between; margin-top: 50px; text-align: center;">
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>INVICTA MEDICAL SERVICES</strong><br>
        CONTRATANTE</p>
    </div>
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>{{clinic.name}}</strong><br>
        CONTRATADA</p>
    </div>
</div>
HTML;
    }

    private function getDetailedHealthPlanTemplate(): string
    {
        return <<<HTML
<h1 style="text-align: center;">CONTRATO DE CREDENCIAMENTO E PRESTAÇÃO DE SERVIÇOS</h1>
<h2 style="text-align: center;">PLANO DE SAÚDE E REDE CREDENCIADA</h2>

<p style="text-align: right;">
    <strong>CONTRATO Nº:</strong> {{contract_number}}<br>
    <strong>DATA:</strong> {{date}}
</p>

<p>Por este instrumento particular, de um lado <strong>{{health_plan.name}}</strong>, pessoa jurídica de direito privado, inscrita no CNPJ sob o nº {{health_plan.registration_number}}, com sede no endereço {{health_plan.address}}, doravante denominada CONTRATANTE, e de outro lado a INVICTA MEDICAL SERVICES, pessoa jurídica de direito privado, doravante denominada CONTRATADA, têm entre si justo e contratado o seguinte:</p>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA PRIMEIRA - DO OBJETO</h3>

<p>1.1. O presente contrato tem por objeto estabelecer as condições para prestação de serviços médicos e hospitalares pela CONTRATADA aos beneficiários da CONTRATANTE.</p>

<p>1.2. A CONTRATADA disponibilizará sua rede credenciada de profissionais, clínicas e hospitais para atendimento aos beneficiários da CONTRATANTE, conforme as condições estabelecidas neste instrumento.</p>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA SEGUNDA - DO PRAZO</h3>

<p>2.1. O presente contrato terá vigência de 12 (doze) meses, com início em {{start_date}} e término em {{end_date}}, podendo ser renovado por períodos iguais e sucessivos mediante termo aditivo assinado pelas partes.</p>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA TERCEIRA - DAS OBRIGAÇÕES DA CONTRATADA</h3>

<p>3.1. A CONTRATADA se compromete a:</p>
<ul>
    <li>Prestar atendimento médico aos beneficiários da CONTRATANTE, por meio de sua rede credenciada;</li>
    <li>Manter a qualidade dos serviços prestados, seguindo os padrões de excelência definidos pelo mercado;</li>
    <li>Fornecer à CONTRATANTE todas as informações necessárias sobre os atendimentos realizados;</li>
    <li>Respeitar a confidencialidade das informações dos beneficiários, de acordo com as normas legais e éticas.</li>
</ul>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA QUARTA - DAS OBRIGAÇÕES DA CONTRATANTE</h3>

<p>4.1. A CONTRATANTE se compromete a:</p>
<ul>
    <li>Efetuar os pagamentos conforme acordado neste contrato;</li>
    <li>Fornecer à CONTRATADA a relação atualizada de seus beneficiários;</li>
    <li>Comunicar quaisquer alterações em seu quadro de beneficiários;</li>
    <li>Orientar seus beneficiários quanto às regras de utilização dos serviços da CONTRATADA.</li>
</ul>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA QUINTA - DOS PREÇOS E FORMA DE PAGAMENTO</h3>

<p>5.1. Os serviços prestados serão remunerados conforme tabela de procedimentos negociada entre as partes, que integra o presente contrato como Anexo I.</p>

<p>5.2. Os pagamentos serão efetuados mensalmente, até o 10º (décimo) dia útil do mês subsequente ao da prestação dos serviços, mediante apresentação de fatura detalhada.</p>

<p>5.3. O atraso no pagamento implicará a incidência de multa de 2% (dois por cento) sobre o valor devido, juros de mora de 1% (um por cento) ao mês e correção monetária pelo IPCA.</p>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA SEXTA - TABELA DE PROCEDIMENTOS E VALORES</h3>

<p>6.1. Os procedimentos e valores acordados nesta negociação ({{negotiation.title}}) têm validade de {{negotiation.start_date}} até {{negotiation.end_date}}.</p>

<p>6.2. A tabela abaixo representa os valores negociados para os procedimentos cobertos por este contrato:</p>

<table border="1" cellpadding="5" cellspacing="0" width="100%" style="border-collapse: collapse; margin: 20px 0;">
    <thead style="background-color: #f0f0f0;">
        <tr>
            <th>Código TUSS</th>
            <th>Procedimento</th>
            <th>Valor (R$)</th>
            <th>Observações</th>
        </tr>
    </thead>
    <tbody>
        <!-- Tabela de procedimentos será preenchida dinamicamente -->
        <tr>
            <td colspan="4" style="text-align: center;">Os valores específicos serão inseridos durante a geração do contrato.</td>
        </tr>
    </tbody>
</table>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA SÉTIMA - DA REVISÃO DOS VALORES</h3>

<p>7.1. Os valores constantes na tabela de procedimentos poderão ser revistos anualmente, utilizando-se como referência o IPCA (Índice Nacional de Preços ao Consumidor Amplo) ou outro índice que vier a substituí-lo.</p>

<p>7.2. Qualquer alteração na tabela de valores deverá ser formalizada mediante termo aditivo a este contrato.</p>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA OITAVA - DAS VEDAÇÕES</h3>

<p>8.1. É vedado à CONTRATADA:</p>
<ul>
    <li>Exigir garantias, tais como cheques, promissórias ou caução, para o atendimento aos beneficiários da CONTRATANTE;</li>
    <li>Cobrar diretamente dos beneficiários valores referentes a serviços cobertos por este contrato;</li>
    <li>Discriminar os beneficiários da CONTRATANTE em relação aos clientes de outras operadoras ou particulares.</li>
</ul>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA NONA - DA RESCISÃO</h3>

<p>9.1. O presente contrato poderá ser rescindido:</p>
<ul>
    <li>Por acordo entre as partes;</li>
    <li>Por descumprimento de quaisquer de suas cláusulas, mediante notificação prévia de 30 (trinta) dias;</li>
    <li>Pela falência, recuperação judicial ou extrajudicial de qualquer das partes.</li>
</ul>

<h3 style="background-color: #f5f5f5; padding: 10px;">CLÁUSULA DÉCIMA - DAS DISPOSIÇÕES GERAIS</h3>

<p>10.1. As partes se comprometem a manter sigilo sobre todas as informações confidenciais a que tiverem acesso em razão deste contrato, sob pena de responder por perdas e danos.</p>

<p>10.2. Qualquer tolerância das partes quanto ao descumprimento das cláusulas deste contrato não implicará novação ou renúncia de direitos.</p>

<p>10.3. As partes elegem o foro da Comarca de São Paulo para dirimir quaisquer dúvidas ou controvérsias oriundas do presente contrato, com renúncia expressa a qualquer outro, por mais privilegiado que seja.</p>

<p>E, por estarem justas e contratadas, as partes assinam o presente contrato em duas vias de igual teor e forma, na presença das testemunhas abaixo.</p>

<div style="display: flex; justify-content: space-between; margin-top: 50px; text-align: center;">
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>{{health_plan.name}}</strong><br>
        CONTRATANTE</p>
    </div>
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>INVICTA MEDICAL SERVICES</strong><br>
        CONTRATADA</p>
    </div>
</div>

<div style="display: flex; justify-content: space-between; margin-top: 30px; text-align: center;">
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>TESTEMUNHA 1</strong><br>
        CPF: ___.___.___-__</p>
    </div>
    <div style="width: 45%;">
        <p>_______________________________<br>
        <strong>TESTEMUNHA 2</strong><br>
        CPF: ___.___.___-__</p>
    </div>
</div>
HTML;
    }
} 