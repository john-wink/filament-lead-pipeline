<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Drivers;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Tables\Actions\Action as TableAction;
use JohnWink\FilamentLeadPipeline\Contracts\LeadSourceDriver;
use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;
use Throwable;

class MetaDriver implements LeadSourceDriver
{
    public function getDisplayName(): string
    {
        return 'Facebook / Meta';
    }

    public function validateConfig(array $config): bool
    {
        return true;
    }

    public function processWebhook(WebhookPayloadData $payload, LeadSource $source): LeadData
    {
        $config        = $source->config ?? [];
        $fieldMapping  = $config['field_mapping'] ?? [];
        $customMapping = $config['custom_field_mapping'] ?? [];
        $fieldData     = $this->extractFieldData($payload->raw_payload);
        $attribution   = $this->extractAttribution($payload->raw_payload);

        $name      = $this->findFirstMatch($fieldData, $fieldMapping['name'] ?? ['full_name', 'vollständiger_name']);
        $firstName = $this->findFirstMatch($fieldData, $fieldMapping['first_name'] ?? ['first_name', 'vorname']);
        $lastName  = $this->findFirstMatch($fieldData, $fieldMapping['last_name'] ?? ['last_name', 'nachname']);
        $email     = $this->findFirstMatch($fieldData, $fieldMapping['email'] ?? ['email', 'e-mail-adresse', 'e-mail']);
        $phone     = $this->findFirstMatch($fieldData, $fieldMapping['phone'] ?? ['phone_number', 'telefonnummer', 'phone']);

        if (( ! $name || '' === $name) && ($firstName || $lastName)) {
            $name = mb_trim("{$firstName} {$lastName}");
        }

        $customFields = [];
        foreach ($customMapping as $item) {
            $fbKey    = $item['facebook_key'] ?? '';
            $boardKey = $item['board_field_key'] ?? '__ignore__';
            if ('__ignore__' !== $boardKey && '__create__' !== $boardKey && isset($fieldData[$fbKey])) {
                $customFields[$boardKey] = $fieldData[$fbKey];
            }
        }

        return new LeadData(
            name: (string) ($name ?? ''),
            email: $email ? (string) $email : null,
            phone: $phone ? (string) $phone : null,
            custom_fields: $customFields,
            source_driver: 'meta',
            source_identifier: (string) $source->getKey(),
            source_campaign_id: $attribution['campaign_id'],
            source_campaign_name: $attribution['campaign_name'],
            source_adgroup_id: $attribution['adgroup_id'],
            source_adgroup_name: $attribution['adgroup_name'],
            source_ad_id: $attribution['ad_id'],
            source_ad_name: $attribution['ad_name'],
            source_channel: $attribution['channel'],
        );
    }

