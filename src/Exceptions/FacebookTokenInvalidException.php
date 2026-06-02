<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Exceptions;

/**
 * The access token is invalid/expired (Graph code 190 / HTTP 401).
 * Recovery requires a fresh OAuth login — do NOT retry blindly.
 */
class FacebookTokenInvalidException extends FacebookGraphException {}
