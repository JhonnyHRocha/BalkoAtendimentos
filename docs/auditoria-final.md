# Auditoria final — 2026-09-08

**Código: PRONTO PARA SUBIR / teste integrado. Ativação real: bloqueada somente pelas configurações obrigatórias pendentes.**

Base Git inspecionada: 7ec9c65. A implementação já existente foi preservada. Esta etapa acrescenta checagem segura de configuração, isolamento do teste HTTP do .env real, documentação operacional e Compose.

Comparação feita no código de BalkoAtendimentos e em operacao-clt/orquestrador, somente em leitura. O Git do projeto de referência permanece limpo. Não houve commit/push nem chamadas externas nesta preparação.

## Os 22 pontos

| # | Pergunta | Resultado | Evidência no código / comparação |
| --- | --- | --- | --- |
| 1 | Independe de classes, banco e runtime do operacao-clt? | SIM | src/bootstrap.php carrega vendor e classes locais; Models/Store usa SQLite próprio. Não há imports Illuminate/Laravel, MySQL ou Redis. |
| 2 | operacao-clt não foi alterado? | SIM | git status --porcelain=v1 do projeto de referência vazio. A montagem usada para ler a configuração foi somente leitura. |
| 3 | Webhook ClikChat correto? | SIM | Controllers/WebhookController::handle e Models/Message::parse: segredo, envelopes, campos, ecos/grupos, escopo, ID estável. Comparado a ClikChatWebhookController/MensagemRecebida do legado. Teste HTTP local cobre src/index.php, bootstrap e inbox. |
| 4 | Múltiplas conversas independentes? | SIM | Message identifica empresa/canal/número/ticket; Store isola registros; Worker mantém debounce por conversa. Testes com duas conversas intercaladas. |
| 5 | Deduplicação? | SIM | Store::enqueue e UNIQUE(conversation,external_id), incluindo reabertura do SQLite. |
| 6 | Estado persistido? | SIM | Store::load/save/finish; estado, saída e conclusão de entradas gravados em transação. |
| 7 | Coleta os dados necessários? | SIM | Services/Fields e ProposalPayload cobrem CPF, nome, nascimento, telefone, endereço e conta/PIX. Comparação com Uy3Adapter::montarPayload e Uy3DigitacaoPayloadBuilder. |
| 8 | Simulação compatível? | SIM | Clients/Uy3Client::simulate: POST /uy3/clt/simulacao-completa, id_robo, cpf e valor_liquido opcional. O serviço usa a base configurada, incluindo /api quando necessário. |
| 9 | Oferta normalizada/apresentada corretamente? | SIM | Offer::evaluate, AtendimentoService::simulate: líquido, parcela confiável, prazo/tabela e primeiro desconto; tabelas por prazo; classificação available/ineligible/blocked/unavailable. Bruto não é tratado como parcela. |
| 10 | Aceite/recusa? | SIM | Services/Text e AtendimentoService: SIM/NÃO em diferentes caixas/acentos, variações explícitas, cancelamento e novo aceite após ajuste. Regressão de NÃO maiúsculo coberta. |
| 11 | Coleta adicional após aceite? | SIM | Fields::next/collect e estados collect/confirm; vários campos identificados, mensagens fragmentadas, correção e confirmação final. |
| 12 | Cadastro compatível? | SIM | Uy3Client::register e ProposalPayload: /uy3/clt/cadastrar-proposta, robô/usuário/tenant, dados pessoais/endereço/pagamento, parcelas e regra de valor mínimo. PIX telefone preserva a chave brasileira e acrescenta somente o país, como formatarTelefonePix do legado. |
| 13 | Protege de cadastro duplicado em retry? | SIM | AtendimentoService::register grava submitting antes do POST; uncertain/review e human_cases; Worker::recover; reconciliação exige evidência. UUID existente não entra no caminho de cadastro. Proteção por conversa/operação, não uma proibição global de outros contratos do mesmo CPF. |
| 14 | Consulta automática de link? | SIM | Tarefa link persistente, retentativas limitadas, link/link_at e entrega em outbox. Equivalente operacional a BuscarLinkFormalizacao. |
| 15 | Acompanha status? | SIM | Tarefa status, ProposalStatus, proposal_status/status_at, notificação de mudanças, estados finais e PaymentRevision. Comparado a AcompanharStatusProposta/StatusProposta/MensagensStatusProposta. |
| 16 | Responde pelo ClikChat? | SIM | ClikChatClient::send: /api/send-message, number/whatsappId/origin/body e autenticação configurada; transporte fake no E2E. |
| 17 | Retry/falha permanente apropriados? | SIM | ApiException classifica falhas; Worker limita tentativas, respeita Retry-After, cria pendências; Store::nextOutput preserva ordem por conversa. Uma saída morta segura somente a própria conversa até ação do operador. |
| 18 | Segredos fora do código? | SIM | Configuração via .env/ambiente; .env.example sem valores; .env ignorado; Diagnostics filtra informações sensíveis. SQLite contém dados operacionais/pessoais e exige acesso restrito. |
| 19 | Testes independem de credenciais reais/produção? | SIM | tests/Harness.php usa MockHandler e dados sintéticos. Teste HTTP usa APP_ENV=test, impedindo bootstrap de carregar o .env real; EXTERNAL_CALLS_ENABLED=false. Execução sem rede externa. |
| 20 | Testes E2E com mocks? | SIM | tests/run.php percorre webhook → inbox → worker → atendimento → outbox → ClikChat, inclusive cadastro, link, status e revisão. Há teste adicional do front controller HTTP em loopback. |
| 21 | Há parte funcional importante dos itens auditados ainda não reproduzida? | NÃO | Ajustes, agregação de mensagens, revisão e fila humana foram implementados. Não se transplanta IA/editor/Laravel: mídia é encaminhada a operador com referência/histórico, e a revisão no banco é confirmada operacionalmente sem novo cadastro. |
| 22 | Existe algo que impeça testar com configurações corretas? | NÃO | Não foi identificado bloqueio de código no caminho integrado validado. Hoje há configurações obrigatórias vazias e chamadas desabilitadas; portanto o teste real não pode ser iniciado ainda. Ver nomes abaixo. |

