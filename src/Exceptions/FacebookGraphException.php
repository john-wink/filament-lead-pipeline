<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

use RuntimeException;

class FacebookGraphException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $error  The Graph `error` object, if any.
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $error = null,
    ) {
        parent::__construct($message);
    }

    public function graphCode(): ?int
    {
        $code = $this->error['code'] ?? null;

        return is_int($code) ? $code : (is_numeric($code) ? (int) $code : null);
    }

    public function graphSubcode(): ?int
    {
        $sub = $this->error['error_subcode'] ?? null;

        return is_int($sub) ? $sub : (is_numeric($sub) ? (int) $sub : null);
    }
}
