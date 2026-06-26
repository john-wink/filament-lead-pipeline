<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookGraphException;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use Throwable;

class FacebookSetupWebhookCommand extends Command
{
    protected $signature = 'lead-pipeline:facebook-setup-webhook';

    protected $description = 'Registriert das App-Level-Leadgen-Webhook-Abo bei Meta (Callback-URL + Verify-Token), damit Leads in Echtzeit ankommen';

    public function handle(FacebookGraphService $facebook): int
    {
        $clientId     = config('lead-pipeline.facebook.client_id');
        $clientSecret = config('lead-pipeline.facebook.client_secret');
        $verifyToken  = config('lead-pipeline.facebook.verify_token');

        if ( ! $clientId || ! $clientSecret) {
            $this->error('FACEBOOK_CLIENT_ID / FACEBOOK_CLIENT_SECRET sind nicht konfiguriert.');

            return self::FAILURE;
        }

        if ( ! $verifyToken) {
            $this->error('FACEBOOK_VERIFY_TOKEN ist nicht konfiguriert — ohne ihn lehnt Meta die Callback-URL ab.');

            return self::FAILURE;
        }

        $prefix      = config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks');
        $callbackUrl = FilamentLeadPipelinePlugin::publicUrl(mb_rtrim((string) $prefix, '/') . '/meta');

        $this->line('Registriere App-Level-Webhook bei Meta …');
        $this->line("  Callback-URL: {$callbackUrl}");
        $this->line('  Feld:         leadgen');

        try {
            $facebook->subscribeAppToLeadgen($callbackUrl, (string) $verifyToken);
        } catch (FacebookGraphException $e) {
            $this->newLine();
            $this->error('Meta hat das Abo abgelehnt: ' . $e->getMessage());
            $this->warn('Mögliche Ursachen:');
            $this->line('  • Verify-Token: FACEBOOK_VERIFY_TOKEN muss in DIESER Umgebung identisch zum App-Dashboard hinterlegt sein.');
            $this->line('  • Erreichbarkeit: Die Callback-URL muss öffentlich erreichbar sein — eine WAF/Firewall darf Metas Request nicht blocken.');
            $this->line('  • URL/Pfad: Die Callback-URL muss exakt auf den Webhook-Endpunkt zeigen.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Unerwarteter Fehler: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ App-Level-Webhook aktiviert.');

        try {
            foreach ($facebook->getAppSubscriptions() as $subscription) {
                if ('page' !== ($subscription['object'] ?? null)) {
                    continue;
                }

                $fields = collect($subscription['fields'] ?? [])->pluck('name')->implode(', ');
                $active = ($subscription['active'] ?? false) ? 'aktiv' : 'inaktiv';
                $this->line("  Bestätigt: object=page, Status={$active}, Felder={$fields}");
            }
        } catch (Throwable) {
            // Read-back is best-effort; the subscription POST already succeeded.
        }

        return self::SUCCESS;
    }
}
