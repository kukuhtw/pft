<?php

declare(strict_types=1);

namespace App\Modules\Counterparties\Enums;

enum CounterpartyKind: string
{
    case PERSON = 'PERSON';
    case ORGANIZATION = 'ORGANIZATION';
    case UNKNOWN = 'UNKNOWN';

    /** "PT Maju Jaya", "CV …", "Bank …", "Koperasi …" are organisations; "Pak Budi" is a person. */
    public static function guess(string $name): self
    {
        $n = mb_strtolower(trim($name));

        if (preg_match('/^(pt|cv|ud|pd|tbk|bank|koperasi|yayasan|kantor|toko|firma)\b|\b(tbk|inc|ltd|llc|corp)\.?$/u', $n) === 1) {
            return self::ORGANIZATION;
        }

        if (preg_match('/^(pak|bu|bapak|ibu|mas|mbak|mba|kak|bang|om|tante|sdr|sdri|bro|sis)\b/u', $n) === 1) {
            return self::PERSON;
        }

        return self::UNKNOWN;
    }
}
