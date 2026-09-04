<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Somebody on the about page.
 *
 * Not a User. A driver or guide whose face belongs on the website does not need
 * a login, and the people who do have logins are mostly not who a customer
 * wants to see. Keeping them apart means publishing a profile never creates an
 * account, and deactivating an account never removes a face from the site by
 * surprise.
 */
class TeamMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'role_title',
        'summary',
        'biography',
        'email',
        'phone',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return MorphMany<Document, $this> */
    public function photographs(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::TeamPhoto->value)
            ->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * The most recent photograph.
     *
     * Uploading a new one does not delete the old, so the newest wins rather
     * than the first — replacing a portrait should not need a deletion first.
     */
    public function photoUrl(): ?string
    {
        $photo = $this->relationLoaded('photographs')
            ? $this->photographs->last()
            : $this->photographs()->latest('id')->first();

        return $photo?->url();
    }

    /** The only scope a public surface may use. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
