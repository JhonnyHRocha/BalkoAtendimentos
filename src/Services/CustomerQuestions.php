<?php
declare(strict_types=1);
namespace App\Services;

final class CustomerQuestions
{
    public static function detects(string $text): bool
    {
        $n=Text::normalize($text);
        return str_contains($text,'?') || (bool)preg_match('/^(?:qual|quais|como|quando|quanto|quantos|quantas|onde|por que|porque|pra que|para que|posso|preciso|voces|e seguro|isso e|tenho uma duvida|nao entendi|me explica|pode explicar)\b/',$n);
    }

    public static function answer(string $text, array $c): ?string
    {
        $n=Text::normalize($text);
        if (preg_match('/\b(taxa|juros|cet|seguro|tarifa)\b/',$n)) { return null; }
        if (preg_match('/\b(valor|parcela|parcelas|prazo|quanto)\b/',$n) && !preg_match('/\b(cai|recebo|receber|demora|libera|liberacao)\b/',$n) && isset($c['offer'])) {
            return ConversationMessages::offer($c['offer']);
        }
        if (preg_match('/\b(cpf|dados|nome|nascimento|endereco)\b/',$n) && preg_match('/\b(por que|porque|pra que|para que|precisa|precisam|pedem|solicitam)\b/',$n)) {
            return 'O CPF é usado para consultar a oferta na UY3. Se você decidir continuar, os dados cadastrais e de pagamento serão usados para cadastrar a proposta. O cadastro só é enviado após sua confirmação final.';
        }
        if (preg_match('/\b(cai|recebo|receber|demora|liberacao)\b/',$n)) {
            return 'Não tenho um prazo de pagamento confirmado. Após o cadastro, enviaremos o link de formalização quando estiver disponível e acompanharemos o status informado pela UY3.';
        }
        if (preg_match('/\b(pix|conta)\b/',$n) && preg_match('/\b(terceiro|outra pessoa|outra titularidade|meu nome|minha)\b/',$n)) {
            return 'Informe uma conta ou chave PIX de sua própria titularidade para receber o crédito.';
        }
        if (preg_match('/\b(corrigir|errei|alterar dados)\b/',$n)) {
            return 'Antes de cadastrar, você poderá escolher CORRIGIR na etapa de confirmação para informar os dados novamente. Se precisar de ajuda agora, envie ATENDENTE.';
        }
        return null;
    }
}