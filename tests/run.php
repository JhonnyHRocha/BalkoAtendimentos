<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Harness.php';

use App\Clients\ApiException;
use App\Models\{Message,Store};
use App\Services\{Fields,Text,Offer,ProposalPayload,ProposalStatus,Operations};
use GuzzleHttp\Psr7\{Response,Request};
use GuzzleHttp\Exception\ConnectException;

$tests = [];
$tests['webhook: envelopes aceitos e deduplicação após reinício'] = function () {
    $h=new Harness(); $p=$h->payload('oi',1,'same');
    check($h->webhook->handle(json_encode([['body'=>$p]]),'test-only')[1]['queued']);
    check(!$h->webhook->handle(json_encode(['body'=>$p]),'test-only')[1]['queued']);
    $other=new Store($h->store->path);
    check(!$other->enqueue(Message::parse($p,$h->config)));
};
$tests['webhook: autenticação, JSON, limite e campos malformados'] = function () {
    $h=new Harness();
    equals($h->webhook->handle('{}','bad')[0],401);
    equals($h->webhook->handle('{','test-only')[0],422);
    equals($h->webhook->handle(str_repeat('x',65537),'test-only')[0],413);
    equals($h->webhook->handle('[]','test-only')[0],422);
    $p=$h->payload(); $p['mensagem']['body']=[];
    equals($h->webhook->handle(json_encode($p),'test-only')[0],422);
};
$tests['webhook: isolamento de empresa/canal, ecos e grupos'] = function () {
    $h=new Harness();
    foreach (['empresa_id','canal_id'] as $field) { $p=$h->payload(); $p[$field]=99; equals(Message::parse($p,$h->config),null); }
    $p=$h->payload(); $p['mensagem']['fromMe']=true; equals(Message::parse($p,$h->config),null);
    $p['mensagem']['fromMe']='false'; check(Message::parse($p,$h->config)!==null);
    $p['ticket']['isGroup']=true; equals(Message::parse($p,$h->config),null);
};
$tests['mensagens em string exigem ID estável explícito'] = function () {
    $h=new Harness(); $p=$h->payload(); $p['mensagem']='oi'; $p['message_id']='string-id'; $p['ticket']['id']=1;
    check(Message::parse($p,$h->config)!==null);
    unset($p['message_id']); fails(fn()=>Message::parse($p,$h->config));
};
$tests['duas conversas mantêm estados e dados separados'] = function () {
    $h=new Harness(); $h->receive('oi',1); $h->receive('oi',2); $h->run();
    $h->send('SIM',1); $h->send('NÃO',2);
    equals($h->state(1)['state'],'cpf'); equals($h->state(2)['state'],'closed');
    check($h->key(1)!==$h->key(2));
};
$tests['duplicata de webhook não repete transição nem saída'] = function () {
    $h=new Harness(); $h->receive('oi',1,'fixed'); $h->run(); $n=count($h->delivered);
    $h->receive('oi',1,'fixed'); $h->run();
    equals(count($h->delivered),$n); equals($h->state()['state'],'consent');
};
$tests['estado persiste ao reabrir SQLite'] = function () {
    $h=new Harness(); $h->offer();
    equals((new Store($h->store->path))->load($h->key())['data']['cpf'],syntheticCpf());
};
$tests['simulação: endpoint, payload e valor opcional'] = function () {
    $h=new Harness(); $h->uy3->simulate(syntheticCpf(),2500);
    $r=$h->calls('simulacao-completa')[0];
    equals((string)$r['request']->getUri(),'https://digitadores.example/api/uy3/clt/simulacao-completa');
    equals($r['body'],['id_robo'=>'robot-test','cpf'=>syntheticCpf(),'valor_liquido'=>2500]);
};
$tests['HTTP: Bearer único e header customizado'] = function () {
    $h=new Harness(['UY3_AUTH_TOKEN'=>'Bearer Bearer test-only']); $h->uy3->simulate(syntheticCpf());
    equals($h->requests[0]['request']->getHeaderLine('Authorization'),'Bearer test-only');
    $h=new Harness(['UY3_AUTH_HEADER'=>'X-Api-Key']); $h->uy3->simulate(syntheticCpf());
    equals($h->requests[0]['request']->getHeaderLine('X-Api-Key'),'test-only');
};
$tests['HTTP: rede bloqueada por configuração não chama handler'] = function () {
    $h=new Harness(['EXTERNAL_CALLS_ENABLED'=>'false']);
    fails(fn()=>$h->uy3->simulate(syntheticCpf()),ApiException::class);
    equals(count($h->requests),0);
};
$tests['HTTP: classifica erro permanente, transitório e protocolo'] = function () {
    foreach ([400=>false,401=>false,403=>false,408=>true,422=>false,429=>true,500=>true,503=>true] as $status=>$temporary) {
        $h=new Harness(); $h->scripts['simulate']=[jsonResponse(['message'=>'test'],$status)];
        try { $h->uy3->simulate(syntheticCpf()); throw new RuntimeException('Erro esperado'); }
        catch(ApiException $e) { equals($e->temporary(),$temporary); }
        equals($h->count('simulacao-completa'),1);
    }
    $h=new Harness(); $h->scripts['simulate']=[new Response(200,[],'bad')];
    try { $h->uy3->simulate(syntheticCpf()); } catch(ApiException $e) { check($e->protocolError); return; }
    throw new RuntimeException('Erro de protocolo esperado');
};
$tests['diagnósticos retêm campo/erro sem expor token ou CPF'] = function () {
    $h=new Harness(); $h->scripts['simulate']=[jsonResponse(['errors'=>['cpf'=>['CPF '.syntheticCpf().' inválido']], 'message'=>'test-only'],422)];
    try { $h->uy3->simulate(syntheticCpf()); }
    catch(ApiException $e) {
        $text=json_encode($e->details);
        check(isset($e->details['errors']['cpf']));
        check(!str_contains($text,syntheticCpf()) && !str_contains($text,'test-only'));
        return;
    }
    throw new RuntimeException('Erro esperado');
};
$tests['oferta: valores, parcela, prazo, tabela e primeiro desconto'] = function () {
    $o=Offer::evaluate(fixtureOffer());
    equals($o['kind'],'available'); equals($o['offer']['payment'],150.0);
    equals($o['offer']['first_payment'],'2027-01-01'); equals($o['offer']['product'],'Tabela teste');
};
$tests['oferta: inelegível, bloqueado e indisponível são distintos'] = function () {
    $r=fixtureOffer(); $r['margem'][0]['result'][0]['elegivel']=false;
    equals(Offer::evaluate($r)['kind'],'ineligible');
    $r['margem'][0]['result'][0]['tipoBloqueio']=['codigo'=>3,'descricao'=>'Bloqueio teste'];
    equals(Offer::evaluate($r)['kind'],'blocked');
    equals(Offer::evaluate(['erro_descricao'=>'Timeout na integração'])['kind'],'unavailable');
    equals(Offer::evaluate(['erro_descricao'=>'Sem margem disponível'])['kind'],'ineligible');
};
$tests['oferta: cronograma em centavos e bruto nunca vira parcela'] = function () {
    $r=fixtureOffer(); unset($r['simulacao']['warranty']);
    equals(Offer::normalize($r),null);
    $r['simulacao_raw']['amortization']['paymentScheduleItems']=[['payment'=>15000]];
    equals(Offer::normalize($r)['payment'],150.0);
};
$tests['oferta: prazo exato e alternativa mais próxima'] = function () {
    $r=fixtureOffer(); $r['tabelas']=[
        ['parcelas'=>12,'valorLiberado'=>1200,'valorParcela'=>120],
        ['parcelas'=>24,'valorLiberado'=>2000,'valorParcela'=>150]];
    equals(Offer::normalize($r,12)['term'],12);
    equals(Offer::normalize($r,30)['term'],24);
};
$tests['oferta: parcela suspeita em outro prazo impede apresentar oferta incorreta'] = function () {
    $r=fixtureOffer(); $r['tabelas']=[['parcelas'=>12,'valorLiberado'=>2000,'valorParcela'=>3000]];
    equals(Offer::evaluate($r,12)['kind'],'unavailable');
};
$tests['PIX telefone: preserva dígitos nos formatos do legado'] = function () {
    foreach (['1100000000'=>'+551100000000','11900000000'=>'+5511900000000',
        '+55 (11) 0000-0000'=>'+551100000000','5511900000000'=>'+5511900000000'] as $input=>$expected) {
        equals(Fields::validate('chave_pix',(string)$input,['tipo_pix'=>'Phone']),$expected);
    }
    equals(Fields::pixPhone('123'),null); equals(Fields::pixPhone('+14155550123'),null);
};
$tests['campos: calendário, CPF, endereço, agência e tipos PIX'] = function () {
    equals(Fields::cpf(syntheticCpf()),syntheticCpf());
    equals(Fields::cpf(str_repeat('0',11)),null);
    equals(Fields::validate('data_nascimento','31/02/1990',[]),null);
    equals(Fields::validate('numero','SN',[]),null);
    equals(Fields::validate('agencia','12',[]),'0012');
    equals(Fields::validate('tipo_pix','ALEATÓRIA',[]),'Automatic');
    equals(Fields::validate('chave_pix','teste@example.com',['tipo_pix'=>'Email']),'teste@example.com');
};
$tests['SIM e NÃO: caixa, acentos e variações esperadas'] = function () {
    foreach (['SIM','sIm','sim!','ACEITO','Autorizo','sim, autorizo'] as $s) { check(Text::yes($s),$s); }
    foreach (['NÃO','Não','NAO','não!','NÃO QUERO','Não, obrigado','RECUSO'] as $s) { check(Text::no($s),$s); }
    check(!Text::yes('não aceito')); check(!Text::no('sim, aceito'));
};
$tests['NÃO maiúsculo encerra consentimento e oferta sem cadastro'] = function () {
    $h=new Harness(); $h->send('oi'); $h->send('NÃO'); equals($h->state()['state'],'closed');
    $h=new Harness(); $h->offer(); $h->send('NÃO'); equals($h->state()['state'],'closed');
    equals($h->count('cadastrar-proposta'),0);
};
$tests['coleta aceita vários campos identificados na mesma mensagem'] = function () {
    $h=new Harness(); $h->offer(); $h->send('sim');
    $h->send("Nome: Pessoa Teste\nNascimento: 01/01/1990\nTelefone: 11900000000\nCEP: 00000000\nRua: Rua Teste\nNumero: 1\nBairro: Bairro Teste\nCidade: Cidade Teste\nUF: SP\nPagamento: PIX\nTipo PIX: EMAIL\nChave PIX: teste@example.com");
    equals($h->state()['state'],'confirm');
};
$tests['E2E completo: webhook -> inbox -> worker -> atendimento -> outbox -> ClikChat'] = function () {
    $h=new Harness(); $h->register();
    equals($h->count('cadastrar-proposta'),1);
    equals($h->state()['uuid'],'uuid-test'); equals($h->state()['link'],'https://sign.example/test');
    check(count($h->delivered)>=20);
    equals((int)$h->store->db->query('SELECT COUNT(*) FROM inbox WHERE done=0')->fetchColumn(),0);
    equals((int)$h->store->db->query('SELECT COUNT(*) FROM outbox WHERE done=0')->fetchColumn(),0);
    $p=$h->calls('cadastrar-proposta')[0]['body'];
    equals($p['id_robo'],'robot-test'); equals($p['user_id'],'user-test'); equals($p['tenant'],'tenant-test');
    equals($p['parcelas'],24); equals($p['data_nascimento'],'1990-01-01T03:00:00.000Z');
    equals($p['conta_bancaria'],['banco'=>'000','agencia'=>'0001','conta'=>123,'conta_digito'=>'0']);
    $h->advance(600); equals($h->state()['proposal_status']['code'],'Processing');
    equals($h->calls('status_proposta')[0]['body'],['id_robo'=>'robot-test','uuid_proposta'=>'uuid-test']);
    foreach ($h->delivered as $m) { equals($m['origin'],'assistente_virtual'); equals($m['whatsappId'],2); }
};
$tests['E2E PIX telefone cadastra exatamente a chave informada'] = function () {
    $h=new Harness(); $h->register(1,true);
    equals($h->calls('cadastrar-proposta')[0]['body']['conta_bancaria'],['tipo_chave_pix'=>'Phone','chave_pix'=>'+551100000000']);
};
$tests['ajuste de valor e prazo exige novo aceite e preserva contrato'] = function () {
    $h=new Harness(); $h->offer();
    $r=fixtureOffer(); $r['tabelas']=[['parcelas'=>12,'valorLiberado'=>2500,'valorParcela'=>260]];
    $h->scripts['simulate']=[jsonResponse($r)];
    $h->send('Quero 2.500,00 em 12 parcelas');
    equals($h->state()['state'],'accept'); equals($h->state()['offer']['term'],12);
    equals($h->calls('simulacao-completa')[1]['body']['valor_liquido'],2500);
    equals($h->count('cadastrar-proposta'),0);
};
$tests['prazo inválido não consulta nem cadastra'] = function () {
    $h=new Harness(); $h->offer(); $n=$h->count('simulacao-completa');
    $h->send('PRAZO 15'); equals($h->count('simulacao-completa'),$n); equals($h->state()['state'],'accept');
};
$tests['valor abaixo do mínimo omitido somente no cadastro pela margem'] = function () {
    $p=(new ProposalPayload(testConfig()))->build(fixtureData(),['value'=>900,'term'=>12]);
    check(!isset($p['valor_liquido'])); equals($p['parcelas'],12);
};
$tests['inelegibilidade não é tratada como indisponibilidade temporária'] = function () {
    $h=new Harness(); $r=fixtureOffer(); $r['margem'][0]['result'][0]['elegivel']=false;
    $h->scripts['simulate']=[jsonResponse($r)];
    foreach (['oi','sim',syntheticCpf()] as $text) { $h->send($text); }
    equals($h->state()['state'],'ineligible');
    equals((int)$h->store->db->query('SELECT COUNT(*) FROM tasks WHERE done=0')->fetchColumn(),0);
};
$tests['simulação temporária retenta automaticamente sem nova mensagem'] = function () {
    $h=new Harness(); $h->scripts['simulate']=[new Response(503)];
    foreach (['oi','sim',syntheticCpf()] as $text) { $h->send($text); }
    equals($h->state()['state'],'simulation_retry');
    $h->advance(30); equals($h->state()['state'],'accept'); equals($h->count('simulacao-completa'),2);
};
$tests['simulação esgotada registra pendência operacional'] = function () {
    $h=new Harness(['SIMULATION_MAX_ATTEMPTS'=>'2']); $h->scripts['simulate']=array_fill(0,4,new Response(503));
    foreach (['oi','sim',syntheticCpf()] as $text) { $h->send($text); }
    $h->advance(30); $h->advance(30);
    equals($h->state()['state'],'simulation_failed'); equals(count($h->cases('simulation_exhausted')),1);
};
$tests['rejeição HTTP 422 permite correção, sem retry automático de cadastro'] = function () {
    $h=new Harness(); $h->collect(); $h->scripts['register']=[jsonResponse(['errors'=>['endereco.numero'=>['Número inválido']]],422)];
    $h->send('confirmar'); equals($h->state()['state'],'registration_rejected');
    equals(count($h->cases('registration_rejected')),1); $h->advance(3600);
    equals($h->count('cadastrar-proposta'),1); $h->send('corrigir'); equals($h->state()['state'],'collect');
};
$tests['rejeição explícita de negócio em HTTP 200 é preservada'] = function () {
    $h=new Harness(); $h->collect(); $h->scripts['register']=[jsonResponse(['erro_descricao'=>'Dados bancários rejeitados'])];
    $h->send('confirmar'); equals($h->state()['state'],'registration_rejected');
    check(isset($h->state()['registration_error']['erro_descricao']));
};
$tests['cadastro incerto: timeout nunca repete POST em novas mensagens'] = function () {
    $h=new Harness(); $h->collect();
    $h->scripts['register']=[new ConnectException('timeout',new Request('POST','https://test.example'))];
    $h->send('confirmar'); equals($h->state()['state'],'review');
    $h->send('confirmar'); $h->send('SIM'); $h->advance(3600);
    equals($h->count('cadastrar-proposta'),1); equals(count($h->cases('registration_uncertain')),1);
};
$tests['cadastro com resposta malformada gera conferência sem duplicar'] = function () {
    $h=new Harness(); $h->collect(); $h->scripts['register']=[new Response(200,[],'bad')];
    $h->send('confirmar'); equals($h->state()['state'],'review'); equals($h->count('cadastrar-proposta'),1);
};
$tests['queda em submitting abre pendência mesmo sem nova mensagem'] = function () {
    $h=new Harness(); $h->collect(); $c=$h->state(); $c['state']='submitting'; $h->store->save($h->key(),$c);
    $h->run(); equals($h->state()['state'],'review');
    equals(count($h->cases('registration_uncertain')),1); equals($h->count('cadastrar-proposta'),0);
};
$tests['UUID persistido após queda recupera apenas consultas'] = function () {
    $h=new Harness(); $h->collect(); $c=$h->state(); $c['state']='submitted'; $c['uuid']='uuid-test'; $h->store->save($h->key(),$c);
    $h->run(); equals($h->state()['link'],'https://sign.example/test'); equals($h->count('cadastrar-proposta'),0);
};
$tests['link automático: pendente, timeout, retry, persistência e entrega'] = function () {
    $h=new Harness(); $h->scripts['link']=[jsonResponse([]),new Response(503),jsonResponse([['url'=>'https://sign.example/ready']])];
    $h->register(); check(!isset($h->state()['link']));
    $h->advance(30); check(!isset($h->state()['link']));
    $h->advance(30); equals($h->state()['link'],'https://sign.example/ready');
    equals($h->count('link_assinatura'),3); equals($h->count('cadastrar-proposta'),1);
    check((bool)array_filter($h->delivered,fn($m)=>str_contains($m['body'],'https://sign.example/ready')));
};
$tests['link esgotado cria caso para atendente e não recadastra'] = function () {
    $h=new Harness(['LINK_MAX_ATTEMPTS'=>'2']); $h->scripts['link']=[jsonResponse([]),jsonResponse([])];
    $h->register(); $h->advance(30); equals(count($h->cases('link_exhausted')),1);
    equals($h->count('cadastrar-proposta'),1);
};
$tests['normalização reconhece todos os estados finais do legado'] = function () {
    foreach (['Liquidation','liquidação','Liquidated','Finished','Encerrado','Canceled','Cancelled','Cancelado',
        'Disapproved','Reprovado','Recusado','Rejected','Expired','Expirado','Error','Erro'] as $code) {
        check(ProposalStatus::normalize(['status'=>$code])['final'],$code);
    }
    check(!ProposalStatus::normalize(['status'=>'PaymentRevision'])['final']);
};
$tests['status final é persistido, notificado e encerra tarefas'] = function () {
    $h=new Harness(); $h->scripts['status']=[jsonResponse(['id'=>'uuid-test','status'=>'Liquidation','statusDisplay'=>'Pago'])];
    $h->register(); $h->advance(600);
    equals($h->state()['state'],'finished'); check($h->state()['proposal_status']['paid']);
    $n=$h->count('status_proposta'); $h->advance(3600); $h->send('CONFIRMAR');
    equals($h->count('status_proposta'),$n); equals($h->count('cadastrar-proposta'),1);
    equals((int)$h->store->db->query('SELECT COUNT(*) FROM tasks WHERE done=0')->fetchColumn(),0);
};
$tests['status sem mudança não duplica notificação'] = function () {
    $h=new Harness(); $h->register(); $h->advance(600); $n=count($h->delivered);
    $h->advance(600); equals(count($h->delivered),$n);
};
$tests['pagamento recusado: coleta revisão, operador conclui e nunca recadastra'] = function () {
    $h=new Harness(); $h->scripts['status']=[
        jsonResponse(['id'=>'uuid-test','status'=>'PaymentRevision','statusDisplay'=>'Revisão de pagamento']),
        jsonResponse(['id'=>'uuid-test','status'=>'Liquidation','statusDisplay'=>'Pago'])];
    $h->register(); $h->advance(600); equals($h->state()['state'],'payment_collect');
    foreach (['PIX','EMAIL','teste@example.com','CONFIRMAR'] as $text) { $h->send($text); }
    equals($h->state()['state'],'payment_review');
    $case=$h->cases('payment_revision')[0]; $ops=new Operations($h->store);
    check($ops->show((int)$case['id'])['details']['ready']);
    $ops->resolve((int)$case['id'],'payment-updated','Dados atualizados pelo operador no ambiente de teste');
    $h->run(); equals($h->state()['state'],'finished'); equals($h->count('cadastrar-proposta'),1);
};
$tests['status ignora UUID divergente e prefere correspondência exata'] = function () {
    $h=new Harness(); $h->scripts['status']=[
        jsonResponse([['id'=>'other','status'=>'Paid']]),
        jsonResponse([['status'=>'Processing'],['id'=>'uuid-test','status'=>'Liquidation']])];
    equals($h->uy3->statusDetails('uuid-test'),null);
    check($h->uy3->statusDetails('uuid-test')['paid']);
};
$tests['falha temporária em A não bloqueia saída pronta de B'] = function () {
    $h=new Harness(); $h->scripts['send']=[new Response(503)];
    $h->receive('oi',1); $h->receive('oi',2); $h->run();
    equals(count($h->delivered),1); equals($h->delivered[0]['number'],'5500000000002');
    $h->advance(5); equals(count($h->delivered),2);
};
$tests['falha permanente em A bloqueia somente A e registra caso'] = function () {
    $h=new Harness(); $h->scripts['send']=[new Response(400)];
    $h->receive('oi',1); $h->receive('oi',2); $h->run(); $h->send('SIM',1);
    equals(count($h->delivered),1); equals($h->delivered[0]['number'],'5500000000002');
    equals(count($h->cases('delivery_failed')),1);
    equals($h->count('send-message'),2);
};
$tests['limite de saída e retomada manual preservam ordem por conversa'] = function () {
    $h=new Harness(['OUTBOX_MAX_ATTEMPTS'=>'2']); $h->scripts['send']=[new Response(503),new Response(503)];
    $h->send('oi'); $h->send('SIM'); $h->advance(5);
    $case=$h->cases('delivery_failed')[0]; equals((int)json_decode($case['details'],true)['attempts'],2);
    $n=$h->count('send-message'); $h->advance(100); equals($h->count('send-message'),$n);
    (new Operations($h->store))->resolve((int)$case['id'],'retry-delivery','Conectividade corrigida no teste');
    $h->run(); equals(count($h->delivered),2);
    check(str_contains($h->delivered[0]['body'],'Você autoriza'));
    check(str_contains($h->delivered[1]['body'],'11 dígitos'));
};
$tests['Retry-After é respeitado sem bloquear outras conversas'] = function () {
    $h=new Harness(); $h->scripts['send']=[new Response(429,['Retry-After'=>'60'])];
    $h->send('oi'); $h->advance(59); equals($h->count('send-message'),1);
    $h->send('oi',2); equals($h->count('send-message'),2);
    $h->advance(1); equals($h->count('send-message'),3);
};
$tests['atendimento humano: fila, histórico, resposta e retomada'] = function () {
    $h=new Harness(); $h->send('oi'); $h->send('ATENDENTE');
    equals($h->state()['state'],'human');
    $ops=new Operations($h->store); $case=$ops->pending()[0];
    check(count($ops->show((int)$case['id'])['recent_messages'])===2);
    $ops->reply((int)$case['id'],'Resposta humana de teste'); $h->run();
    equals(end($h->delivered)['body'],'Resposta humana de teste');
    $ops->resolve((int)$case['id'],'resume','Atendimento pode continuar'); equals($h->state()['state'],'consent');
};
$tests['reconciliação associa UUID e inicia acompanhamento sem novo cadastro'] = function () {
    $h=new Harness(); $h->collect(); $h->scripts['register']=[new Response(503)]; $h->send('confirmar');
    $case=$h->cases('registration_uncertain')[0];
    (new Operations($h->store))->resolve((int)$case['id'],'associate','UUID conferido pelo operador de teste','uuid-test');
    $h->run(); equals($h->state()['uuid'],'uuid-test'); equals($h->count('cadastrar-proposta'),1);
    check(isset($h->state()['link']));
};
$tests['liberar novo cadastro exige evidência e confirmação final do cliente'] = function () {
    $h=new Harness(); $h->collect(); $h->scripts['register']=[new Response(503)]; $h->send('confirmar');
    $case=$h->cases('registration_uncertain')[0]; $ops=new Operations($h->store);
    fails(fn()=>$ops->resolve((int)$case['id'],'not-created',''));
    $ops->resolve((int)$case['id'],'not-created','Ausência da proposta verificada');
    $h->run(); equals($h->count('cadastrar-proposta'),1); equals($h->state()['state'],'confirm');
    $h->send('confirmar'); equals($h->count('cadastrar-proposta'),2);
};
$tests['mídia abre atendimento humano com referência operacional'] = function () {
    $h=new Harness(); $p=$h->payload(''); $p['mensagem']['mediaType']='image'; $p['mensagem']['mediaUrl']='https://media.example/test';
    $h->webhook->handle(json_encode($p),'test-only'); $h->run();
    equals($h->state()['state'],'human'); equals(count($h->cases('media')),1);
    equals(json_decode($h->cases('media')[0]['details'],true)['media_url'],'https://media.example/test');
};
$tests['migração preserva outbox e estado do esquema anterior'] = function () {
    $dir=dirname(__DIR__).'/var/tests/'.bin2hex(random_bytes(8)); mkdir($dir,0700,true); $path=$dir.'/old.sqlite';
    $db=new PDO('sqlite:'.$path);
    $db->exec('CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT,data TEXT NOT NULL,text TEXT NOT NULL,done INTEGER NOT NULL DEFAULT 0,attempts INTEGER NOT NULL DEFAULT 0,available INTEGER NOT NULL DEFAULT 0)');
    $db->prepare('INSERT INTO outbox(data,text) VALUES(?,?)')->execute(['{"key":"legacy","number":"5500000000000","channel":2}','Mensagem antiga']);
    $s=new Store($path); equals($s->nextOutput(time())['conversation'],'legacy');
    equals($s->nextOutput(time())['text'],'Mensagem antiga');
};
$tests['status esgotado permite reabrir consulta operacionalmente'] = function () {
    $h=new Harness(['STATUS_MAX_ATTEMPTS'=>'1']); $h->scripts['status']=[new Response(503)];
    $h->register(); $h->advance(600);
    $case=$h->cases('status_exhausted')[0];
    (new Operations($h->store))->resolve((int)$case['id'],'retry-task','Indisponibilidade resolvida');
    $h->run(); equals($h->state()['proposal_status']['code'],'Processing');
    equals($h->count('cadastrar-proposta'),1);
};
$tests['pular entrega exige ação explícita e libera só a sequência correspondente'] = function () {
    $h=new Harness(); $h->scripts['send']=[new Response(400)];
    $h->send('oi'); $h->send('SIM');
    $case=$h->cases('delivery_failed')[0]; $ops=new Operations($h->store);
    fails(fn()=>$ops->resolve((int)$case['id'],'skip-delivery',''));
    $ops->resolve((int)$case['id'],'skip-delivery','Mensagem entregue manualmente em teste');
    $h->run(); equals(count($h->delivered),1); check(str_contains($h->delivered[0]['body'],'11 dígitos'));
};
$tests['pedido de atendente não permite contornar conferência de cadastro'] = function () {
    $h=new Harness(); $h->collect(); $h->scripts['register']=[new Response(503)];
    $h->send('confirmar'); $h->send('ATENDENTE');
    $case=$h->cases('requested')[0]; $ops=new Operations($h->store);
    fails(fn()=>$ops->resolve((int)$case['id'],'resume','Não pode ignorar conferência'));
    equals($h->count('cadastrar-proposta'),1);
};
$tests['revisão de pagamento não apaga dados novamente no mesmo status'] = function () {
    $h=new Harness();
    $h->scripts['status']=array_fill(0,2,jsonResponse(['id'=>'uuid-test','status'=>'PaymentRevision']));
    $h->register(); $h->advance(600); $h->send('PIX'); $h->send('EMAIL');
    $h->advance(600);
    equals($h->state()['data']['pagamento'],'PIX'); equals($h->state()['data']['tipo_pix'],'Email');
};
$tests['recusa final do banco encerra sem orientar assinatura'] = function () {
    $h=new Harness(); $h->scripts['status']=[jsonResponse(['id'=>'uuid-test','status'=>'Disapproved','statusDisplay'=>'Reprovado'])];
    $h->register(); $h->advance(600); equals($h->state()['state'],'finished');
    $h->send('LINK'); check(!str_contains(end($h->delivered)['body'],'https://sign.example'));
    equals($h->count('cadastrar-proposta'),1);
};
$tests['HTTP real local: front controller, bootstrap, autenticação e persistência'] = function () {
    $dir=dirname(__DIR__).'/var/tests/'.bin2hex(random_bytes(8)); mkdir($dir,0700,true);
    $path=$dir.'/http.sqlite'; $port=random_int(20000,45000); $root=dirname(__DIR__);
    $env=getenv();
    foreach (['APP_ENV'=>'test','APP_DB_PATH'=>$path,'WEBHOOK_SECRET'=>'test-only','CLIKCHAT_COMPANY_ID'=>'1','CLIKCHAT_CHANNEL_ID'=>'2',
        'EXTERNAL_CALLS_ENABLED'=>'false'] as $key=>$value) { $env[$key]=$value; }
    $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root.'/src',$root.'/src/index.php'],
        [['file',$null,'r'],['file',$null,'w'],['file',$null,'w']],$pipes,$root,$env);
    if (!is_resource($process)) { throw new RuntimeException('Servidor de teste não iniciou'); }
    try {
        $ready=false;
        for ($i=0;$i<50;$i++) {
            $socket=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.1);
            if ($socket) { fclose($socket); $ready=true; break; }
            usleep(50000);
        }
        check($ready,'Servidor local não respondeu');
        $health=file_get_contents('http://127.0.0.1:'.$port.'/health');
        equals(json_decode($health,true),['ok'=>true]);
        $body=json_encode(['empresa_id'=>1,'canal_id'=>2,'numero_cliente'=>'5500000000000',
            'mensagem'=>['id'=>'http-test','body'=>'oi','ticketId'=>1]]);
        $context=stream_context_create(['http'=>['method'=>'POST',
            'header'=>['Content-Type: application/json','X-Webhook-Secret: test-only'],
            'content'=>$body,'ignore_errors'=>true,'timeout'=>15]]);
        $r=file_get_contents('http://127.0.0.1:'.$port.'/webhooks/clikchat',false,$context);
        check(is_string($r), 'Servidor HTTP não respondeu ao POST');
        check(json_decode($r,true)['queued'] ?? false, $r);
        $db=new PDO('sqlite:'.$path); equals((int)$db->query('SELECT COUNT(*) FROM inbox')->fetchColumn(),1);
    } finally { proc_terminate($process); proc_close($process); }
};
$tests['mensagens fragmentadas de uma conversa são agregadas sem misturar clientes'] = function () {
    $h=new Harness(); $h->offer(1); $h->send('sim',1); $h->offer(2); $h->send('sim',2);
    $h->receive('Pessoa',1); $h->receive('Outra',2); $h->receive('Teste',1); $h->receive('Pessoa',2); $h->run();
    equals($h->state(1)['data']['nome'],'Pessoa Teste');
    equals($h->state(2)['data']['nome'],'Outra Pessoa');
};
$tests['buffer de coleta não absorve cancelamento como dado cadastral'] = function () {
    $h=new Harness(); $h->offer(); $h->send('sim');
    $h->receive('Pessoa Teste'); $h->receive('NÃO'); $h->run();
    equals($h->state()['state'],'closed'); equals($h->count('cadastrar-proposta'),0);
};
$tests['janela de debounce de A não impede processamento pronto de B'] = function () {
    $h=new Harness(['DEBOUNCE_SECONDS'=>'2']); $h->now=time();
    $h->receive('oi',1); $h->receive('oi',2);
    $h->store->db->prepare('UPDATE inbox SET received_at=? WHERE conversation=?')->execute([$h->now-3,$h->key(2)]);
    $h->run(); equals($h->state(1)['state'],'start'); equals($h->state(2)['state'],'consent');
    $h->advance(2); equals($h->state(1)['state'],'consent');
};
$tests['preflight: configuração completa e chamadas externas desabilitadas'] = function () {
    $r=App\Config\Preflight::inspect(testConfig(['EXTERNAL_CALLS_ENABLED'=>'false']));
    equals($r,['missing'=>[],'invalid'=>[]]);
};
$tests['preflight: ausências são reportadas somente pelo nome'] = function () {
    $r=App\Config\Preflight::inspect(testConfig(['UY3_TENANT'=>'','CLIKCHAT_TOKEN'=>'']));
    check(in_array('UY3_TENANT',$r['missing'],true)); check(in_array('CLIKCHAT_TOKEN',$r['missing'],true));
    check(!str_contains(json_encode($r),'test-only'));
};
$tests['preflight: URL com segredo e ID inválido não vazam valores'] = function () {
    $r=App\Config\Preflight::inspect(testConfig(['UY3_BASE_URL'=>'https://test-only@host.example','CLIKCHAT_COMPANY_ID'=>'invalid-test']));
    check(in_array('UY3_BASE_URL',$r['invalid'],true));
    check(in_array('CLIKCHAT_COMPANY_ID',$r['invalid'],true));
    check(!str_contains(json_encode($r),'test-only') && !str_contains(json_encode($r),'invalid-test'));
};
$tests['UY3 HTTP explicito: fluxo completo com mocks e IDs do webhook'] = function () {
    $h=new Harness(['UY3_BASE_URL'=>'http://digitadores.example/api','UY3_ALLOW_HTTP'=>'true','CLIKCHAT_COMPANY_ID'=>'','CLIKCHAT_CHANNEL_ID'=>'']);
    equals(App\Config\Preflight::inspect($h->config),['missing'=>[],'invalid'=>[]]);
    $h->register();
    equals($h->state()['link'],'https://sign.example/test');
    foreach($h->requests as $r) {
        equals($r['request']->getUri()->getScheme(),$r['path']==='/api/send-message'?'https':'http');
    }
    equals($h->state()['route']['company'],1); equals($h->state()['route']['channel'],2);
};
$tests['HTTP restrito ao UY3: rejeita sem opt-in e ClikChat mesmo com opt-in'] = function () {
    foreach(['','false'] as $flag) {
        $h=new Harness(['UY3_BASE_URL'=>'http://digitadores.example/api','UY3_ALLOW_HTTP'=>$flag]);
        fails(fn()=>$h->uy3->simulate(syntheticCpf()),ApiException::class); equals(count($h->requests),0);
        check(in_array('UY3_BASE_URL',App\Config\Preflight::inspect($h->config)['invalid'],true));
    }
    $h=new Harness(['CLIKCHAT_BASE_URL'=>'http://chat.example','UY3_ALLOW_HTTP'=>'true']);
    fails(fn()=>$h->http->post('CLIKCHAT','/api/send-message',[]),ApiException::class); equals(count($h->requests),0);
    check(in_array('CLIKCHAT_BASE_URL',App\Config\Preflight::inspect($h->config)['invalid'],true));
};
$tests['URLs invalidas rejeitadas no runtime e preflight mesmo com HTTP explicito'] = function () {
    foreach(['ftp://host.example','http://test-only@host.example','http://host.example?token=test-only','http://host.example#fragment','http:///api','https://host.example?x=1'] as $url) {
        $h=new Harness(['UY3_BASE_URL'=>$url,'UY3_ALLOW_HTTP'=>'true']);
        fails(fn()=>$h->uy3->simulate(syntheticCpf()),ApiException::class); equals(count($h->requests),0);
        check(in_array('UY3_BASE_URL',App\Config\Preflight::inspect($h->config)['invalid'],true));
    }
    check(in_array('UY3_ALLOW_HTTP',App\Config\Preflight::inspect(testConfig(['UY3_ALLOW_HTTP'=>'yes']))['invalid'],true));
};
$tests['IDs dinamicos: isolamento, dedup e envio por canal apos reinicio'] = function () {
    $cfg=['CLIKCHAT_COMPANY_ID'=>'','CLIKCHAT_CHANNEL_ID'=>''];$h=new Harness($cfg);$keys=[];
    foreach([[1,2],[1,3],[2,2]] as [$company,$channel]) {
        $p=$h->payload('oi',1,'same-id'); unset($p['empresa_id'],$p['canal_id']);
        $p['ticket']['companyId']=$company;$p['mensagem']['whatsappId']=$channel;
        $keys[]=Message::parse($p,$h->config)['key'];
        check($h->webhook->handle(json_encode($p),'test-only')[1]['queued']);
        check(!$h->webhook->handle(json_encode($p),'test-only')[1]['queued']);
    }
    equals(count(array_unique($keys)),3);
    $restarted=new Harness($cfg,$h->store->path);$restarted->run();
    equals(array_column($restarted->delivered,'whatsappId'),[2,3,2]);
    foreach($keys as $i=>$key){$c=$restarted->store->load($key);equals($c['state'],'consent');equals($c['route']['company'],$i===2?2:1);}
    $rows=$restarted->store->db->query('SELECT data FROM outbox')->fetchAll(PDO::FETCH_COLUMN);
    foreach($rows as $row)check(isset(json_decode($row,true)['company']));
};
$tests['IDs dinamicos: webhook exige IDs positivos e autentica antes de persistir'] = function () {
    $h=new Harness(['CLIKCHAT_COMPANY_ID'=>'','CLIKCHAT_CHANNEL_ID'=>'']);
    foreach(['empresa_id','canal_id'] as $field) {
        foreach([null,0,-1,'2abc','1.5',[],true,'999999999999999999999999999'] as $bad){$p=$h->payload();$p[$field]=$bad;equals($h->webhook->handle(json_encode($p),'test-only')[0],422);}
    }
    equals($h->webhook->handle(json_encode($h->payload()),'wrong')[0],401);
    equals((int)$h->store->db->query('SELECT count(*) FROM inbox')->fetchColumn(),0);
};
$tests['Filtros opcionais independentes preservam restricao configurada'] = function () {
    $p=(new Harness())->payload();
    check(Message::parse($p,testConfig(['CLIKCHAT_COMPANY_ID'=>'']))!==null);
    equals(Message::parse($p,testConfig(['CLIKCHAT_COMPANY_ID'=>'','CLIKCHAT_CHANNEL_ID'=>'99'])),null);
    equals(Message::parse($p,testConfig(['CLIKCHAT_COMPANY_ID'=>'99','CLIKCHAT_CHANNEL_ID'=>''])),null);
};
$tests['botoes persistem na outbox e usam contrato ClikChat apos reinicio'] = function () {
    $h=new Harness();$h->receive('oi');$h->scripts['send']=[new Response(503)];$h->run();
    $row=$h->store->db->query('SELECT data FROM outbox LIMIT 1')->fetchColumn();
    $buttons=json_decode($row,true)['buttons'];equals(array_column($buttons,'id'),['SIM','NÃO']);
    $other=new Harness([],$h->store->path);$other->now=$h->now+1000;$other->run();
    equals($other->delivered[0]['buttons'],$buttons);
    foreach($buttons as $b){equals($b['type'],'reply');equals($b['title'],$b['displayText']);check(mb_strlen($b['title'])<=20);}
    check(str_contains($other->delivered[0]['body'],"\n\n"));
};
$tests['botoes de oferta ajuste e confirmacao executam comandos'] = function () {
    $h=new Harness();$h->offer();$out=end($h->delivered);
    check(str_contains($out['body'],"\n*Prazo:*"));equals(array_column($out['buttons'],'id'),['SIM','AJUSTAR','NÃO']);
    $h->send('Ajustar oferta');equals($h->state()['state'],'accept');check(str_contains(end($h->delivered)['body'],'VALOR 2000'));
    $h->send('Sim, quero');equals($h->state()['state'],'collect');
    $h=new Harness();$h->collect();$h->send('oi');equals(array_column(end($h->delivered)['buttons'],'id'),['CONFIRMAR','CORRIGIR','CANCELAR']);
    $h->send('Confirmar');equals($h->count('cadastrar-proposta'),1);
};
$tests['duvida sobre oferta preserva dados e retoma campo pendente'] = function () {
    $h=new Harness();$h->offer();$h->send('SIM');$before=$h->state();$count=count($h->requests);
    $h->send('Qual o valor da parcela?');equals($h->state(),$before);
    $out=end($h->delivered)['body'];check(str_contains($out,'*Valor da parcela:*'));check(str_ends_with($out,Fields::QUESTIONS['nome']));
    equals($h->count('cadastrar-proposta'),0);equals(count($h->requests),$count+1);
    $h->send('Pessoa Teste');equals($h->state()['data']['nome'],'Pessoa Teste');
};
$tests['duvida antes do CPF nao autoriza nem consulta sem consentimento'] = function () {
    $h=new Harness();$h->send('oi');$h->send('Por que precisa do CPF?');equals($h->state()['state'],'consent');equals($h->count('simulacao-completa'),0);
    check(str_contains(end($h->delivered)['body'],'confirmação final'));
    $h->send('Autorizar');equals($h->state()['state'],'cpf');$h->send('Quando recebo?');equals($h->state()['state'],'cpf');
    check(str_ends_with(end($h->delivered)['body'],'Informe seu CPF com 11 dígitos.'));
};
$tests['duvida desconhecida permite resposta humana sem apagar coleta'] = function () {
    $h=new Harness();$h->offer();$h->send('SIM');$before=$h->state();$h->send('Qual a taxa de juros?');equals($h->state(),$before);
    $cases=$h->cases('customer_question');equals(count($cases),1);$ops=new Operations($h->store);
    $ops->reply((int)$cases[0]['id'],'Resposta conferida pela equipe.');$h->run();
    $ops->resolve((int)$cases[0]['id'],'answered','Dúvida respondida');equals($h->state(),$before);
    equals(count($h->cases('customer_question')),0);
};
$tests['debounce nao mistura pergunta com dado de outra mensagem'] = function () {
    $h=new Harness();$h->offer();$h->send('SIM');
    $h->receive('Qual o valor da parcela?');$h->receive('Pessoa Teste');$h->run();
    equals($h->state()['data']['nome'],'Pessoa Teste');equals(Fields::next($h->state()['data']),'data_nascimento');
    check(str_contains($h->delivered[count($h->delivered)-2]['body'],'*Valor da parcela:*'));
};
$tests['duvida na revisao preserva UUID e dados sem novo cadastro'] = function () {
    $h=new Harness();$h->register();$c=$h->state();$c['state']='payment_collect';foreach(Fields::PAYMENT_FIELDS as $f)unset($c['data'][$f]);$h->store->save($h->key(),$c);
    $h->send('Posso usar conta de outra pessoa?');equals($h->state()['uuid'],$c['uuid']);equals($h->state()['data'],$c['data']);equals($h->count('cadastrar-proposta'),1);
    check(str_contains(end($h->delivered)['body'],'própria titularidade'));equals(array_column(end($h->delivered)['buttons'],'id'),['PIX','CONTA']);
};
$failed=0;
foreach ($tests as $name=>$test) {
    try { $test(); echo "OK $name\n"; }
    catch(Throwable $e) { $failed++; fwrite(STDERR,"FALHOU $name: ".$e->getMessage()."\n".$e->getTraceAsString()."\n"); }
}
echo count($tests)." testes; $failed falhas.\n";
exit($failed ? 1 : 0);