## Verificações executadas

- 64/64 testes passando em PHP 8.3, com Docker --network none.
- Sintaxe válida em 28 arquivos PHP de src, bin e tests.
- SQLite dos testes em tmpfs; sem uso do banco operacional.
- .env local criado após aprovação do código/testes; carregamento validado sem imprimir conteúdo.
- EXTERNAL_CALLS_ENABLED permanece false.
- Checagem de configuração informa apenas nomes; não abre banco ou constrói clients HTTP.
- Referência sem alterações; nenhum segredo adicionado ao índice Git.

O teste E2E cobre cadastro bancário e PIX, valor/prazo, respostas negativas, falhas temporárias/permanentes, duas conversas, esgotamento, recuperação em submitting e após UUID persistido, link com retry, finalização, PaymentRevision e ações humanas.

## Configuração pendente

- WEBHOOK_SECRET
- CLIKCHAT_COMPANY_ID
- CLIKCHAT_CHANNEL_ID
- CLIKCHAT_TOKEN
- UY3_BASE_URL
- UY3_ROBO_ID
- UY3_USER_ID
- UY3_TENANT

Não foram inventados valores, consultados bancos remotos ou presumidas identidades de empresa/robô/tenant. Foram aproveitados apenas parâmetros reconhecidos no contrato de configuração do Uy3Adapter. Não houve cópia de banco ou classes do legado.

## Caminho operacional de amanhã

ClikChat → POST /webhooks/clikchat → inbox/SQLite → worker → autorização/CPF → simulação UY3 → outbox/ClikChat → aceite e coleta → confirmação → cadastro UY3 → tarefas de link/status → outbox/ClikChat.

Antes de ativar: completar as variáveis pendentes, executar bin/check-config.php, disponibilizar uma URL HTTPS do webhook acessível ao ClikChat e configurar X-Webhook-Secret. O endereço local 127.0.0.1 não é acessível pelo provedor sem túnel/reverse proxy. A base Digitadores deve ser HTTPS e usar o prefixo /api conforme o servidor.

Somente no início do teste autorizado: alterar EXTERNAL_CALLS_ENABLED para true e iniciar/reiniciar o worker. O teste integrado real poderá registrar uma proposta após CONFIRMAR; combinar previamente com Jhonny os dados e o ambiente apropriados. O serviço não faz cancelamento remoto automático.

A fila humana é operacional por bin/operations.php: list/show/reply/resolve. Em PaymentRevision, os dados revisados ficam vinculados ao UUID; o operador atua no banco e confirma payment-updated. Se o cadastro ficar incerto, conferir antes de associate/not-created.

A aprovação é de código e validação offline. Não equivale a uma homologação já realizada no ambiente externo.
