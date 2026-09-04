<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\DeleteDocument;
use App\Enums\DocumentCategory;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTeamMemberRequest;
use App\Models\TeamMember;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The people shown on the about page.
 *
 * Publishing is separate from saving, so a profile can be written, checked and
 * held back rather than appearing on the public site the moment somebody starts
 * typing a name into it.
 */
class TeamMemberController extends Controller
{
    use HandlesImageUploads;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(): View
    {
        $this->authorize('viewAny', TeamMember::class);

        return view('admin.team.index', [
            'members' => TeamMember::query()
                ->with('photographs')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', TeamMember::class);

        return view('admin.team.create', ['member' => null]);
    }

    public function store(SaveTeamMemberRequest $request): RedirectResponse
    {
        $member = DB::transaction(function () use ($request): TeamMember {
            $member = new TeamMember;
            $member->fill($request->validated());
            $member->created_by_user_id = $request->user()?->getKey();
            $member->updated_by_user_id = $request->user()?->getKey();
            $member->save();

            $this->auditLogger->record(
                event: 'team_member.created',
                auditable: $member,
                newValues: ['name' => $member->name, 'role_title' => $member->role_title],
                user: $request->user(),
            );

            return $member;
        }, 3);

        $rejected = $this->storeUploadedImages($request, $member, DocumentCategory::TeamPhoto);

        return $this->withRejectedImages(
            redirect()->route('admin.team.edit', $member)
                ->with('success', 'Profile saved. Publish it when the photograph looks right.'),
            $rejected,
        );
    }

    public function edit(TeamMember $team): View
    {
        $this->authorize('update', $team);

        return view('admin.team.edit', ['member' => $team->load('photographs')]);
    }

    public function update(SaveTeamMemberRequest $request, TeamMember $team): RedirectResponse
    {
        DB::transaction(function () use ($request, $team): void {
            $locked = TeamMember::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            $previous = ['name' => $locked->name, 'role_title' => $locked->role_title];

            $locked->fill($request->validated());
            $locked->updated_by_user_id = $request->user()?->getKey();
            $locked->save();

            $this->auditLogger->record(
                event: 'team_member.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: ['name' => $locked->name, 'role_title' => $locked->role_title],
                user: $request->user(),
            );
        }, 3);

        $rejected = $this->storeUploadedImages($request, $team, DocumentCategory::TeamPhoto);

        return $this->withRejectedImages(
            redirect()->route('admin.team.edit', $team)->with('success', 'The profile was updated.'),
            $rejected,
        );
    }

    /**
     * Publishing needs a photograph.
     *
     * A team grid with one blank silhouette in it looks broken rather than
     * incomplete, and the fix — upload a picture — is one the person publishing
     * can do straight away, so it is a useful thing to insist on.
     */
    public function publish(Request $request, TeamMember $team): RedirectResponse
    {
        $this->authorize('update', $team);

        if (! $team->photographs()->exists()) {
            return back()->withErrors(['images' => 'Add a photograph before publishing this profile.']);
        }

        $team->forceFill([
            'is_published' => true,
            'updated_by_user_id' => $request->user()?->getKey(),
        ])->save();

        $this->auditLogger->record(
            event: 'team_member.published',
            auditable: $team,
            newValues: ['name' => $team->name],
            user: $request->user(),
        );

        return back()->with('success', $team->name.' is now shown on the about page.');
    }

    public function unpublish(Request $request, TeamMember $team): RedirectResponse
    {
        $this->authorize('update', $team);

        $team->forceFill([
            'is_published' => false,
            'updated_by_user_id' => $request->user()?->getKey(),
        ])->save();

        $this->auditLogger->record(
            event: 'team_member.unpublished',
            auditable: $team,
            newValues: ['name' => $team->name],
            user: $request->user(),
        );

        return back()->with('success', $team->name.' was taken off the about page.');
    }

    /**
     * Removing somebody entirely.
     *
     * Their photographs go with them: leaving orphaned files on a public disk
     * after a person has asked to be taken off the site is not a deletion.
     */
    public function destroy(Request $request, TeamMember $team): RedirectResponse
    {
        $this->authorize('delete', $team);

        $name = $team->name;

        DB::transaction(function () use ($request, $team): void {
            $action = app(DeleteDocument::class);

            foreach ($team->photographs()->get() as $photograph) {
                $action->execute($request->user(), $photograph, 'Team profile removed.');
            }

            $this->auditLogger->record(
                event: 'team_member.deleted',
                auditable: $team,
                oldValues: ['name' => $team->name, 'role_title' => $team->role_title],
                user: $request->user(),
            );

            $team->delete();
        }, 3);

        return redirect()->route('admin.team.index')->with('success', $name.' was removed.');
    }
}
