<?php
declare(strict_types=1);
namespace App\Services;

final class Text
{
    public static function normalize(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = strtolower($ascii === false ? $text : $ascii);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
    }

    public static function yes(string $text): bool
    {
        return in_array(self::normalize($text), [
            'sim', 's', 'aceito', 'aceitar', 'autorizo', 'concordo', 'pode sim',
            'sim autorizo', 'sim aceito', 'quero', 'quero sim', 'pode continuar',
        ], true);
    }

    public static function no(string $text): bool
    {
        return in_array(self::normalize($text), [
            'nao', 'n', 'nao quero', 'nao aceito', 'nao autorizo', 'nao obrigado',
            'nao obrigada', 'recuso', 'recusar', 'cancelar', 'cancela', 'sem interesse',
        ], true);
    }

    public static function human(string $text): bool
    {
        return in_array(self::normalize($text), [
            'humano', 'atendente', 'falar com atendente', 'quero falar com atendente',
            'falar com uma pessoa', 'preciso de ajuda', 'ajuda',
        ], true);
    }

    public static function money(string $text): ?float
    {
        $text = trim(str_ireplace('R$', '', $text));
        if (!preg_match('/^\d+(?:[.,]\d+)*$/', $text)) { return null; }
        if (str_contains($text, ',')) { $text = str_replace(',', '.', str_replace('.', '', $text)); }
        elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $text)) { $text = str_replace('.', '', $text); }
        return is_numeric($text) && (float) $text > 0 ? round((float) $text, 2) : null;
    }
}
