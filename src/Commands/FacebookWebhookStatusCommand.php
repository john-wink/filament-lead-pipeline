<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

class FacebookWebhookStatusCommand extends Command
{
    protected $signature = 'lead-pipeline:facebook-webhook-status
        {--team= : Filter by team slug or uuid}';

    protected $description = 'Live-Check welche Facebook-Pages aktuell einen Leadgen-Webhook zu uns haben';

    public function handle(FacebookGraphService $facebook): int
    {
        $teamFilter = $this->option('team');

        $connections = $this->loadConnections($teamFilter);

        if ($connections->isEmpty()) {
            $this->warn('Keine Facebook-Verbindungen gefunden' . ($teamFilter ? " für Team '{$teamFilter}'." : '.'));

            return self::SUCCESS;
        }

        $totalCount   = 0;
        $okCount      = 0;
        $brokenCount  = 0;
        $expiredCount = 0;
        $errorCount   = 0;

        foreach ($connections as $connection) {
            $teamName   = $connection->team?->name ?? '—';
            $connUser   = $connection->facebook_user_name ?? '—';
            $tokenStale = $connection->isExpired();

            foreach ($connection->pages as $page) {
                $totalCount++;
                $status = '';

                if ($tokenStale) {
                    $status = 'TOKEN ABGELAUFEN';
                    $expiredCount++;
                } else {
                    try {
                        $pageAccessToken = $page->page_access_token;
                        $subscribed      = $facebook->isPageSubscribedToLeadgen($page->page_id, $pageAccessToken);
                        $page->update(['is_webhooks_subscribed' => $subscribed]);

                        if ($subscribed) {
                            $status = 'OK';
                            $okCount++;
                        } else {
                            $status = 'NICHT ABONNIERT';
                            $brokenCount++;
                        }
                    } catch (DecryptException) {
                        $status = 'TOKEN UNLESBAR (APP_KEY-Mismatch — neu verbinden oder APP_PREVIOUS_KEYS setzen)';
                        $errorCount++;
                    } catch (Exception $e) {
                        $status = 'FEHLER: ' . $this->truncate($e->getMessage(), 200);
                        $errorCount++;
                    }
                }

                $this->renderPageBlock($teamName, $connUser, $page, $status);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d Pages geprüft — %d OK, %d nicht abonniert, %d abgelaufen, %d Fehler',
            $totalCount,
            $okCount,
            $brokenCount,
            $expiredCount,
            $errorCount,
        ));

        return self::SUCCESS;
    }

    private function renderPageBlock(string $teamName, string $connUser, FacebookPage $page, string $status): void
    {
        $marker = match (true) {
            'OK' === $status                           => '<fg=green>✓</>',
            'TOKEN ABGELAUFEN' === $status             => '<fg=yellow>!</>',
            str_starts_with($status, 'TOKEN UNLESBAR') => '<fg=yellow>!</>',
            str_starts_with($status, 'FEHLER')         => '<fg=red>✗</>',
            'NICHT ABONNIERT' === $status              => '<fg=red>✗</>',
            default                                    => '·',
        };

        $this->line('');
        $this->line(sprintf('%s %s (%s)', $marker, $page->page_name, $page->page_id));
        $this->line(sprintf('    Team:       %s', $teamName));
        $this->line(sprintf('    Connection: %s', $connUser));
        $this->line(sprintf('    Forms:      %s', $this->formatFormCount($page)));
        $this->line(sprintf('    Status:     %s', $status));
        $this->line(sprintf('    Letzter Lead: %s', $this->formatLastLead($page)));
    }

    /** @return Collection<int, FacebookConnection> */
    private function loadConnections(?string $teamFilter): Collection
    {
        $query = FacebookConnection::query()->with(['team', 'pages']);

        if ($teamFilter) {
            $teamModel = config('lead-pipeline.tenancy.model');
            $teamId    = $teamModel::query()
                ->where('slug', $teamFilter)
                ->orWhere('uuid', $teamFilter)
                ->value('uuid');

            $query->where('team_uuid', $teamId);
        }

        return $query->get();
    }

    private function formatFormCount(FacebookPage $page): string
    {
        $totalForms = $page->forms()->count();

        $mappedIds = LeadSource::query()
            ->where('facebook_page_uuid', $page->uuid)
            ->where('status', LeadSourceStatusEnum::Active)
            ->get()
            ->flatMap(fn (LeadSource $s) => $s->facebook_form_ids ?? [])
            ->unique()
            ->count();

        return "{$mappedIds}/{$totalForms}";
    }

    private function formatLastLead(FacebookPage $page): string
    {
        $latest = LeadSource::query()
            ->where('facebook_page_uuid', $page->uuid)
            ->max('last_received_at');

        return $latest ? $latest : 'nie';
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
