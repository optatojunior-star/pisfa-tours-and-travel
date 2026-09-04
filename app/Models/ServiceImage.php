<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Support\ServiceCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A picture standing in for one service's line icon.
 *
 * The row carries almost nothing. It exists so an uploaded file has an owner —
 * documents belong to a record, and "the car hire service" was not a record.
 * Everything else about a service still comes from ServiceCatalogue, which
 * stays the one place that says what PISFA offers.
 */
class ServiceImage extends Model
{
    use HasFactory;

    protected $fillable = ['service_key'];

    /** @return MorphMany<Document, $this> */
    public function photographs(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable')
            ->where('category', DocumentCategory::ServiceIcon->value)
            ->orderBy('id');
    }

    public function imageUrl(): ?string
    {
        $image = $this->relationLoaded('photographs')
            ? $this->photographs->last()
            : $this->photographs()->latest('id')->first();

        return $image?->url();
    }

    /**
     * Every row, keyed by service, for the screen that manages them.
     *
     * @return array<array-key, self>
     */
    public static function keyedByService(): array
    {
        return static::query()
            ->with('photographs')
            ->get()
            ->keyBy('service_key')
            ->all();
    }

    /**
     * Every service's picture, keyed by service, for one query per page.
     *
     * Services with no picture are simply absent, and the caller falls back to
     * the line icon — a missing upload must never blank a homepage card.
     *
     * @return array<string, string>
     */
    public static function urlsByServiceKey(): array
    {
        return static::query()
            ->whereIn('service_key', array_keys(ServiceCatalogue::SERVICES))
            ->with('photographs')
            ->get()
            ->mapWithKeys(static fn (self $row): array => [
                $row->service_key => (string) $row->imageUrl(),
            ])
            ->filter(static fn (string $url): bool => $url !== '')
            ->all();
    }
}