    public function verifySignature(string $payload, string $signature, LeadSource $source): bool
    {
        $appSecret         = config('lead-pipeline.facebook.client_secret');
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /** @return array<\Filament\Forms\Components\Component> */
    public function getConfigFormSchema(): array
    {
        return [
            Hidden::make('config.field_mapping')
                ->dehydrateStateUsing(fn ($state): array => is_array($state) ? $state : []),
            Hidden::make('config.loaded_fields')
                ->dehydrateStateUsing(fn ($state): array => is_array($state) ? $state : []),
            Placeholder::make('facebook_connect')
                ->label('')
                ->content(fn () => view('lead-pipeline::filament.components.facebook-connect-button')),
            Select::make('facebook_page_uuid')
                ->label(__('lead-pipeline::lead-pipeline.facebook.page'))
                ->options(fn () => FacebookPage::query()
                    ->whereHas('connection', fn ($q) => $q->where('user_uuid', auth()->id()))
                    ->pluck('page_name', 'uuid'))
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('facebook_form_ids', []))
                ->suffixAction(
                    FormAction::make('sync_pages')
                        ->label(__('lead-pipeline::lead-pipeline.facebook.sync_pages'))
                        ->icon('heroicon-o-arrow-path')
                        ->disabled(fn (): bool => ! FacebookConnection::query()
                            ->where('user_uuid', auth()->id())
                            ->where('status', FacebookConnectionStatusEnum::Connected)
                            ->exists())
                        ->action(function (): void {
                            $connection = FacebookConnection::query()
                                ->where('user_uuid', auth()->id())
                                ->where('status', FacebookConnectionStatusEnum::Connected)
                                ->first();

                            if ( ! $connection) {
                                return;
                            }

                            $summary = app(FacebookPageSynchronizer::class)->sync($connection);

                            \Filament\Notifications\Notification::make()
                                ->title(__('lead-pipeline::lead-pipeline.facebook.sync_completed'))
                                ->body(__('lead-pipeline::lead-pipeline.facebook.sync_summary', $summary))
                                ->success()
                                ->send();
                        }),
                ),
            Select::make('facebook_form_ids')
                ->label(__('lead-pipeline::lead-pipeline.facebook.forms'))
                ->multiple()
                ->options(function (callable $get) {
                    $pageUuid = $get('facebook_page_uuid');
                    if ( ! $pageUuid) {
                        return [];
                    }
                    $page = FacebookPage::find($pageUuid);

                    return $page?->forms()->pluck('form_name', 'form_id')->toArray() ?? [];
                })
                ->live()
                ->visible(fn (callable $get) => filled($get('facebook_page_uuid'))),
            Actions::make([
                FormAction::make('load_fields')
                    ->label(__('lead-pipeline::lead-pipeline.facebook.load_fields'))
                    ->icon('heroicon-o-arrow-path')
                    ->disabled(fn (callable $get): bool => blank($get('facebook_page_uuid')) || empty($get('facebook_form_ids')))
                    ->action(function (callable $get, callable $set): void {
                        $pageUuid = $get('facebook_page_uuid');
                        $formIds  = $get('facebook_form_ids') ?? [];

                        if ( ! $pageUuid || empty($formIds)) {
                            return;
                        }

                        $page      = FacebookPage::find($pageUuid);
                        $facebook  = app(FacebookGraphService::class);
                        $allFields = [];

                        foreach ($formIds as $formId) {
                            try {
                                $questions = $facebook->getFormQuestions($formId, $page->page_access_token);
                                foreach ($questions as $q) {
                                    $allFields[$q['key']] = $q;
                                }
                            } catch (Throwable) {
                                // Skip forms that can't be loaded
                            }
                        }

                        $set('config.loaded_fields', array_values($allFields));

                        // Auto-map standard fields
                        $fieldMapping = ['name' => [], 'first_name' => [], 'last_name' => [], 'email' => [], 'phone' => []];
                        $customItems  = [];

                        foreach ($allFields as $field) {
                            match ($field['type']) {
                                'FULL_NAME'  => $fieldMapping['name'][]       = $field['key'],
                                'FIRST_NAME' => $fieldMapping['first_name'][] = $field['key'],
                                'LAST_NAME'  => $fieldMapping['last_name'][]  = $field['key'],
                                'EMAIL'      => $fieldMapping['email'][]      = $field['key'],
                                'PHONE'      => $fieldMapping['phone'][]      = $field['key'],
                                default      => $customItems[]                = [
                                    'facebook_key'    => $field['key'],
                                    'facebook_label'  => $field['label'],
                                    'board_field_key' => '__ignore__',
                                ],
                            };
                        }

                        $set('config.field_mapping', $fieldMapping);
                        $set('config.custom_field_mapping', $customItems);
                    }),
            ])->visible(fn (callable $get) => filled($get('facebook_form_ids'))),
            Section::make(__('lead-pipeline::lead-pipeline.facebook.field_mapping'))
                ->schema([
                    View::make('lead-pipeline::filament.components.field-mapping-info'),
                    Repeater::make('config.custom_field_mapping')
                        ->label(__('lead-pipeline::lead-pipeline.facebook.custom_fields'))
                        ->schema([
                            TextInput::make('facebook_key')
                                ->label(__('lead-pipeline::lead-pipeline.facebook.fb_field'))
                                ->disabled()
                                ->dehydrated()
                                ->columnSpan(1),
                            TextInput::make('facebook_label')
                                ->label(__('lead-pipeline::lead-pipeline.facebook.fb_label'))
                                ->disabled()
                                ->dehydrated()
                                ->columnSpan(1),
                            Select::make('board_field_key')
                                ->label(__('lead-pipeline::lead-pipeline.facebook.board_field'))
                                ->options(function (callable $get) {
                                    $boardFk = LeadSource::fkColumn('lead_board');

                                    // Depth varies: Edit = 3 levels, Create = 4 levels
                                    // (extra Section wrapper in Create form)
                                    $boardId = null;
                                    foreach (['../../../', '../../../../'] as $prefix) {
                                        $boardId = $get($prefix . $boardFk);
                                        if ($boardId) {
                                            break;
                                        }
                                    }

                                    if ( ! $boardId) {
                                        return [
                                            '__ignore__' => __('lead-pipeline::lead-pipeline.facebook.no_mapping'),
                                            '__create__' => '+ ' . __('lead-pipeline::lead-pipeline.facebook.auto_create_fields'),
                                        ];
                                    }
                                    $board  = LeadBoard::find($boardId);
                                    $fields = $board?->fieldDefinitions()->custom()->ordered()->pluck('name', 'key')->toArray() ?? [];

                                    return [
                                        '__ignore__' => __('lead-pipeline::lead-pipeline.facebook.no_mapping'),
                                        '__create__' => '+ ' . __('lead-pipeline::lead-pipeline.facebook.auto_create_fields'),
                                        ...$fields,
                                    ];
                                })
                                ->live()
                                ->default('__ignore__')
                                ->columnSpan(1),
                            Select::make('create_field_type')
                                ->label(__('lead-pipeline::lead-pipeline.facebook.field_type_label'))
                                ->options(\JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum::class)
                                ->default('string')
                                ->visible(fn (callable $get) => '__create__' === $get('board_field_key'))
                                ->columnSpan(1),
                            \Filament\Forms\Components\Toggle::make('create_show_in_card')
                                ->label(__('lead-pipeline::lead-pipeline.facebook.show_on_card'))
                                ->default(false)
                                ->visible(fn (callable $get) => '__create__' === $get('board_field_key'))
                                ->columnSpan(1),
                        ])
                        ->columns(3)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->defaultItems(0),
                ])
                ->visible(fn (callable $get) => filled($get('config.loaded_fields'))),
            Select::make('default_assigned_to')
                ->label(__('lead-pipeline::lead-pipeline.actions.default_advisor'))
                ->options(fn () => FilamentLeadPipelinePlugin::getAssignableUsers()
                    ->pluck('display_label', 'uuid' === config('lead-pipeline.primary_key_type') ? 'uuid' : 'id'))
                ->nullable(),
        ];
    }

