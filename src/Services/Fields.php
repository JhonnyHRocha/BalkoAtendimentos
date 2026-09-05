<?php
declare(strict_types=1);
namespace App\Services;

final class Fields
{
    public const PAYMENT_FIELDS = ['pagamento','tipo_pix','chave_pix','banco','agencia','conta','conta_digito'];
    public const QUESTIONS = [
        'nome' => 'Qual é seu nome completo?',
        'data_nascimento' => 'Qual é sua data de nascimento? Use DD/MM/AAAA.',
        'telefone' => 'Informe seu celular com DDD.',
        'cep' => 'Qual é seu CEP (8 dígitos)?',
        'logradouro' => 'Qual é a rua ou avenida do seu endereço?',
        'numero' => 'Qual é o número do endereço? Informe somente números.',
        'bairro' => 'Qual é o bairro?',
        'cidade' => 'Qual é a cidade?',
        'uf' => 'Qual é a UF (exemplo: SP)?',
        'pagamento' => 'Como deseja receber? Responda PIX ou CONTA.',
        'tipo_pix' => 'Qual é o tipo da chave PIX? CPF, TELEFONE, EMAIL ou ALEATÓRIA.',
        'chave_pix' => 'Informe a chave PIX de uma conta de sua titularidade.',
        'banco' => 'Informe o código do banco (3 dígitos).',
        'agencia' => 'Informe a agência sem dígito (até 4 números).',
        'conta' => 'Informe o número da conta sem o dígito.',
        'conta_digito' => 'Informe o dígito da conta.',
    ];

