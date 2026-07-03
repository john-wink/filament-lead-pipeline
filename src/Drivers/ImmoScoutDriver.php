<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Drivers;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use JohnWink\FilamentLeadPipeline\Contracts\LeadSourceDriver;
use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Support\ImmoScoutLeadMapper;

/**
 * ImmoScout24 construction financing leads. The API is pull-only: leads are
 * polled via the scheduler (SyncImmoScoutLeadsCommand) and via the manual
 * import actions — there is no webhook channel.
 */
class ImmoScoutDriver implements LeadSourceDriver
{
    /**
     * @param  array<string, mixed>  $data
     * @return string The new connection's uuid
     */
    public static function createConnection(array $data): string
    {
        $connection = ImmoScoutConnection::query()->create([
            'team_uuid'           => filament()->getTenant()?->getKey(),
            'user_uuid'           => auth()->id(),
            'name'                => $data['name'],
            'environment'         => $data['environment'],
            'consumer_key'        => $data['consumer_key'],
            'consumer_secret'     => $data['consumer_secret'],
            'access_token'        => $data['access_token'] ?? null,
            'access_token_secret' => $data['access_token_secret'] ?? null,
            'scout_id'            => $data['scout_id'] ?? null,
            'status'              => ImmoScoutConnectionStatusEnum::Connected,
        ]);

        return (string) $connection->getKey();
    }

    public function getDisplayName(): string
    {
        return 'ImmoScout24';
    }

    public function validateConfig(array $config): bool
    {
        return filled($config['immoscout_connection_uuid'] ?? null);
    }

    public function processWebhook(WebhookPayloadData $payload, LeadSource $source): LeadData
    {
        $data = (new ImmoScoutLeadMapper())->map($payload->raw_payload);

        $data->source_identifier = (string) $source->getKey();

        return $data;
    }

    public function verifySignature(string $payload, string $signature, LeadSource $source): bool
    {
        return false;
    }

    /** @return array<\Filament\Forms\Components\Component> */
    public function getConfigFormSchema(): array
    {
        return [
            Select::make('config.immoscout_connection_uuid')
                ->label(__('lead-pipeline::lead-pipeline.immoscout.connection'))
                ->options(function (): array {
                    $tenant = filament()->getTenant();

                    return ImmoScoutConnection::query()
                        ->when($tenant, fn ($query) => $query->where('team_uuid', $tenant->getKey()))
                        ->orderBy('name')
                        ->pluck('name', 'uuid')
                        ->all();
                })
                ->helperText(__('lead-pipeline::lead-pipeline.immoscout.connection_help'))
                ->required()
                ->createOptionForm([
                    TextInput::make('name')
                        ->label(__('lead-pipeline::lead-pipeline.field.name'))
                        ->required(),
                    Select::make('environment')
                        ->label(__('lead-pipeline::lead-pipeline.immoscout.environment'))
                        ->options(ImmoScoutEnvironmentEnum::class)
                        ->default(ImmoScoutEnvironmentEnum::Production->value)
                        ->required(),
                    TextInput::make('consumer_key')
                        ->label(__('lead-pipeline::lead-pipeline.immoscout.consumer_key'))
                        ->required(),
                    TextInput::make('consumer_secret')
                        ->label(__('lead-pipeline::lead-pipeline.immoscout.consumer_secret'))
                        ->password()
                        ->revealable()
                        ->required(),
                    TextInput::make('access_token')
                        ->label(__('lead-pipeline::lead-pipeline.immoscout.access_token'))
                        ->helperText(__('lead-pipeline::lead-pipeline.immoscout.access_token_help'))
                        ->password()
                        ->revealable(),
                    TextInput::make('access_token_secret')
                        ->label(__('lead-pipeline::lead-pipeline.immoscout.access_token_secret'))
                        ->password()
                        ->revealable(),
                    TextInput::make('scout_id')
                        ->label(__('lead-pipeline::lead-pipeline.immoscout.scout_id'))
                        ->helperText(__('lead-pipeline::lead-pipeline.immoscout.scout_id_help')),
                ])
                ->createOptionUsing(fn (array $data): string => static::createConnection($data)),

            Toggle::make('config.auto_sync')
                ->label(__('lead-pipeline::lead-pipeline.immoscout.auto_sync'))
                ->helperText(__('lead-pipeline::lead-pipeline.immoscout.auto_sync_help'))
                ->default(true),
        ];
    }

    public function getWebhookUrl(LeadSource $source): string
    {
        return '';
    }

    /** @return array<string, string> */
    public function getDefaultFieldMapping(): array
    {
        return [];
    }

    /** @return array<Action> */
    public function getTableActions(LeadSource $source): array
    {
        return [
            Action::make('import_leads')
                ->label(__('lead-pipeline::lead-pipeline.immoscout.import_leads'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (LeadSource $record): bool => 'immoscout24' === $record->driver)
                ->form([
                    Select::make('days')
                        ->label(__('lead-pipeline::lead-pipeline.immoscout.import_days'))
                        ->options([
                            7   => __('lead-pipeline::lead-pipeline.immoscout.days_7'),
                            30  => __('lead-pipeline::lead-pipeline.immoscout.days_30'),
                            90  => __('lead-pipeline::lead-pipeline.immoscout.days_90'),
                            180 => __('lead-pipeline::lead-pipeline.immoscout.days_180'),
                            365 => __('lead-pipeline::lead-pipeline.immoscout.days_365'),
                        ])
                        ->default(90)
                        ->required(),
                ])
                ->action(function (LeadSource $record, array $data): void {
                    ImportImmoScoutLeadsJob::dispatch($record, (int) $data['days']);

                    Notification::make()
                        ->title(__('lead-pipeline::lead-pipeline.immoscout.import_queued'))
                        ->success()
                        ->send();
                }),

            Action::make('import_test_leads')
                ->label(__('lead-pipeline::lead-pipeline.immoscout.import_test_leads'))
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->visible(fn (LeadSource $record): bool => 'immoscout24' === $record->driver)
                ->requiresConfirmation()
                ->modalDescription(__('lead-pipeline::lead-pipeline.immoscout.import_test_leads_help'))
                ->action(function (LeadSource $record): void {
                    ImportImmoScoutLeadsJob::dispatch($record, testMode: true);

                    Notification::make()
                        ->title(__('lead-pipeline::lead-pipeline.immoscout.import_queued'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
