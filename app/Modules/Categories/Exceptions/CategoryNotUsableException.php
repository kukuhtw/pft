<?php

declare(strict_types=1);

namespace App\Modules\Categories\Exceptions;

use App\Modules\Shared\Exceptions\DomainException;

final class CategoryNotUsableException extends DomainException
{
    public function errorCode(): string
    {
        return 'CATEGORY_NOT_USABLE';
    }
}