    public static function cpf(string $text): ?string
    {
        if (!preg_match('/^[\d.\-\s]+$/', $text)) { return null; }
        $cpf = preg_replace('/\D/', '', $text);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) { return null; }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) { $sum += (int) $cpf[$i] * ($t + 1 - $i); }
            $digit = ($sum * 10) % 11;
            if (($digit === 10 ? 0 : $digit) !== (int) $cpf[$t]) { return null; }
        }
        return $cpf;
    }

    public static function next(array $data, bool $paymentOnly = false): ?string
    {
        $fields = $paymentOnly ? ['pagamento'] :
            ['nome','data_nascimento','telefone','cep','logradouro','numero','bairro','cidade','uf','pagamento'];
        $fields = array_merge($fields, ($data['pagamento'] ?? '') === 'PIX'
            ? ['tipo_pix','chave_pix'] : ['banco','agencia','conta','conta_digito']);
        foreach ($fields as $field) { if (!isset($data[$field])) { return $field; } }
        return null;
    }

    public static function pixPhone(string $text): ?string
    {
        if (!preg_match('/^[+\d()\s-]+$/', $text)) { return null; }
        $digits = preg_replace('/\D/', '', $text);
        if (str_starts_with(trim($text), '+') && !str_starts_with($digits, '55')) { return null; }
        // Contrato do legado: preserva a chave; acrescenta somente o país.
        if (strlen($digits) === 10 || strlen($digits) === 11) { $digits = '55'.$digits; }
        return preg_match('/^55\d{10,11}$/', $digits) ? '+'.$digits : null;
    }

    public static function validate(string $field, string $text, array $data): string|int|null
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 180) { return null; }
        if ($field === 'data_nascimento') {
            foreach (['d/m/Y','Y-m-d'] as $format) {
                $d = \DateTimeImmutable::createFromFormat('!'.$format, $text);
                if ($d && $d->format($format) === $text && $d < new \DateTimeImmutable('today') &&
                    $d > new \DateTimeImmutable('-120 years')) { return $d->format('Y-m-d').'T03:00:00.000Z'; }
            }
            return null;
        }
        if ($field === 'telefone') {
            if (!preg_match('/^[+\d()\s-]+$/', $text)) { return null; }
            $v = preg_replace('/\D/', '', $text);
            if (strlen($v) >= 12 && str_starts_with($v, '55')) { $v = substr($v, 2); }
            if (strlen($v) === 10) { $v = substr($v, 0, 2).'9'.substr($v, 2); }
            return preg_match('/^[1-9]\d9\d{8}$/', $v) ? $v : null;
        }
        if ($field === 'uf') {
            $v = strtoupper($text);
            return in_array($v, explode(' ', 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'), true) ? $v : null;
        }
        if ($field === 'pagamento') {
            return match (Text::normalize($text)) {
                'pix','por pix','chave pix' => 'PIX',
                'conta','conta bancaria','por conta' => 'CONTA',
                default => null,
            };
        }
        if ($field === 'tipo_pix') {
            return match (Text::normalize($text)) {
                'cpf','naturalregistrationnumber' => 'NaturalRegistrationNumber',
                'telefone','celular','phone' => 'Phone',
                'email','e mail' => 'Email',
                'aleatoria','aleatorio','chave aleatoria','evp','automatic' => 'Automatic',
                default => null,
            };
        }
        if ($field === 'chave_pix') {
            return match ($data['tipo_pix'] ?? '') {
                'NaturalRegistrationNumber' => self::cpf($text) !== null && self::cpf($text) === ($data['cpf'] ?? '')
                    ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $data['cpf']) : null,
                'Email' => filter_var($text, FILTER_VALIDATE_EMAIL) ?: null,
                'Phone' => self::pixPhone($text),
                'Automatic' => preg_match('/^(?:[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}|[a-z0-9]{32})$/i', $text) ? strtolower($text) : null,
                default => null,
            };
        }
        if ($field === 'cep') { return preg_match('/^\d{5}-?\d{3}$/', $text) ? str_replace('-', '', $text) : null; }
        if ($field === 'banco') { return preg_match('/^\d{3}$/', $text) ? $text : null; }
        if ($field === 'agencia') { return preg_match('/^\d{1,4}$/', $text) ? str_pad($text, 4, '0', STR_PAD_LEFT) : null; }
        if ($field === 'numero') { return preg_match('/^\d{1,8}$/', $text) ? (int) $text : null; }
        if ($field === 'conta') { return preg_match('/^\d{1,12}$/', $text) ? (int) $text : null; }
        if ($field === 'conta_digito') { return preg_match('/^\d$/', $text) ? $text : null; }
        if ($field === 'nome') { return preg_match('/^\p{L}[\p{L}\s\x{0027}-]+\s\p{L}[\p{L}\s\x{0027}-]*$/u', $text) ? $text : null; }
        return preg_match('/\p{L}/u', $text) ? $text : null;
    }

    /** Permite vários campos identificados na mesma mensagem, sem inferência de IA. */
    public static function collect(string $text, array &$data, bool $paymentOnly = false): ?string
    {
        $aliases = ['nome'=>'nome','nascimento'=>'data_nascimento','data nascimento'=>'data_nascimento',
            'data de nascimento'=>'data_nascimento','telefone'=>'telefone','celular'=>'telefone',
            'cep'=>'cep','rua'=>'logradouro','logradouro'=>'logradouro','numero'=>'numero','bairro'=>'bairro',
            'cidade'=>'cidade','uf'=>'uf','pagamento'=>'pagamento','tipo pix'=>'tipo_pix',
            'tipo de pix'=>'tipo_pix','chave pix'=>'chave_pix','banco'=>'banco','agencia'=>'agencia',
            'conta'=>'conta','digito'=>'conta_digito','conta digito'=>'conta_digito'];
        $entries = [];
        foreach (preg_split('/[;\r\n]+/', $text) as $line) {
            if (preg_match('/^([^:]+):\s*(.+)$/u', trim($line), $m)) {
                $field = $aliases[Text::normalize($m[1])] ?? null;
                if ($field !== null && (!$paymentOnly || in_array($field, self::PAYMENT_FIELDS, true))) { $entries[$field] = $m[2]; }
            }
        }
        if ($entries === []) {
            $field = self::next($data, $paymentOnly);
            if ($field === null) { return null; }
            $value = self::validate($field, preg_replace('/\s+/', ' ', $text), $data);
            if ($value === null) { return $field; }
            $data[$field] = $value;
            return null;
        }
        // Ordem do contrato garante que tipo PIX é validado antes da chave.
        $invalid = null;
        foreach (array_keys(self::QUESTIONS) as $field) {
            if (!isset($entries[$field])) { continue; }
            if ($field === 'pagamento') {
                $new = self::validate($field, $entries[$field], $data);
                if ($new !== null && $new !== ($data['pagamento'] ?? null)) {
                    foreach (self::PAYMENT_FIELDS as $key) { unset($data[$key]); }
                }
            }
            if ($field === 'tipo_pix' && self::validate($field, $entries[$field], $data) !== ($data['tipo_pix'] ?? null)) {
                unset($data['chave_pix']);
            }
            $value = self::validate($field, $entries[$field], $data);
            if ($value === null) { $invalid ??= $field; }
            else { $data[$field] = $value; }
        }
        return $invalid;
    }
}
