# Referência UY3 e implementação independente

O operacao-clt/orquestrador foi consultado somente em leitura. Não é necessário em instalação ou execução.

| Referência no legado | Equivalente local |
| --- | --- |
| ClikChatWebhookController; Services/ClikChat/MensagemRecebida | Controllers/WebhookController; Models/Message |
| Jobs/ProcessarMensagem; Jobs/ResponderConversa | Models/Store; Jobs/Worker; Services/AtendimentoService |
| Jobs/Fluxo/RetomarConversaFluxo; Services/Fluxo/Nos/Bancos/NoAdapterBanco | Máquina determinística e tarefas persistentes locais |
| Services/Simulacao/Adapters/Uy3Adapter | Clients/Uy3Client; Services/Offer; Services/ProposalStatus |
| Services/Leads/Digitacao/Uy3DigitacaoPayloadBuilder | Services/Fields; Services/ProposalPayload |
| Services/Digitadores/DigitadoresClient | Clients/JsonClient |
| Jobs/BuscarLinkFormalizacao | Worker::background; AtendimentoService::task(link) |
| Jobs/AcompanharStatusProposta; Services/Simulacao/StatusProposta | Worker::background; ProposalStatus; task(status) |
| Services/Simulacao/MensagensStatusProposta | PaymentRevision, coleta revisada e Operations::resolve(payment-updated) |
| Services/ClikChat/ClikChatClient; Services/Conversa/EntregaConversa | Clients/ClikChatClient; outbox por conversa |
| Encaminhamento humano e histórico Laravel | human_cases, operator_events, bin/operations.php |

## Contratos

POST relativos à base Digitadores, incluindo /api na base quando exigido:

- /uy3/clt/simulacao-completa: id_robo, cpf, valor_liquido opcional.
- /uy3/clt/cadastrar-proposta: id_robo, user_id, tenant, cpf, nome, telefone, data_nascimento, endereco, conta_bancaria, parcelas, valor_liquido condicional.
- /uy3/link_assinatura: id_robo, id_proposta.
- /uy3/status_proposta: id_robo, uuid_proposta.

Endereço: logradouro, numero, bairro, cidade, uf, cep. Conta: banco, agencia, conta, conta_digito; alternativa PIX: tipo_chave_pix, chave_pix. Tipos PIX: NaturalRegistrationNumber, Phone, Email, Automatic. CPF PIX é pontuado e deve ser o do titular. PIX telefone preserva todos os dígitos da chave brasileira e apenas acrescenta +55 quando necessário. Telefone cadastral segue regra separada de celular.

Nascimento: YYYY-MM-DDT03:00:00.000Z. Prazos: 12,18,24,30,36. Cadastro por margem abaixo do mínimo omite valor_liquido. Oferta primária ou tabela compatível com prazo solicitado, incluindo alternativa mais próxima explicitamente apresentada para novo aceite.

Líquido: simulacao.amortization.liquidValue. Parcela: simulacao.warranty.totalValue ou cronograma; simulacao_raw em centavos. requestedAmount é bruto, nunca parcela confiável. Se uma tabela de outro prazo tiver parcela suspeita igual ao bruto, o serviço não atribui a parcela primária a ela: agenda nova consulta/conferência.

Cadastro confirma id_proposta (UUID), distinto de creditNoteNo. Link: url no objeto ou primeiro item. Status: objeto/lista/envelope proposta; prefere correspondência exata ao UUID e só aceita objeto sem ID quando único.

ClikChat: POST /api/send-message com Bearer e number, whatsappId, origin, body. origin padrão assistente_virtual.

## Pós-cadastro e diferenças de implementação

O legado consulta link/status por polling, persiste mudanças, encerra em estados finais e, em PaymentRevision, coleta outra conta/PIX para o time concluir a revisão no banco. Essas ações existem no serviço independente com SQLite, worker e fila operacional CLI. Não há caminho de cadastrar-proposta no acompanhamento.

Não foram transplantados Laravel, Redis, editor visual ou IA. O comportamento necessário usa comandos textuais, campos identificados, agregação de mensagens durante coleta e encaminhamento humano de mídia com referência/histórico. A equipe opera sua própria fila local e responde via ClikChat. Não há dependência do painel/banco antigo.

Nenhum endpoint de atualização de conta ou cancelamento foi presumido: a equipe executa essas ações no banco, registra evidência local e retoma o acompanhamento da mesma proposta.
