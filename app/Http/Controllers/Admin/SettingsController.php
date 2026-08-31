<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdateSettings;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function edit(Request $request): View
    {
        $this->authorize('viewAny', Setting::class);

        return view('admin.settings.index', [
            'groups' => SettingDefinition::grouped(),
            'values' => $this->settings->all(),
            'fieldName' => fn (string $key): string => str_replace('.', '__', $key),
        ]);
    }

    public function update(Request $request, UpdateSettings $action): RedirectResponse
    {
        $this->authorize('update', Setting::class);

        // A marker the action uses to tell "unchecked box" from "field absent",
        // which is the difference between setting false and leaving alone.
        $changes = $action->execute($request->user(), $request->all() + ['_submitted' => true]);

        return redirect()
            ->route('admin.settings.edit')
            ->with('success', $changes === []
                ? 'Nothing changed.'
                : count($changes).' '.str('setting')->plural(count($changes)).' updated and recorded in the audit log.');
    }
}
