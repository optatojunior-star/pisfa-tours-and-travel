<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_audit_log_for_the_authenticated_user_and_subject(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create(['name' => 'Before']);

        $this->actingAs($actor);

        $auditLog = app(AuditLogger::class)->record(
            event: 'user.updated',
            auditable: $subject,
            oldValues: [
                'name' => 'Before',
                'password' => 'old-secret',
                'recovery_codes' => ['old-recovery-code'],
                'otp' => '123456',
                'national_id_number' => 'CM1234567890',
                'nested' => [
                    'document_path' => 'car-hire/HIRE-PRIVATE/identity.pdf',
                    'service_address' => 'Private residence',
                    'safe_label' => 'Customer supplied document',
                ],
            ],
            newValues: [
                'name' => 'After',
                'password' => 'new-secret',
                'signature' => 'raw-signature',
                'two_factor_code' => '654321',
                'driving_permit_number' => 'DL9876543210',
                'identity_document_url' => 'https://private.example.test/identity.pdf',
                'flight_number' => 'PISFA 123',
                'idempotency_key' => 'private-replay-key',
                'contact_phone' => '+256700000000',
            ],
            context: [
                'source' => 'test-suite',
                'passport_number' => 'P1234567',
                'url' => 'https://pisfa.test/admin/users/2',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PISFA Test',
            ],
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $auditLog->id,
            'user_id' => $actor->id,
            'event' => 'user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $subject->id,
            'url' => 'https://pisfa.test/admin/users/2',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PISFA Test',
        ]);

        $this->assertSame([
            'name' => 'Before',
            'password' => '[REDACTED]',
            'recovery_codes' => '[REDACTED]',
            'otp' => '[REDACTED]',
            'national_id_number' => '[REDACTED]',
            'nested' => [
                'document_path' => '[REDACTED]',
                'service_address' => '[REDACTED]',
                'safe_label' => 'Customer supplied document',
            ],
        ], $auditLog->old_values);
        $this->assertSame([
            'name' => 'After',
            'password' => '[REDACTED]',
            'signature' => '[REDACTED]',
            'two_factor_code' => '[REDACTED]',
            'driving_permit_number' => '[REDACTED]',
            'identity_document_url' => '[REDACTED]',
            'flight_number' => '[REDACTED]',
            'idempotency_key' => '[REDACTED]',
            'contact_phone' => '[REDACTED]',
        ], $auditLog->new_values);
        $this->assertSame([
            'source' => 'test-suite',
            'passport_number' => '[REDACTED]',
        ], $auditLog->context);
        $this->assertTrue($auditLog->user->is($actor));
        $this->assertTrue($auditLog->auditable->is($subject));
    }

    public function test_it_redacts_tokens_from_sensitive_urls(): void
    {
        $auditLog = app(AuditLogger::class)->record(
            event: 'staff.invitation.accepted',
            context: [
                'url' => 'https://pisfa.test/staff/invitations/raw-invitation-token?signature=raw-signature&view=compact',
            ],
        );

        $this->assertSame(
            'https://pisfa.test/staff/invitations/[REDACTED]?signature=[REDACTED]&view=compact',
            $auditLog->url,
        );
        $this->assertStringNotContainsString('raw-invitation-token', $auditLog->url);
        $this->assertStringNotContainsString('raw-signature', $auditLog->url);
    }
}
