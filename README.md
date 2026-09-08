# BalkoAtendimentos

Atendimento CLT UY3 independente, em PHP 8.3, com entrada e saída ClikChat e integração com a API dos Digitadores. Não usa classes, banco, runtime ou serviços internos do operacao-clt.

## Instalar e executar

Requisitos: PHP 8.3+, Composer, extensões PDO/pdo_sqlite, iconv e mbstring. SQLite e locks devem estar em disco local gravável, exclusivo desta instância.

~~~sh
composer install
php -S 127.0.0.1:8080 -t src src/index.php
~~~

Prepare as variáveis de ambiente ou um .env local a partir de .env.example. Nenhum .env real acompanha o projeto. Em outro terminal:

~~~sh
php bin/worker.php
~~~

O worker exige configuração completa e EXTERNAL_CALLS_ENABLED=true. Essa habilitação é explícita; vazia ou false bloqueia chamadas. Para um ciclo, use --once. Configure supervisor para reiniciar o worker se o processo parar.

Rotas:
- GET /health: liveness local; não verifica conectividade com as APIs.
- POST /webhooks/clikchat: exige X-Webhook-Secret; autentica, valida e persiste a entrada.

Em produção, encaminhe apenas as rotas ao front controller com PHP-FPM/servidor web. Não exponha a raiz do repositório, .env, vendor ou var. O servidor embutido é para desenvolvimento.

## Arquitetura

WebhookController e Message validam envelopes/identidade. Store mantém conversas, inbox, outbox, tarefas agendadas, pendências humanas e trilha operacional em SQLite próprio. AtendimentoService conduz a conversa; Fields, Text, Offer, ProposalStatus e ProposalPayload tratam regras e contratos. Uy3Client e ClikChatClient usam Guzzle por JsonClient.

Um worker por SQLite, protegido por lock local, processa entradas, consultas agendadas e saídas. A ordenação de saída é por conversa: a falha de um cliente não bloqueia os demais. Uma saída morta segura somente as posteriores daquela conversa até decisão do operador. Não use o mesmo banco em NFS/SMB nem múltiplos hosts.

## Fluxo do cliente

1. Autorizar consulta com SIM e informar CPF.
2. Receber oferta com líquido, parcela, prazo, tabela e primeiro desconto quando disponíveis.
3. Aceitar, recusar ou ajustar com VALOR 2500, PRAZO 12 ou QUERO 2500 EM 12 PARCELAS. MARGEM retira o valor solicitado. A oferta ajustada exige novo aceite.
4. Informar dados pessoais, endereço e conta/PIX. É possível enviar campos identificados em várias linhas: Nome:, Nascimento:, Telefone:, CEP:, Rua:, Numero:, Bairro:, Cidade:, UF:, Pagamento:, Tipo PIX:, Chave PIX:.
5. CONFIRMAR cadastra; CORRIGIR refaz a coleta; NÃO/CANCELAR encerra antes do cadastro.
6. O serviço busca automaticamente o link e acompanha o status até estado final ou limite operacional. LINK/STATUS permitem consultar o cache/agendar atualização.
7. Na revisão de pagamento, coleta outra conta/PIX para a equipe atualizar a proposta existente. Não chama cadastrar-proposta novamente.

SIM/NÃO aceitam variações de caixa, acentos e respostas como ACEITO, AUTORIZO, NÃO QUERO e RECUSO. Mensagens próximas durante coleta podem ser agregadas por conversa. Cancelamento, aceite e pedido de atendente são preservados como comandos separados.

ATENDENTE cria pendência operacional e pausa a coleta automática. Áudio/imagem/documento também vão para a fila humana, com ticket e referência HTTPS da mídia quando fornecidos. Não há IA nem download automático da mídia: o operador consulta o histórico/referência e responde pelo canal.

## Configuração

.env.example contém apenas nomes, sem valores ou credenciais. Uma instância atende uma empresa/canal; use banco/configuração separados para outros escopos.

