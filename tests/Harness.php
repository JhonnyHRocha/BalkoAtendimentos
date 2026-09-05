<?php
declare(strict_types=1);
use App\Clients\{JsonClient,Uy3Client,ClikChatClient};
use App\Config\Config;
use App\Controllers\WebhookController;
use App\Models\Store;
use App\Services\{AtendimentoService,ProposalPayload};
use App\Jobs\Worker;
use GuzzleHttp\{Client,HandlerStack};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

function check(bool $ok, string $why = 'Verificação falhou'): void
{
    if (!$ok) { throw new RuntimeException($why); }
}
function equals(mixed $a, mixed $b, string $why = ''): void
{
    check($a === $b, $why ?: 'Esperado '.json_encode($b).'; recebido '.json_encode($a));
}
function fails(callable $fn, string $class = InvalidArgumentException::class): void
{
    try { $fn(); } catch (Throwable $e) { check($e instanceof $class, get_class($e)); return; }
    throw new RuntimeException('Exceção esperada: '.$class);
}
function testConfig(array $override = []): Config
{
    return new Config($override + ['EXTERNAL_CALLS_ENABLED'=>'true','CLIKCHAT_BASE_URL'=>'https://chat.example',
        'CLIKCHAT_TOKEN'=>'test-only','CLIKCHAT_COMPANY_ID'=>'1','CLIKCHAT_CHANNEL_ID'=>'2','WEBHOOK_SECRET'=>'test-only',
        'UY3_BASE_URL'=>'https://digitadores.example/api','UY3_AUTH_TOKEN'=>'test-only','UY3_ROBO_ID'=>'robot-test',
        'UY3_USER_ID'=>'user-test','UY3_TENANT'=>'tenant-test']);
}
function syntheticCpf(): string
{
    $s = '123123123';
    for ($t=9; $t<11; $t++) {
        $sum=0;
        for ($i=0; $i<$t; $i++) { $sum += (int)$s[$i]*($t+1-$i); }
        $d=($sum*10)%11; $s .= (string)($d===10 ? 0 : $d);
    }
    return $s;
}
function fixtureOffer(): array
{
    return ['margem'=>[['status'=>'OK','result'=>[['elegivel'=>true]]]], 'simulacao'=>[
        'warranty'=>['totalValue'=>150],
        'amortization'=>['liquidValue'=>2000,'requestedAmount'=>3000,'numberOfPayments'=>24,
            'firstPaymentDate'=>'2027-01-01','productName'=>'Tabela teste']]];
}
function fixtureData(): array
{
    return ['cpf'=>syntheticCpf(),'nome'=>'Pessoa Teste','data_nascimento'=>'1990-01-01T03:00:00.000Z',
        'telefone'=>'11900000000','cep'=>'00000000','logradouro'=>'Rua Teste','numero'=>1,'bairro'=>'Bairro Teste',
        'cidade'=>'Cidade Teste','uf'=>'SP','pagamento'=>'CONTA','banco'=>'000','agencia'=>'0001','conta'=>123,'conta_digito'=>'0'];
}
function jsonResponse(array $body, int $status=200, array $headers=[]): Response
{
    return new Response($status,$headers,json_encode($body,JSON_THROW_ON_ERROR));
}

final class Harness
{
    public Store $store;
    public Config $config;
    public WebhookController $webhook;
    public Worker $worker;
    public AtendimentoService $service;
    public Uy3Client $uy3;
    public JsonClient $http;
    public array $requests = [];
    public array $delivered = [];
    public array $scripts = [];
    public int $now;
    private int $sequence = 0;

