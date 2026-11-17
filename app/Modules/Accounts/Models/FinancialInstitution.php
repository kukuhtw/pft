<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $kind
 */
class FinancialInstitution extends Model
{
    use HasUlids;

    protected $fillable = ['code', 'name', 'kind', 'country_code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
