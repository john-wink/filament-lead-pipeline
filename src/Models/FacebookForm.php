<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JohnWink\FilamentLeadPipeline\Database\Factories\FacebookFormFactory;

class FacebookForm extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'facebook_forms';

    protected $primaryKey = 'uuid';

    protected $fillable = [
        'facebook_page_uuid',
        'form_id',
        'form_name',
        'status',
        'cached_at',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_uuid');
    }

    public function isActive(): bool
    {
        return 'active' === $this->status;
    }

    protected static function newFactory(): FacebookFormFactory
    {
        return FacebookFormFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cached_at' => 'datetime',
        ];
    }
}