    public function __construct(array $config = [], ?string $path = null)
    {
        $this->config = testConfig($config);
        $path ??= dirname(__DIR__).'/var/tests/'.bin2hex(random_bytes(8)).'/state.sqlite';
        $this->store = new Store($path);
        $this->now = time()+1000;
        $callback = function ($request, $options) {
            $path = $request->getUri()->getPath();
            $body = json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);
            $this->requests[] = ['path'=>$path,'body'=>$body,'request'=>$request];
            $type = match ($path) {
                '/api/send-message'=>'send',
                '/api/uy3/clt/simulacao-completa'=>'simulate',
                '/api/uy3/clt/cadastrar-proposta'=>'register',
                '/api/uy3/link_assinatura'=>'link',
                '/api/uy3/status_proposta'=>'status',
                default => throw new RuntimeException('Endpoint inesperado: '.$path),
            };
            if (!empty($this->scripts[$type])) {
                $response = array_shift($this->scripts[$type]);
                if (is_callable($response)) { $response = $response($body, $this); }
            } else {
                $response = jsonResponse(match ($type) {
                    'send'=>['wid'=>'test-out-'.count($this->requests)],
                    'simulate'=>fixtureOffer(),
                    'register'=>['id_proposta'=>'uuid-test','creditNoteNo'=>'number-test'],
                    'link'=>['url'=>'https://sign.example/test'],
                    'status'=>['id'=>'uuid-test','status'=>'Processing','statusDisplay'=>'Em análise'],
                }, $type === 'send' ? 201 : 200);
            }
            if ($type === 'send' && $response instanceof Response && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $this->delivered[] = $body;
            }
            return $response;
        };
        $mock = new MockHandler(array_fill(0, 1500, $callback));
        $this->http = new JsonClient(new Client(['handler'=>HandlerStack::create($mock)]),$this->config);
        $this->uy3 = new Uy3Client($this->http,$this->config);
        $clik = new ClikChatClient($this->http,$this->config);
        $this->service = new AtendimentoService($this->uy3,new ProposalPayload($this->config),$this->store,$this->config);
        $this->worker = new Worker($this->store,$this->service,$clik,$this->config);
        $this->webhook = new WebhookController($this->store,$this->config);
    }

    public function payload(string $text='oi', int $ticket=1, ?string $id=null): array
    {
        return ['empresa_id'=>1,'canal_id'=>2,'numero_cliente'=>'550000000000'.($ticket%10),
            'mensagem'=>['wid'=>$id ?? 'event-'.++$this->sequence,'body'=>$text,'ticketId'=>$ticket,'mediaType'=>'conversation']];
    }
    public function key(int $ticket=1): string
    {
        return hash('sha256','1:2:550000000000'.($ticket%10).':'.$ticket);
    }
    public function state(int $ticket=1): array { return $this->store->load($this->key($ticket)); }
    public function receive(string $text, int $ticket=1, ?string $id=null): void
    {
        $r = $this->webhook->handle(json_encode($this->payload($text,$ticket,$id),JSON_THROW_ON_ERROR),'test-only');
        equals($r[0],200);
    }
    public function send(string $text, int $ticket=1): void { $this->receive($text,$ticket); $this->run(); }
    public function run(): void
    {
        for ($i=0; $i<100; $i++) { if (!$this->worker->once($this->now)) { return; } }
        throw new RuntimeException('Worker não ficou ocioso');
    }
    public function advance(int $seconds): void { $this->now += $seconds; $this->run(); }
    public function count(string $suffix): int { return count(array_filter($this->requests,static fn($r)=>str_ends_with($r['path'],$suffix))); }
    public function calls(string $suffix): array { return array_values(array_filter($this->requests,static fn($r)=>str_ends_with($r['path'],$suffix))); }
    public function offer(int $ticket=1): void
    {
        foreach (['oi','SIM',syntheticCpf()] as $text) { $this->send($text,$ticket); }
        equals($this->state($ticket)['state'],'accept');
    }
    public function collect(int $ticket=1, bool $pix=false): void
    {
        $this->offer($ticket);
        foreach (['sim','Pessoa Teste','01/01/1990','11900000000','00000000','Rua Teste','1','Bairro Teste','Cidade Teste','SP'] as $text) { $this->send($text,$ticket); }
        foreach ($pix ? ['PIX','TELEFONE','1100000000'] : ['CONTA','000','1','123','0'] as $text) { $this->send($text,$ticket); }
        equals($this->state($ticket)['state'],'confirm');
    }
    public function register(int $ticket=1, bool $pix=false): void
    {
        $this->collect($ticket,$pix);
        $this->send('confirmar',$ticket);
        equals($this->state($ticket)['state'],'submitted');
    }
    public function cases(string $kind): array
    {
        $s=$this->store->db->prepare('SELECT * FROM human_cases WHERE kind=? AND status="open"'); $s->execute([$kind]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
