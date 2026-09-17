<?php
declare(strict_types=1);
namespace App\Services;

final class ConversationMessages
{
    public static function buttons(array $c): array
    {
        $choices = match ($c['state']) {
            'consent' => ['SIM'=>'Autorizar', 'NÃO'=>'Não autorizar'],
            'accept' => ['SIM'=>'Sim, quero', 'AJUSTAR'=>'Ajustar oferta', 'NÃO'=>'Não quero'],
            'confirm' => ['CONFIRMAR'=>'Confirmar', 'CORRIGIR'=>'Corrigir', 'CANCELAR'=>'Cancelar'],
            'payment_confirm' => ['CONFIRMAR'=>'Confirmar', 'CORRIGIR'=>'Corrigir'],
            'collect','payment_collect' => Fields::next($c['data'], $c['state']==='payment_collect')==='pagamento'
                ? ['PIX'=>'PIX','CONTA'=>'Conta bancária'] : [],
            default => [],
        };
        return array_map(static fn($id,$label)=>['type'=>'reply','title'=>$label,'displayText'=>$label,'id'=>$id],array_keys($choices),array_values($choices));
    }

    public static function prompt(array $c): string
    {
        return match ($c['state']) {
            'consent'=>'Você autoriza a consulta do CPF? Responda SIM ou NÃO.',
            'cpf'=>'Informe seu CPF com 11 dígitos.',
            'accept'=>'Deseja continuar com a oferta? Responda SIM ou NÃO. Para ajustar, envie VALOR seguido do valor ou PRAZO seguido das parcelas.',
            'collect','payment_collect'=>Fields::QUESTIONS[Fields::next($c['data'],$c['state']==='payment_collect')] ?? 'Envie CONFIRMAR ou CORRIGIR.',
            'confirm'=>'Confirma os dados e autoriza cadastrar a oferta aceita? Envie CONFIRMAR, CORRIGIR ou CANCELAR.',
            'payment_confirm'=>'Confirma os novos dados de pagamento? Envie CONFIRMAR ou CORRIGIR.',
            default=>'',
        };
    }

    public static function offer(array $offer): string
    {
        $text = "*Oferta UY3*\n\n";
        $text .= '*Valor disponível:* R$ '.number_format($offer['value'],2,',','.');
        $text .= "\n*Prazo:* ".$offer['term'].' parcelas';
        $text .= "\n*Valor da parcela:* R$ ".number_format($offer['payment'],2,',','.');
        if (!empty($offer['first_payment'])) { $text .= "\n*Primeiro desconto:* ".substr((string)$offer['first_payment'],0,10); }
        if (!empty($offer['product'])) { $text .= "\n*Tabela:* ".$offer['product']; }
        return $text;
    }
}