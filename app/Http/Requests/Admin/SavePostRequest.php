<?php

namespace App\Http\Requests\Admin;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

class SavePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');

        return $post instanceof Post
            ? ($this->user()?->can('update', $post) ?? false)
            : ($this->user()?->can('create', Post::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:4', 'max:200'],
            // Lower-case, hyphen-separated: the slug is a URL, not a headline.
            'slug' => ['nullable', 'string', 'max:200', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'excerpt' => ['required', 'string', 'min:20', 'max:500'],
            'body' => ['required', 'string', 'min:50'],
            'post_category_id' => ['nullable', 'integer', 'exists:post_categories,id'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'is_featured' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array', 'max:12'],
            'tags.*' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'A slug may contain only lower-case letters, numbers, and hyphens.',
            'excerpt.min' => 'Write a summary readers can judge the article from.',
        ];
    }

    /** Tags arrive as one comma-separated field and are split here. */
    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');

        if (is_string($tags)) {
            $this->merge([
                'tags' => array_values(array_filter(array_map('trim', explode(',', $tags)))),
            ]);
        }
    }
}
