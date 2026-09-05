Execute composer test e composer lint na raiz. A suíte usa Guzzle MockHandler, dados sintéticos e SQLite em var/tests. O único HTTP real é loopback para testar o front controller, com chamadas externas desabilitadas. A execução Docker documentada usa --network none e bancos temporários em tmpfs.

Harness.php monta WebhookController, Store, AtendimentoService, Worker e clients reais sobre o transporte fake; não substitui a máquina de estados por stub. run.php cobre o fluxo completo e regressões da auditoria. lint.php percorre todos os PHP de src, bin e tests.
