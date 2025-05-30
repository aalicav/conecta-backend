# Fluxo de Agendamento - Planos de Saúde e Especialidades

## Processo de Negociação de Especialidades

O fluxo de agendamento no sistema segue uma lógica específica em relação às especialidades médicas e planos de saúde:

### Planos de Saúde
- Os planos de saúde **não negociam valores das especialidades**
- Eles apenas solicitam a especialidade através do ID TUSS
- O plano envia a solicitação com o código TUSS do procedimento/especialidade necessária

### Clínicas e Profissionais
- As **clínicas e profissionais** são os responsáveis por:
  - Negociar os valores das especialidades
  - Definir os preços para cada procedimento
  - Manter sua tabela de preços atualizada

### Processo de Agendamento
Quando uma solicitação de agendamento é recebida:

1. O plano de saúde envia o ID TUSS da especialidade/procedimento necessário
2. O sistema busca na rede credenciada:
   - Clínicas e profissionais que atendem aquela especialidade (pelo ID TUSS)
   - Valores negociados por cada prestador
3. O sistema seleciona o prestador mais adequado baseado em:
   - Menor valor negociado para a especialidade
   - Proximidade geográfica do paciente
4. O agendamento é então direcionado para o prestador selecionado

Este fluxo garante que o sistema sempre encontre o melhor custo-benefício para o paciente, considerando tanto o valor do procedimento quanto a localização do prestador de serviço. 