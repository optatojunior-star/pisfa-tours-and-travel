@php
    $isEdit = $post !== null;
    // The slug is the public URL, frozen once the post has been live.
    $slugLocked = $isEdit && $post->published_at !== null;
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.posts.update', $post) : route('admin.posts.store') }}" enctype="multipart/form-data" class="space-y-6">
    @csrf
    @if ($isEdit) @method('PATCH') @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
            <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="post-content">
        <h2 id="post-content" class="text-lg font-black text-slate-950">The article</h2>

        <div class="mt-5 space-y-5">
            <div>
                <label for="title" class="block text-sm font-semibold text-slate-800">Headline</label>
                <input id="title" name="title" type="text" required minlength="4" maxlength="200"
                       value="{{ old('title', $post?->title) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('title')" class="mt-1" />
            </div>

            <div>
                <label for="slug" class="block text-sm font-semibold text-slate-800">URL slug</label>
                <input id="slug" name="slug" type="text" maxlength="200" @disabled($slugLocked)
                       value="{{ old('slug', $post?->slug) }}"
                       placeholder="Derived from the headline when left blank"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600 disabled:bg-stone-100 disabled:text-slate-500">
                <p class="mt-1 text-xs text-slate-500">
                    @if ($slugLocked)
                        Fixed, because this post has been public. Changing it would break every shared link.
                    @else
                        Lower-case letters, numbers, and hyphens.
                    @endif
                </p>
                <x-input-error :messages="$errors->get('slug')" class="mt-1" />
            </div>

            <div>
                <label for="excerpt" class="block text-sm font-semibold text-slate-800">Summary</label>
                <textarea id="excerpt" name="excerpt" rows="3" required minlength="20" maxlength="500"
                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('excerpt', $post?->excerpt) }}</textarea>
                <p class="mt-1 text-xs text-slate-500">Shown on listing cards and used as the page description.</p>
                <x-input-error :messages="$errors->get('excerpt')" class="mt-1" />
            </div>

            {{--
                The cover image. blog/index and blog/show have always rendered
                one — Post::coverUrl() reads the first BlogMedia document — and
                there was simply no field anywhere that could put a file there,
                so every article on the site showed the plain fallback header.
            --}}
            <div>
                <x-image-upload
                    name="images"
                    label="Cover image"
                    help="The picture at the top of the article and on its listing card. The first one uploaded is the one used."
                    :existing="$post?->media"
                    :delete-route="$post ? fn ($image) => route('admin.media.destroy', $image) : null" />
            </div>
            <div>
                <label for="body" class="block text-sm font-semibold text-slate-800">Body</label>
                <textarea id="body" name="body" rows="18" required minlength="50"
                          class="mt-1 block w-full rounded-xl border-slate-300 font-mono text-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('body', $post?->body) }}</textarea>
                <p class="mt-1 text-xs text-slate-500">
                    Plain text. Leave a blank line between paragraphs. Markup is escaped when the article renders.
                </p>
                <x-input-error :messages="$errors->get('body')" class="mt-1" />
            </div>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="post-meta">
        <h2 id="post-meta" class="text-lg font-black text-slate-950">Classification and SEO</h2>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="post_category_id" class="block text-sm font-semibold text-slate-800">Category</label>
                <select id="post_category_id" name="post_category_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Uncategorised</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) old('post_category_id', $post?->post_category_id) === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('post_category_id')" class="mt-1" />
            </div>

            <div>
                <label for="tags" class="block text-sm font-semibold text-slate-800">Tags</label>
                <input id="tags" name="tags" type="text"
                       value="{{ old('tags', $post ? implode(', ', $post->tagList()) : '') }}"
                       placeholder="safari, bwindi, packing"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">Comma separated, up to twelve. Case and spacing are normalised.</p>
                <x-input-error :messages="$errors->get('tags')" class="mt-1" />
            </div>

            <div>
                <label for="meta_title" class="block text-sm font-semibold text-slate-800">SEO title</label>
                <input id="meta_title" name="meta_title" type="text" maxlength="200"
                       value="{{ old('meta_title', $post?->meta_title) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">Falls back to the headline.</p>
            </div>

            <div>
                <label for="meta_description" class="block text-sm font-semibold text-slate-800">SEO description</label>
                <input id="meta_description" name="meta_description" type="text" maxlength="300"
                       value="{{ old('meta_description', $post?->meta_description) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">Falls back to the summary.</p>
            </div>

            <label class="flex items-center gap-2 sm:col-span-2">
                <input type="hidden" name="is_featured" value="0">
                <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $post?->is_featured))
                       class="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                <span class="text-sm text-slate-700">Feature this article on the journal and the home page</span>
            </label>
        </div>
    </section>

    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-6 py-3 text-sm font-bold text-white hover:bg-emerald-800">
        {{ $isEdit ? 'Save changes' : 'Save draft' }}
    </button>
</form>
