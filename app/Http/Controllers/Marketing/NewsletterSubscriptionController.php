<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreNewsletterSubscriptionRequest;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\RedirectResponse;

class NewsletterSubscriptionController extends Controller
{
    public function __invoke(StoreNewsletterSubscriptionRequest $request): RedirectResponse
    {
        $data = $request->validated();

        NewsletterSubscriber::query()->updateOrCreate(
            ['email' => $data['email']],
            [
                'status' => 'active',
                'source' => $data['source'],
                'subscribed_at' => now(),
            ]
        );

        return back()->with(
            'newsletter_success',
            'You are subscribed. We will send useful PISFA travel updates to this email address.'
        );
    }
}
