<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

/**
 * Base class for business-rule violations. Rendered as a JSON error envelope:
 * {"error": {"code": "...", "message": "...", "details": {...}}}
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, protected array $details = [])
    {
        parent::__construct($message);
    }

    abstract public function errorCode(): string;

    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
