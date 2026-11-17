<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Modules\Ledger\Enums\LedgerAccountClass;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property string $name
 * @property LedgerAccountClass $ledger_class
 * @property bool $is_liquid
 * @property bool $is_active
 */
class AccountType extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'name', 'ledger_class', 'is_liquid', 'is_active'];

    protected function casts(): array
    {
        return [
            'ledger_class' => LedgerAccountClass::class,
            'is_liquid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