| Variável | Uso / padrão quando vazia |
| --- | --- |
| APP_ENV | Identificação operacional do ambiente |
| APP_DB_PATH | var/atendimento.sqlite dentro do projeto |
| WEBHOOK_SECRET | Segredo obrigatório de entrada |
| CLIKCHAT_COMPANY_ID | Filtro opcional de empresa; ID recebido no webhook |
| CLIKCHAT_CHANNEL_ID | Filtro opcional de canal; envio usa o ID da conversa |
| CLIKCHAT_BASE_URL | Origem HTTPS do ClikChat, sem /api |
| CLIKCHAT_TOKEN | Token de envio |
| CLIKCHAT_ORIGIN | assistente_virtual |
| UY3_BASE_URL | Base dos Digitadores; HTTPS ou HTTP com UY3_ALLOW_HTTP=true; preservar /api |
| UY3_ALLOW_HTTP | Opt-in exclusivo para HTTP no UY3; false por padrao |
| UY3_AUTH_HEADER | Authorization |
| UY3_AUTH_TOKEN | Token Digitadores |
| UY3_AUTH_BEARER | true normaliza Bearer em Authorization; false preserva token |
| UY3_ROBO_ID | Robô da simulação e padrão das demais operações |
| UY3_DIGITACAO_ROBO_ID | Override opcional do robô de cadastro/link/status |
| UY3_USER_ID | Usuário para cadastro |
| UY3_TENANT | Tenant para cadastro |
| UY3_MIN_VALUE | 1000; abaixo disso omite valor_liquido no cadastro pela margem |
| HTTP_TIMEOUT | 90 segundos; conexão limitada a 10 segundos |
| EXTERNAL_CALLS_ENABLED | Somente true permite APIs externas |
| OUTBOX_MAX_ATTEMPTS | 5 tentativas de envio |
| LINK_INTERVAL_SECONDS | 30 segundos |
| LINK_MAX_ATTEMPTS | 120 consultas |
| STATUS_INTERVAL_SECONDS | 600 segundos |
| STATUS_MAX_ATTEMPTS | 288 consultas |
| SIMULATION_INTERVAL_SECONDS | 30 segundos |
| SIMULATION_MAX_ATTEMPTS | 5 consultas automáticas após falha inicial |
| DEBOUNCE_SECONDS | 1 segundo para estabilizar mensagens recebidas |

ClikChat aceita objeto direto, {body: objeto} ou [{body: objeto}]. Identificadores: empresa_id, canal_id, numero_cliente, mensagem.wid/id e mensagem.ticketId; reconhece os fallbacks ticket.companyId, mensagem.whatsappId, ticket.id e ticket.contact.number. Mensagem em string precisa de message_id no body. Preserve identificadores ao reenviar. Ecos, grupos e escopos diferentes são ignorados.

HTTP: 200 gravação/duplicata/ignorado, 401 segredo inválido, 422 formato inválido, 413 limite de 64 KiB, 503 falha local. Estado de conversa e deduplicação são independentes de memória do processo.

## Falhas e atendimento humano

Timeouts, HTTP 408/425/429 e 5xx são temporários nas consultas/saídas. Outbox usa backoff exponencial e respeita Retry-After. Outros erros HTTP são permanentes. Tentativas esgotadas ou falhas permanentes abrem human_cases, visíveis pela CLI. O contador persiste antes da chamada para limitar repetições mesmo após queda.

No cadastro, a regra é diferente: submitting é persistido antes do POST. HTTP 400/422 ou rejeição explícita de negócio permitem revisão dos dados, sem retry automático. Timeout, 5xx ou resposta sem confirmação segura geram registration_uncertain. O worker recupera esse estado mesmo sem nova mensagem do cliente.

Consulte e trate a fila regularmente:

~~~sh
php bin/operations.php list
php bin/operations.php show ID
php bin/operations.php reply ID "Resposta do atendente"
php bin/operations.php resolve ID ACAO "Evidência da conferência" [UUID]
~~~

show contém dados pessoais e histórico recente: execute somente em terminal/acesso autorizado. reply enfileira resposta pelo ClikChat; o worker realiza o envio. Ações:
- associate: vincula UUID confirmado a cadastro incerto e inicia link/status.
- not-created: somente após verificar que a proposta não existe; volta à confirmação final do cliente.
- retry-delivery: libera nova tentativa da saída morta após corrigir a causa.
- skip-delivery: dispensa a saída após decisão justificada, por exemplo entrega manual, liberando as posteriores.
- payment-updated: após atualizar os dados de pagamento no Digitadores, retoma status da mesma proposta.
- retry-task: reabre consulta de simulação/link/status esgotada.
- resume: devolve conversa humana à etapa anterior; não contorna cadastro incerto.

Todas as resoluções exigem evidência, registrada com ação/data. O processo usa o mesmo lock do worker; se ocupado, tente novamente ou pare o worker durante a ação. A fila local é o mecanismo operacional de encaminhamento: não é necessário configurar email/Slack ou acessar banco legado.

Atalho para reconciliação:

~~~sh
php bin/reconcile.php HASH_CONVERSA UUID_CONFIRMADO "Conferido no Digitadores"
php bin/reconcile.php HASH_CONVERSA --not-created "Ausência de cadastro confirmada"
~~~

A correção de pagamento é manual no banco, como no fluxo de referência: não foi inventado endpoint UY3 de atualização/cancelamento. Os dados revisados ficam em show e o operador confirma payment-updated após atuar. O serviço acompanha a proposta existente.

## Persistência e operação

A inicialização acrescenta as tabelas/colunas novas ao SQLite anterior, preservando dados. Faça backup antes de atualizar uma instalação. Propostas antigas em submitted têm consultas recuperadas; submitting/review viram pendências operacionais. Não apague histórico de inbox/outbox sem definir uma janela segura de retenção.

A entrega ClikChat é pelo menos uma vez. Se o destino receber a mensagem e a resposta se perder, pode haver repetição; o contrato observado não garante idempotência de envio. Isso não provoca repetição do cadastro UY3.

