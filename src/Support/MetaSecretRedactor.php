<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Support;

use JohnWink\FilamentLeadPipeline\Exceptions\FacebookGraphException;
use Throwable;

final class MetaSecretRedactor
{
    private const string SECRET_PATTERN = '/(access_token|appsecret_proof|client_secret|fb_exchange_token)=[^&\s"\']+/i';

    public static function redact(string $text): string
    {
        return (string) preg_replace(self::SECRET_PATTERN, '$1=[REDACTED]', $text);
    }

    /**
     * @return array{exception_class: class-string<Throwable>, http_status: int|null, meta_code: int|null, error: string}
     */
    public static function logContext(Throwable $exception): array
    {
        $graphException = $exception instanceof FacebookGraphException ? $exception : null;

        return [
            'exception_class' => $exception::class,
            'http_status'     => $graphException?->httpStatus,
            'meta_code'       => $graphException?->graphCode(),
            'error'           => self::redact($exception->getMessage()),
        ];
    }
}
