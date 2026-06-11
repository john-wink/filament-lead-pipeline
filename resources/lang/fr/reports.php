<?php

declare(strict_types=1);

return [
    'password_required'    => 'This report is password protected',
    'password_placeholder' => 'Enter password',
    'password_submit'      => 'Open report',
    'password_invalid'     => 'The password is incorrect.',
    'synced_at'            => 'Last updated: :date',
    'imprint'              => 'Imprint',
    'download_pdf'         => 'Download PDF',
    'creatives_totals'     => 'Total: :impressions impr. · :leads leads · :spend €',

    'presets' => [
        'today'      => 'Today',
        'last7days'  => 'Last 7 days',
        'last30days' => 'Last 30 days',
        'last90days' => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'all_time'   => 'All time',
        'custom'     => 'Custom range',
    ],

    'sections' => [
        'kpis'      => 'Key metrics',
        'trend'     => 'Trend',
        'gender'    => 'Gender',
        'funnel'    => 'Sales funnel',
        'creatives' => 'Ads',
        'claim'     => 'About us',
    ],

    'kpis' => [
        'inquiries'        => 'Inquiries',
        'cost_per_inquiry' => 'Cost per inquiry',
        'spend'            => 'Amount spent',
        'reach'            => 'Reach',
        'impressions'      => 'Impressions',
        'link_clicks'      => 'Link clicks',
    ],

    'gender' => [
        'male'    => 'Male',
        'female'  => 'Female',
        'unknown' => 'Unknown',
    ],

    'funnel' => [
        'impressions' => 'Impressions',
        'link_clicks' => 'Link clicks',
        'inquiries'   => 'Inquiries',
        'qualified'   => 'Qualified',
        'won'         => 'Closed deals',
    ],

    'mail' => [
        'subject'      => 'Your current marketing report: :name',
        'intro'        => 'Your current marketing report ":name" is ready:',
        'failed_title' => 'Report delivery failed',
        'failed_body'  => 'The scheduled delivery of the report ":name" has failed repeatedly.',
    ],

    'actions' => [
        'preview'           => 'Open preview',
        'refresh'           => 'Refresh now',
        'refresh_started'   => 'Synchronisation started',
        'refresh_throttled' => 'A synchronisation is already running — please try again in 15 minutes.',
        'rotate_token'      => 'Rotate link',
        'download_pdf'      => 'Generate PDF',
    ],
    'resource' => [
        'singular' => 'Report',
        'plural'   => 'Reports',
        'tabs'     => [
            'content'    => 'Content',
            'branding'   => 'Branding',
            'sharing'    => 'Sharing',
            'scheduling' => 'Delivery',
        ],
        'fields' => [
            'date_preset_default' => 'Default range',
            'date_locked'         => 'Lock range for viewers',
            'sections'            => 'Sections',
            'funnel_mapping'      => 'Funnel mapping',
            'boards'              => 'Boards (lead data)',
            'ad_sources'          => 'Meta ad accounts',
            'connection'          => 'Facebook connection',
            'ad_account'          => 'Ad account',
            'campaigns'           => 'Campaigns',
            'campaigns_hint'      => 'Empty = all campaigns of the account',
            'missing_ads_read'    => 'This connection is missing the ads_read permission.',
            'reconnect'           => 'Reconnect',
            'logo'                => 'Logo',
            'co_logo'             => 'Co-branding logo',
            'accent_color'        => 'Accent color',
            'claim'               => 'Claim / intro text',
            'footer_text'         => 'Footer text',
            'branding_effective'  => 'Effective branding',
            'branding_inherited'  => 'Resolved accent color: :color (empty = inherits from team/platform default)',
            'share_url'           => 'Share link',
            'copy_link'           => 'Copy link',
            'password'            => 'Set new password',
            'expires_at'          => 'Valid until',
            'is_active'           => 'Active',
            'view_stats'          => 'Views (30 days)',
            'schedules'           => 'Schedules',
            'weekly'              => 'Weekly',
            'monthly'             => 'Monthly',
            'send_log'            => 'Delivery log (last 10)',
            'sync_state'          => 'Sync',
            'stale_hint'          => 'Data is stale — the last successful sync is overdue or failed.',
        ],
        'validation' => [
            'foreign_connection' => 'This Facebook connection does not belong to your team.',
            'foreign_board'      => 'This board is not shared with your team.',
        ],
    ],
];