Dados pessoais e referências de mídia ficam no banco local, não nos logs. Restrinja permissões do diretório, backups e acesso operacional; defina retenção e proteção de disco no ambiente. Logs em stderr contêm evento/ID de job/classe, sem CPF ou payload. Diagnósticos operacionais preservam códigos/campos/motivos com filtragem de segredos; não copiam integralmente respostas externas.

## Testes e auditoria

~~~sh
composer test
composer lint
~~~

A suíte usa Guzzle MockHandler, dados sintéticos e SQLite isolado. Inclui a cadeia completa webhook → inbox → worker → atendimento → outbox → ClikChat, filas concorrentes, reconciliação, pagamento, estados finais, ajustes e migração. Um teste HTTP acessa somente o servidor PHP em 127.0.0.1, com chamadas externas desabilitadas.

Docker sem rede e sem escrita no repositório (PowerShell):

~~~powershell
docker run --rm --network none --read-only -v "${PWD}:/app:ro" --tmpfs /app/var:rw,noexec,nosuid,size=128m -w /app php:8.3-cli php tests/run.php
docker run --rm --network none --read-only -v "${PWD}:/app:ro" -w /app php:8.3-cli php tests/lint.php
~~~

Resultado e evidências em docs/auditoria-final.md. Contratos comparados em docs/referencia-legado.md. Nenhuma chamada de homologação/produção ou credencial real foi usada nesta validação.

## Preparação para o teste integrado com Jhonny

O .env local foi preparado com chamadas externas desabilitadas. Antes de ativar, valide sem exibir valores:

~~~powershell
docker compose run --rm --no-deps web php bin/check-config.php
~~~

O comando retorna env_loaded, external_calls_enabled e somente nomes de variáveis ausentes/inválidas. Código de saída 2 significa configuração ainda incompleta; não tente iniciar o worker nesse estado. Não use cat/Get-Content no .env em terminais compartilhados. UY3_BASE_URL aceita HTTPS ou HTTP com UY3_ALLOW_HTTP=true; preserve /api conforme o servidor Digitadores.

Nesta máquina, use Docker (PHP/Composer não estão no PATH). Dependências já estão instaladas em vendor. Em uma cópia nova, execute composer install com Composer/PHP disponíveis antes de subir o serviço.

Iniciar somente o servidor, sem iniciar atendimento ou APIs:

~~~powershell
docker compose up -d web
Invoke-RestMethod http://127.0.0.1:8080/health
docker compose logs -f web
~~~

O webhook é POST /webhooks/clikchat. Configure uma URL HTTPS pública ou túnel/reverse proxy até http://127.0.0.1:8080/webhooks/clikchat e o header X-Webhook-Secret no ClikChat. Não envie o teste ao webhook antigo nem direcione a mesma integração aos dois fluxos. A porta local fica vinculada ao loopback; não é acessível diretamente ao ClikChat.

Somente amanhã, após preencher as variáveis apontadas e autorizar o teste real, altere EXTERNAL_CALLS_ENABLED para true no .env pelo editor local. Então:

~~~powershell
docker compose --profile integration up -d
docker compose restart worker
docker compose logs -f worker
docker compose exec worker php bin/operations.php list
~~~

Para investigar uma pendência em terminal autorizado, use show ID; reply ID TEXTO agenda resposta; resolve registra a ação/evidência. Os detalhes podem conter dados pessoais do cliente, mas não credenciais. O operador confirma a alteração bancária no Digitadores antes de payment-updated.

O roteiro de teste é: mensagem inicial → SIM → CPF de teste acordado → oferta → SIM → dados → CONFIRMAR → link → assinatura/status. Para validar negociação, use VALOR/PRAZO antes do aceite. Após o cadastro, não repita a confirmação para tentar resolver indisponibilidade: consulte a pendência e reconcilie o UUID existente.

Ao encerrar o teste, pare o worker, restaure EXTERNAL_CALLS_ENABLED=false e pare o servidor/túnel conforme necessário:

~~~powershell
docker compose stop worker
docker compose stop web
~~~

Os logs de aplicação ficam em stderr, acessíveis por docker compose logs. O SQLite e as pendências permanecem em var; parar os containers não remove o estado. Os testes usam banco separado e não devem ser apontados ao banco operacional. Consulte docs/auditoria-final.md para os 22 itens e o resultado da validação.

### IDs por conversa e UY3 HTTP

CLIKCHAT_COMPANY_ID e CLIKCHAT_CHANNEL_ID sao filtros opcionais. Sem esses filtros, o webhook autenticado exige IDs positivos em empresa_id/ticket.companyId e canal_id/mensagem.whatsappId. Ambos ficam persistidos; o envio usa whatsappId da conversa. Configure um CLIKCHAT_TOKEN autorizado para a empresa/canais atendidos; IDs recebidos nao concedem acesso a outras empresas.

HTTPS permanece o padrao. Apenas UY3 aceita HTTP com UY3_ALLOW_HTTP=true, para reproduzir o endpoint do legado. Essa opcao permite trafego sem TLS nesse endpoint; nao altera a URL, nao permite redirecionamentos e nao habilita chamadas externas. Mantenha EXTERNAL_CALLS_ENABLED=false durante a preparacao.
