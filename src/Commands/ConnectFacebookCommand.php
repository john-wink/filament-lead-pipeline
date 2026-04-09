<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Exception;
use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookForm;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

class ConnectFacebookCommand extends Command
{
    protected $signature = 'lead-pipeline:connect-facebook
        {--user= : User UUID}
        {--team= : Team UUID}
        {--token= : Long-lived User Access Token from Graph API Explorer}';

    protected $description = 'Connect a Facebook account manually via Access Token';

    public function handle(FacebookGraphService $facebook): int
    {
        $userUuid = $this->option('user') ?? $this->ask('User UUID');
        $teamUuid = $this->option('team') ?? $this->ask('Team UUID');
        $token    = $this->option('token');

        if ( ! $token) {
            $this->info('');
            $this->info(__('lead-pipeline::lead-pipeline.command.token_instructions'));
            $this->info(__('lead-pipeline::lead-pipeline.command.step_1'));
            $this->info(__('lead-pipeline::lead-pipeline.command.step_2'));
            $this->info(__('lead-pipeline::lead-pipeline.command.step_3'));
            $this->info(__('lead-pipeline::lead-pipeline.command.step_4'));
            $this->info(__('lead-pipeline::lead-pipeline.command.step_5'));
            $this->info('');
            $token = $this->ask('Access Token');
        }

        $this->info(__('lead-pipeline::lead-pipeline.command.exchanging_token'));

        try {
            $longLived = $facebook->exchangeForLongLivedToken($token);
            $token     = $longLived['access_token'];
            $expiresIn = $longLived['expires_in'] ?? 5184000;
            $this->info(__('lead-pipeline::lead-pipeline.command.token_received', ['seconds' => $expiresIn]));
        } catch (Exception $e) {
            $this->warn(__('lead-pipeline::lead-pipeline.command.token_failed', ['error' => $e->getMessage()]));
            $expiresIn = 3600;
        }

        $this->info(__('lead-pipeline::lead-pipeline.command.loading_profile'));
        $me = $facebook->getMe($token);
        $this->info(__('lead-pipeline::lead-pipeline.command.connected_as', ['name' => $me['name'], 'id' => $me['id']]));

        $connection = FacebookConnection::query()->updateOrCreate(
            [
                'user_uuid'        => $userUuid,
                'facebook_user_id' => $me['id'],
            ],
            [
                'team_uuid'          => $teamUuid,
                'facebook_user_name' => $me['name'],
                'access_token'       => $token,
                'token_expires_at'   => now()->addSeconds($expiresIn),
                'scopes'             => config('lead-pipeline.facebook.scopes'),
                'status'             => 'connected',
            ],
        );

        $this->info(__('lead-pipeline::lead-pipeline.command.connection_created', ['uuid' => $connection->uuid]));

        $this->info(__('lead-pipeline::lead-pipeline.command.loading_pages'));
        $pages = $facebook->getUserPages($token);

        if (empty($pages)) {
            $this->warn(__('lead-pipeline::lead-pipeline.command.no_pages'));

            return self::SUCCESS;
        }

        foreach ($pages as $pageData) {
            $page = FacebookPage::query()->updateOrCreate(
                [
                    'facebook_connection_uuid' => $connection->uuid,
                    'page_id'                  => $pageData['id'],
                ],
                [
                    'page_name'         => $pageData['name'],
                    'page_access_token' => $pageData['access_token'],
                ],
            );

            $this->info('  ' . __('lead-pipeline::lead-pipeline.command.page_found', ['name' => $pageData['name'], 'id' => $pageData['id']]));

            // Load forms for this page
            try {
                $forms = $facebook->getPageLeadForms($pageData['id'], $pageData['access_token']);

                foreach ($forms as $formData) {
                    FacebookForm::query()->updateOrCreate(
                        [
                            'facebook_page_uuid' => $page->uuid,
                            'form_id'            => $formData['id'],
                        ],
                        [
                            'form_name' => $formData['name'] ?? "Form {$formData['id']}",
                            'cached_at' => now(),
                        ],
                    );
                    $this->info('    ' . __('lead-pipeline::lead-pipeline.command.form_found', ['name' => $formData['name'], 'id' => $formData['id']]));
                }

                if (empty($forms)) {
                    $this->warn('    ' . __('lead-pipeline::lead-pipeline.command.no_forms'));
                }
            } catch (Exception $e) {
                $this->warn('    ' . __('lead-pipeline::lead-pipeline.command.forms_failed', ['error' => $e->getMessage()]));
            }

            // Webhook abonnieren
            try {
                $facebook->subscribePageToLeadgen($pageData['id'], $pageData['access_token']);
                $page->update(['is_webhooks_subscribed' => true]);
                $this->info('    ' . __('lead-pipeline::lead-pipeline.command.webhook_subscribed'));
            } catch (Exception $e) {
                $this->warn('    ' . __('lead-pipeline::lead-pipeline.command.webhook_failed', ['error' => $e->getMessage()]));
            }
        }

        $this->newLine();
        $this->info(__('lead-pipeline::lead-pipeline.command.done'));

        return self::SUCCESS;
    }
}
