<?php

namespace Tests\Feature\Governance;

use App\Actions\Settings\UpdateSettings;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuditAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();

        // Super administrators sit under the mandatory-2FA policy, which would
        // redirect before any governance route is reached. That gate has its
        // own coverage; relaxing it keeps these tests about governance.
        config(['security.two_factor.required_roles' => []]);
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    private function superAdmin(): User
    {
        return $this->user(UserRole::SuperAdmin, ['two_factor_required' => false]);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'company__name' => 'PISFA Tours and Travels',
            'company__tagline' => 'Uganda travel and transport',
            'company__email' => 'info@pisfa.test',
            'company__phone' => '+256700123456',
            'company__address' => 'Kampala, Uganda',
            'company__registration_number' => '',
            'company__tax_identification_number' => '',
            'site__announcement' => '',
            'site__accepting_online_bookings' => '1',
        ], $overrides);
    }

    // ---- The registry holds no credentials -------------------------------

    public function test_no_credential_can_be_stored_through_settings(): void
    {
        // A settings page that could hold an API key would defeat the reason
        // those values are kept in environment variables at all.
        foreach (SettingDefinition::keys() as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/(secret|password|token|api[_.-]?key|private[_.-]?key|webhook)/i',
                $key,
                "Setting [{$key}] looks like a credential and must not be editable.",
            );
        }
    }

    public function test_an_unregistered_key_is_never_written(): void
    {
        $admin = $this->superAdmin();

        app(UpdateSettings::class)->execute($admin, $this->settingsPayload([
            'stripe__secret' => 'sk_live_should_never_land',
            'app__key' => 'base64:nope',
        ]));

        // The form cannot be used to plant a value the application would read.
        $this->assertDatabaseMissing('settings', ['key' => 'stripe.secret']);
        $this->assertDatabaseMissing('settings', ['key' => 'app.key']);
        $this->assertSame(0, Setting::query()->whereNotIn('key', SettingDefinition::keys())->count());
    }

    public function test_an_unknown_key_reads_as_its_default(): void
    {
        // The registry is the list of what exists; an unknown key is a
        // programming mistake, not a value to invent.
        $this->assertSame('fallback', app(SettingsRepository::class)->get('not.a.setting', 'fallback'));
    }

    // ---- Reading and writing ---------------------------------------------

    public function test_a_stored_value_wins_over_config(): void
    {
        config(['pisfa.company.name' => 'Shipped Default Ltd']);
        $settings = app(SettingsRepository::class);

        $this->assertSame('Shipped Default Ltd', $settings->get('company.name'));

        Setting::setValue('company.name', 'Renamed After Launch');
        $settings->forget();

        // The whole reason these live in a table: a rename without a deploy.
        $this->assertSame('Renamed After Launch', $settings->get('company.name'));
    }

    public function test_a_change_is_audited_with_before_and_after(): void
    {
        $admin = $this->superAdmin();
        config(['pisfa.company.name' => 'Old Name Ltd']);

        $changes = app(UpdateSettings::class)->execute($admin, $this->settingsPayload([
            'company__name' => 'New Name Ltd',
        ]));

        $this->assertArrayHasKey('company.name', $changes);
        $this->assertSame('Old Name Ltd', $changes['company.name']['from']);
        $this->assertSame('New Name Ltd', $changes['company.name']['to']);

        $entry = AuditLog::query()->where('event', 'settings.updated')->sole();
        $this->assertSame($admin->getKey(), $entry->user_id);
        $this->assertContains('company.name', $entry->new_values['keys']);
        $this->assertSame('New Name Ltd', $entry->new_values['changes']['company.name']['to']);
    }

    public function test_an_unchanged_value_produces_no_audit_entry(): void
    {
        $admin = $this->superAdmin();
        config(['pisfa.company.name' => 'PISFA Tours and Travels']);

        $changes = app(UpdateSettings::class)->execute($admin, $this->settingsPayload());

        // Only what actually moved is recorded; a no-op save must not fill the
        // trail with noise.
        $this->assertArrayNotHasKey('company.name', $changes);
        $this->assertSame(0, AuditLog::query()
            ->where('event', 'settings.updated')
            ->whereJsonContains('new_values->keys', 'company.name')
            ->count());
    }

    public function test_an_unchecked_box_stores_false(): void
    {
        $admin = $this->superAdmin();

        // An unchecked checkbox posts nothing at all, so absence has to mean
        // false rather than "leave it alone".
        $payload = $this->settingsPayload();
        unset($payload['site__accepting_online_bookings']);

        app(UpdateSettings::class)->execute($admin, $payload + ['_submitted' => true]);

        $this->assertFalse(app(SettingsRepository::class)->bool('site.accepting_online_bookings'));
    }

    public function test_an_invalid_value_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(UpdateSettings::class)->execute($this->superAdmin(), $this->settingsPayload([
            'company__email' => 'not-an-email',
        ]));
    }

    public function test_a_required_setting_cannot_be_emptied(): void
    {
        $this->expectException(ValidationException::class);

        app(UpdateSettings::class)->execute($this->superAdmin(), $this->settingsPayload([
            'company__name' => '',
        ]));
    }

    // ---- Authorisation ---------------------------------------------------

    public function test_only_a_super_administrator_may_configure(): void
    {
        foreach ([UserRole::Manager, UserRole::Staff, UserRole::Customer, UserRole::Driver] as $role) {
            try {
                app(UpdateSettings::class)->execute(
                    $this->user($role, ['two_factor_required' => false]),
                    $this->settingsPayload(),
                );
                $this->fail($role->value.' must not be able to change settings.');
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertSame(0, Setting::query()->count());
    }

    public function test_a_suspended_super_administrator_cannot_configure(): void
    {
        $suspended = $this->user(UserRole::SuperAdmin, [
            'status' => AccountStatus::Suspended,
            'two_factor_required' => false,
        ]);

        $this->expectException(AuthorizationException::class);

        app(UpdateSettings::class)->execute($suspended, $this->settingsPayload());
    }

    public function test_a_manager_cannot_read_the_audit_trail(): void
    {
        // A manager appearing in the trail should not be the person who decides
        // what it says about them.
        $manager = $this->user(UserRole::Manager, ['two_factor_required' => false]);

        $this->actingAs($manager)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.settings.edit'))->assertForbidden();
    }

    public function test_a_customer_cannot_reach_governance(): void
    {
        $customer = $this->user(UserRole::Customer);

        $this->actingAs($customer)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.settings.edit'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
    }

    // ---- The viewer ------------------------------------------------------

    public function test_the_trail_is_readable_and_filterable(): void
    {
        $admin = $this->superAdmin();
        $logger = app(AuditLogger::class);

        $logger->record(event: 'payment.settled', user: $admin);
        $logger->record(event: 'invoice.issued', user: $admin);
        $logger->record(event: 'review.published', user: $admin);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('payment.settled')
            ->assertSee('invoice.issued');

        // A prefix match, so "payment" finds every payment.* entry.
        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['event' => 'payment']))
            ->assertOk()
            ->assertSee('payment.settled')
            ->assertDontSee('invoice.issued');
    }

    public function test_an_entry_can_be_opened(): void
    {
        $admin = $this->superAdmin();

        $entry = app(AuditLogger::class)->record(
            event: 'quotation.sent',
            newValues: ['number' => 'QTN-2026-00001', 'total_minor' => 500000],
            user: $admin,
        );

        $this->actingAs($admin)
            ->get(route('admin.audit.show', $entry))
            ->assertOk()
            ->assertSee('quotation.sent')
            ->assertSee('QTN-2026-00001');
    }

    public function test_redacted_values_stay_redacted_in_the_viewer(): void
    {
        $admin = $this->superAdmin();

        $entry = app(AuditLogger::class)->record(
            event: 'staff.updated',
            newValues: ['password' => 'hunter2', 'api_key' => 'sk_live_abc', 'role' => 'manager'],
            user: $admin,
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.audit.show', $entry))
            ->assertOk();

        // The trail records that something changed without becoming a second
        // copy of what changed.
        $response->assertSee('[REDACTED]');
        $response->assertDontSee('hunter2');
        $response->assertDontSee('sk_live_abc');
    }

    public function test_an_entry_with_no_actor_says_so(): void
    {
        $admin = $this->superAdmin();

        // A scheduled command has no actor. Attributing it to somebody would be
        // worse than admitting it.
        AuditLog::query()->create(['event' => 'quotation.expired', 'user_id' => null]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('System or guest');
    }

    public function test_an_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.audit.index', ['from' => '2026-09-01', 'to' => '2026-08-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_the_trail_offers_no_way_to_change_it(): void
    {
        $admin = $this->superAdmin();
        $entry = app(AuditLogger::class)->record(event: 'payment.settled', user: $admin);

        // A trail somebody can amend is not a trail: there is no route at all.
        foreach (['PATCH', 'PUT', 'DELETE', 'POST'] as $method) {
            $this->actingAs($admin)
                ->call($method, route('admin.audit.show', $entry))
                ->assertStatus(405);
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $entry->getKey()]);
    }

    // ---- Settings over HTTP ----------------------------------------------

    public function test_a_super_administrator_saves_settings_over_http(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Credentials are not here');

        $this->actingAs($admin)
            ->patch(route('admin.settings.update'), $this->settingsPayload([
                'company__name' => 'PISFA Uganda Ltd',
            ]))
            ->assertRedirect(route('admin.settings.edit'));

        $this->assertSame('PISFA Uganda Ltd', app(SettingsRepository::class)->get('company.name'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_the_document_layout_renders_stored_branding(): void
    {
        $admin = $this->superAdmin();

        app(UpdateSettings::class)->execute($admin, $this->settingsPayload([
            'company__name' => 'Renamed For The PDF',
            'company__registration_number' => 'REG-12345',
        ]));

        // The layout is rendered as Blade rather than driven through DomPDF:
        // the claim under test is that the template reads the brand array
        // instead of config, and rasterising a PDF to prove it costs a great
        // deal of memory for no extra confidence.
        $html = view('pdf.layout', ['brand' => app(SettingsRepository::class)->brand()])->render();

        $this->assertStringContainsString('Renamed For The PDF', $html);
        $this->assertStringContainsString('REG-12345', $html);
    }

    public function test_the_brand_array_reflects_stored_settings(): void
    {
        app(UpdateSettings::class)->execute($this->superAdmin(), $this->settingsPayload([
            'company__name' => 'Renamed Ltd',
            'company__registration_number' => 'REG-999',
        ]));

        $brand = app(SettingsRepository::class)->brand();

        $this->assertSame('Renamed Ltd', $brand['name']);
        $this->assertSame('REG-999', $brand['registration_number']);
    }
}
