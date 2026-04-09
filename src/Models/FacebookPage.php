<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use JohnWink\FilamentLeadPipeline\Database\Factories\FacebookPageFactory;

class FacebookPage extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'facebook_pages';

    protected $primaryKey = 'uuid';

    protected $fillable = [
        'facebook_connection_uuid',
        'page_id',
        'page_name',
        'page_access_token',
        'is_webhooks_subscribed',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FacebookConnection::class, 'facebook_connection_uuid');
    }

    public function forms(): HasMany
    {
        return $this->hasMany(FacebookForm::class, 'facebook_page_uuid');
    }

    public function leadSources(): HasMany
    {
        return $this->hasMany(LeadSource::class, 'facebook_page_uuid');
    }

    protected static function newFactory(): FacebookPageFactory
    {
        return FacebookPageFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'page_access_token'      => 'encrypted',
            'is_webhooks_subscribed' => 'boolean',
        ];
    }
}
