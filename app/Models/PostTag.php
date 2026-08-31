<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $tag
 * @property Post|null $post
 */
class PostTag extends Model
{
    use HasFactory;

    protected $table = 'post_tag';

    protected $fillable = ['post_id', 'tag'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Normalises a submitted tag to the stored form.
     *
     * Lower-cased and hyphenated, so "Gorilla Trekking", "gorilla trekking",
     * and "Gorilla-Trekking" are one tag rather than three.
     */
    public static function normalise(string $tag): string
    {
        return str($tag)->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();
    }
}
