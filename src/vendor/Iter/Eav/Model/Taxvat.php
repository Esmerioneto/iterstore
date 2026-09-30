<?php

declare(strict_types=1);

namespace Iter\Eav\Model;

/** Brazilian CPF (11 digits) and CNPJ (14 digits) check digits, for the cpf_cnpj input type and the customer */
class Taxvat
{
    /** digits only; null when it isn't a valid CPF or CNPJ */
    public static function normalize(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        return self::validCpf($digits) || self::validCnpj($digits) ? $digits : null;
    }

    public static function validCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * ($t + 1 - $i);
            }
            if ((int) $cpf[$t] !== $sum * 10 % 11 % 10) {
                return false;
            }
        }

        return true;
    }

    public static function validCnpj(string $cnpj): bool
    {
        if (!preg_match('/^\d{14}$/', $cnpj) || preg_match('/^(\d)\1+$/', $cnpj)) {
            return false;
        }
        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $t => $weights) {
            $sum = 0;
            foreach ($weights as $i => $w) {
                $sum += (int) $cnpj[$i] * $w;
            }
            if ((int) $cnpj[$t] !== ($sum % 11 < 2 ? 0 : 11 - $sum % 11)) {
                return false;
            }
        }

        return true;
    }
}
