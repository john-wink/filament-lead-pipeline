<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Services\MetaApiErrorInterpreter;

beforeEach(function (): void {
    $this->interpreter = new MetaApiErrorInterpreter();
});

it('treats a missing error object as no actionable failure', function (): void {
    $verdict = $this->interpreter->interpret(null);

    expect($verdict['category'])->toBe('none')
        ->and($verdict['retryable'])->toBeFalse()
        ->and($verdict['missing_permission'])->toBeNull();
});

it('classifies an invalid/expired token as a reconnect issue, not a permission gap', function (): void {
    $verdict = $this->interpreter->interpret([
        'code'          => 190,
        'error_subcode' => 463,
        'type'          => 'OAuthException',
        'message'       => 'Error validating access token: Session has expired.',
        'fbtrace_id'    => 'Abc123',
    ]);

    expect($verdict['category'])->toBe('token_invalid')
        ->and($verdict['retryable'])->toBeFalse()
        ->and($verdict['missing_permission'])->toBeNull()
        ->and($verdict['code'])->toBe(190)
        ->and($verdict['subcode'])->toBe(463)
        ->and($verdict['fbtrace_id'])->toBe('Abc123')
        ->and($verdict['required_action'])->toContain('Reconnect');
});

it('extracts the exact missing permission from a code 200 message', function (): void {
    $verdict = $this->interpreter->interpret([
        'code'    => 200,
        'type'    => 'OAuthException',
        'message' => '(#200) Requires ads_management permission to manage the object',
    ]);

    expect($verdict['category'])->toBe('missing_permission')
        ->and($verdict['missing_permission'])->toBe('ads_management')
        ->and($verdict['retryable'])->toBeFalse()
        ->and($verdict['required_action'])->toContain('ads_management');
});

it('maps code 299 to a missing events_management permission', function (): void {
    $verdict = $this->interpreter->interpret([
        'code'    => 299,
        'message' => 'Permissions error',
    ]);

    expect($verdict['category'])->toBe('missing_permission')
        ->and($verdict['missing_permission'])->toBe('events_management')
        ->and($verdict['retryable'])->toBeFalse();
});

it('treats code 100 subcode 33 as a dataset asset-assignment problem, not a scope', function (): void {
    $verdict = $this->interpreter->interpret([
        'code'          => 100,
        'error_subcode' => 33,
        'type'          => 'GraphMethodException',
        'message'       => "Unsupported post request. Object with ID '123' does not exist, cannot be loaded due to missing permissions, or does not support this operation",
    ]);

    expect($verdict['category'])->toBe('dataset_access')
        ->and($verdict['missing_permission'])->toBeNull()
        ->and($verdict['retryable'])->toBeFalse()
        ->and($verdict['required_action'])->toContain('Dataset');
});

it('classifies an app-level permission error', function (): void {
    $verdict = $this->interpreter->interpret([
        'code'    => 10,
        'message' => 'Application does not have permission for this action',
    ]);

    expect($verdict['category'])->toBe('app_permission')
        ->and($verdict['retryable'])->toBeFalse();
});

it('marks rate limiting and transient policy blocks as retryable without a permission action', function (array $error): void {
    $verdict = $this->interpreter->interpret($error);

    expect($verdict['category'])->toBe('transient')
        ->and($verdict['retryable'])->toBeTrue()
        ->and($verdict['missing_permission'])->toBeNull();
})->with([
    'app rate limit'  => [['code' => 4, 'message' => 'Application request limit reached']],
    'user rate limit' => [['code' => 17, 'message' => 'User request limit reached']],
    'custom limit'    => [['code' => 80004, 'message' => 'There have been too many calls']],
    'policy block'    => [['code' => 368, 'message' => 'Temporarily blocked for policies violations']],
]);

it('falls back to unknown for an unrecognised error code', function (): void {
    $verdict = $this->interpreter->interpret([
        'code'    => 1,
        'message' => 'An unknown error occurred',
    ]);

    expect($verdict['category'])->toBe('unknown')
        ->and($verdict['retryable'])->toBeFalse();
});
