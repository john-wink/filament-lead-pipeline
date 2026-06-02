<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

/**
 * A temporary Graph failure (rate limit, 5xx, network). Safe to retry.
 */
class FacebookTransientException extends FacebookGraphException {}