    public function getWebhookUrl(LeadSource $source): string
    {
        $prefix = config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks');

        return FilamentLeadPipelinePlugin::publicUrl("{$prefix}/meta");
    }

    /** @return array<string, string> */
    public function getDefaultFieldMapping(): array
    {
        return [
            'name'  => 'full_name',
            'email' => 'email',
            'phone' => 'phone_number',
        ];
    }

    /** @return array<TableAction> */
    public function getTableActions(LeadSource $source): array
    {
        $importForm = [
            Select::make('days')
                ->label(__('lead-pipeline::lead-pipeline.facebook.import_period'))
                ->options([
                    30  => __('lead-pipeline::lead-pipeline.facebook.import_30'),
                    60  => __('lead-pipeline::lead-pipeline.facebook.import_60'),
                    90  => __('lead-pipeline::lead-pipeline.facebook.import_90'),
                    180 => __('lead-pipeline::lead-pipeline.facebook.import_180'),
                    365 => __('lead-pipeline::lead-pipeline.facebook.import_365'),
                ])
                ->default(90)
                ->required(),
        ];

        return [
            TableAction::make('import_leads')
                ->label(__('lead-pipeline::lead-pipeline.facebook.import_leads'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (LeadSource $record) => filled($record->facebook_page_uuid)
                    && \JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum::Active === $record->status)
                ->form($importForm)
                ->modalDescription(__('lead-pipeline::lead-pipeline.facebook.import_description'))
                ->action(function (LeadSource $record, array $data): void {
                    \JohnWink\FilamentLeadPipeline\Jobs\ImportFacebookLeadsJob::dispatch($record, (int) $data['days']);

                    \Filament\Notifications\Notification::make()
                        ->title(__('lead-pipeline::lead-pipeline.facebook.import_started'))
                        ->body(__('lead-pipeline::lead-pipeline.facebook.import_started_body'))
                        ->success()
                        ->send();
                }),
            TableAction::make('reimport_leads')
                ->label(__('lead-pipeline::lead-pipeline.facebook.reimport_leads'))
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (LeadSource $record) => filled($record->facebook_page_uuid)
                    && \JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum::Active === $record->status
                    && $record->leads()->exists())
                ->form($importForm)
                ->modalDescription(__('lead-pipeline::lead-pipeline.facebook.reimport_description'))
                ->action(function (LeadSource $record, array $data): void {
                    \JohnWink\FilamentLeadPipeline\Jobs\ImportFacebookLeadsJob::dispatch($record, (int) $data['days'], true);

                    \Filament\Notifications\Notification::make()
                        ->title(__('lead-pipeline::lead-pipeline.facebook.reimport_started'))
                        ->body(__('lead-pipeline::lead-pipeline.facebook.reimport_started_body'))
                        ->success()
                        ->send();
                }),
            TableAction::make('reactivate_webhook')
                ->label(__('lead-pipeline::lead-pipeline.facebook.reactivate_webhook'))
                ->icon('heroicon-o-bolt-slash')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('lead-pipeline::lead-pipeline.facebook.reactivate_webhook_description'))
                ->hidden(fn (LeadSource $record) => blank($record->facebook_page_uuid))
                ->action(function (LeadSource $record): void {
                    $page = $record->facebookPage;

                    if ( ! $page) {
                        \Filament\Notifications\Notification::make()
                            ->title(__('lead-pipeline::lead-pipeline.facebook.reactivate_webhook_failed'))
                            ->body(__('lead-pipeline::lead-pipeline.facebook.reactivate_webhook_no_page'))
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        app(FacebookGraphService::class)->subscribePageToLeadgen(
                            $page->page_id,
                            $page->page_access_token,
                        );
                        $page->update(['is_webhooks_subscribed' => true]);

                        \Filament\Notifications\Notification::make()
                            ->title(__('lead-pipeline::lead-pipeline.facebook.reactivate_webhook_success'))
                            ->body(__('lead-pipeline::lead-pipeline.facebook.reactivate_webhook_success_body', ['page' => $page->page_name]))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        $page->update(['is_webhooks_subscribed' => false]);

                        \Filament\Notifications\Notification::make()
                            ->title(__('lead-pipeline::lead-pipeline.facebook.reactivate_webhook_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            TableAction::make('reconnect')
                ->label(__('lead-pipeline::lead-pipeline.facebook.reconnect'))
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->url(fn (): string => route('lead-pipeline.facebook.redirect'))
                ->openUrlInNewTab()
                ->hidden(fn (LeadSource $record): bool => ! $this->connectionNeedsAttention($record)),
        ];
    }

    /**
     * Extracts Meta attribution fields from either the raw webhook payload
     * (entry[].changes[].value.ad_id / adgroup_id) or a Graph-API-shaped payload
     * (top-level ad_id / adset_id / campaign_id / ...).
     *
     * @param  array<string, mixed>  $rawPayload
     * @return array{campaign_id: ?string, campaign_name: ?string, adgroup_id: ?string, adgroup_name: ?string, ad_id: ?string, ad_name: ?string, channel: ?string}
     */
    private function extractAttribution(array $rawPayload): array
    {
        $result = [
            'campaign_id'   => null,
            'campaign_name' => null,
            'adgroup_id'    => null,
            'adgroup_name'  => null,
            'ad_id'         => null,
            'ad_name'       => null,
            'channel'       => null,
        ];

        $sources = [$rawPayload];

        foreach ($rawPayload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (isset($change['value']) && is_array($change['value'])) {
                    $sources[] = $change['value'];
                }
            }
        }

        foreach ($sources as $src) {
            $result['campaign_id'] ??= $this->stringOrNull($src['campaign_id'] ?? null);
            $result['campaign_name'] ??= $this->stringOrNull($src['campaign_name'] ?? null);
            $result['adgroup_id'] ??= $this->stringOrNull($src['adset_id'] ?? $src['adgroup_id'] ?? null);
            $result['adgroup_name'] ??= $this->stringOrNull($src['adset_name'] ?? $src['adgroup_name'] ?? null);
            $result['ad_id'] ??= $this->stringOrNull($src['ad_id'] ?? null);
            $result['ad_name'] ??= $this->stringOrNull($src['ad_name'] ?? null);
            $result['channel'] ??= $this->stringOrNull($src['platform'] ?? null);
        }

        return $result;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (string) $value;
    }

    private function connectionNeedsAttention(LeadSource $source): bool
    {
        $connection = $source->facebookPage?->connection;

        if (null === $connection) {
            return false;
        }

        return $connection->needsReauth()
            || $connection->isInWarningWindow(now()->addDays((int) config('lead-pipeline.facebook.refresh.warning_days', 7)));
    }

    /** @return array<string, mixed> */
    private function extractFieldData(array $rawPayload): array
    {
        $fieldData = [];

        $entries = $rawPayload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                $fields = $change['value']['field_data'] ?? [];

                foreach ($fields as $field) {
                    $name   = $field['name'] ?? null;
                    $values = $field['values'] ?? [];

                    if (null !== $name && [] !== $values) {
                        $value            = 1 === count($values) ? $values[0] : $values;
                        $fieldData[$name] = $value;
                        $slug             = \Illuminate\Support\Str::slug($name, '_');
                        if ($slug !== $name && ! isset($fieldData[$slug])) {
                            $fieldData[$slug] = $value;
                        }
                    }
                }
            }
        }

        return $fieldData;
    }

    private function findFirstMatch(array $fieldData, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($fieldData[$key]) && '' !== $fieldData[$key]) {
                return (string) $fieldData[$key];
            }
        }

        return null;
    }
}
