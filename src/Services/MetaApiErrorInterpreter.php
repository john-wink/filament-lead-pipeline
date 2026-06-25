<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

/**
 * Übersetzt das `error`-Objekt einer Graph-/Conversions-API-Antwort in eine
 * handlungsleitende Diagnose: WELCHE Berechtigung fehlt bzw. ob der Fehler nur
 * transient ist. So kann der Sende-Job beim abgelehnten POST konkret loggen,
 * welcher Scope/welches Asset ergänzt werden muss, statt nur den HTTP-Status.
 *
 * Quellen: Graph-API-Fehlercodes (190-Subcodes 458-467/492, 200/299, 100/33,
 * 10, Rate-Limits 4/17/80004, Policy-Block 368).
 */
class MetaApiErrorInterpreter
{
    /**
     * @param  array<string, mixed>|null  $error  Das `error`-Objekt aus dem Response-Body.
     * @return array{
     *     category: string,
     *     retryable: bool,
     *     missing_permission: string|null,
     *     required_action: string,
     *     code: int|null,
     *     subcode: int|null,
     *     message: string|null,
     *     fbtrace_id: string|null,
     * }
     */
    public function interpret(?array $error): array
    {
        $code    = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $message = isset($error['message']) ? (string) $error['message'] : null;
        $trace   = isset($error['fbtrace_id']) ? (string) $error['fbtrace_id'] : null;

        [$category, $retryable, $missingPermission, $action] = $this->classify($code, $subcode, $message);

        return [
            'category'           => $category,
            'retryable'          => $retryable,
            'missing_permission' => $missingPermission,
            'required_action'    => $action,
            'code'               => $code,
            'subcode'            => $subcode,
            'message'            => $message,
            'fbtrace_id'         => $trace,
        ];
    }

    /**
     * @return array{0: string, 1: bool, 2: string|null, 3: string}
     */
    private function classify(?int $code, ?int $subcode, ?string $message): array
    {
        if (null === $code) {
            return ['none', false, null, 'No Meta error returned.'];
        }

        if (in_array($code, [4, 17, 32, 80001, 80004, 368], true)) {
            return ['transient', true, null, 'Transient (rate limit / temporary block). Retry with backoff; do not change permissions.'];
        }

        if (190 === $code) {
            return ['token_invalid', false, null, sprintf(
                'Meta access token is invalid/expired (subcode %s). Reconnect the Meta connection (re-run OAuth or regenerate the system-user token).',
                $subcode ?? 'n/a',
            )];
        }

        if (299 === $code) {
            return ['missing_permission', false, 'events_management', 'Grant the "events_management" permission to the Meta connection (re-run OAuth with the added scope).'];
        }

        if (200 === $code) {
            $permission = $this->extractPermission($message);

            return ['missing_permission', false, $permission, $permission
                ? sprintf('Grant the "%s" permission to the Meta connection (re-run OAuth with the added scope) and assign the dataset as a Business asset.', $permission)
                : 'A permission is missing on the Meta connection. Grant the permission named in the Meta error message and re-run OAuth.'];
        }

        if (100 === $code && 33 === $subcode) {
            return ['dataset_access', false, null, 'The token cannot see the Dataset. In Business Settings → Data Sources → Datasets, assign the dataset to the connected user/system user ("Manage Pixel" / "Use this dataset"), and verify the dataset id is correct.'];
        }

        if (10 === $code) {
            return ['app_permission', false, null, 'The app lacks permission for this action. Request the required permission/feature (Advanced Access) for the Meta app.'];
        }

        return ['unknown', false, null, sprintf('Unrecognised Meta error (code %d). Inspect the message and fbtrace_id.', $code)];
    }

    private function extractPermission(?string $message): ?string
    {
        if (null === $message) {
            return null;
        }

        if (1 === preg_match('/Requires (\w+) permission/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
