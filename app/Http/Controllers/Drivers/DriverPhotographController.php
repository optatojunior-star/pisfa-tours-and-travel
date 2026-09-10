<?php

namespace App\Http\Controllers\Drivers;

use App\Actions\Documents\DeleteDocument;
use App\Enums\DocumentCategory;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A driver's own headshot.
 *
 * It exists for one moment: a customer standing in the arrivals hall at Entebbe,
 * deciding whether the person walking towards them is the driver PISFA sent.
 * Until now the confirmation gave them a name and, if the office had recorded
 * one, a phone number — which answers the question only after the stranger is
 * already talking to them.
 *
 * Drivers manage their own photograph rather than the office doing it. It is
 * their face, they can retake it in a moment when it stops looking like them,
 * and there was no admin screen for driver profiles to put it on anyway.
 */
class DriverPhotographController extends Controller
{
    use HandlesImageUploads;

    public function store(Request $request): RedirectResponse
    {
        $profile = $this->ownProfile($request);

        $request->validate($this->imageRules('images', 1), $this->imageMessages());

        // Single-slot category: StoreDocument supersedes and removes the
        // previous file on its own, so there is nothing to clear here.
        $rejected = $this->storeUploadedImages($request, $profile, DocumentCategory::DriverPhoto);

        return $this->withRejectedImages(
            back()->with('success', $rejected === []
                ? 'Your photograph was saved. Customers expecting you will see it on their confirmation.'
                : 'Your photograph was not saved.'),
            $rejected,
        );
    }

    public function destroy(Request $request, DeleteDocument $deleteDocument): RedirectResponse
    {
        $profile = $this->ownProfile($request);
        $photograph = $profile->photograph();

        if ($photograph === null) {
            return back()->with('success', 'There was no photograph to remove.');
        }

        $deleteDocument->execute($request->user(), $photograph, 'Removed by the driver.');

        return back()->with('success', 'Your photograph was removed. Customers will see your name only.');
    }

    /**
     * The signed-in driver's own profile, and nobody else's.
     *
     * There is no route parameter to tamper with — the profile is resolved from
     * the session — so a driver cannot reach another driver's photograph even
     * by guessing an id.
     */
    private function ownProfile(Request $request): DriverProfile
    {
        $user = $request->user();

        abort_unless($user !== null && $user->hasRole(UserRole::Driver), 403);

        $profile = $user->driverProfile;

        // A driver whose profile the office has not created yet has nothing to
        // attach a photograph to. That is a message, not a 500.
        abort_if($profile === null, 404, 'Your driver profile has not been set up yet.');

        return $profile;
    }
}
