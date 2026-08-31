<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStage;
use App\Support\Bookings\BookingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every column BookingSource names must actually exist on its table.
 *
 * This exists because of a bug that reached production. CustomerSnapshot
 * hardcoded `customer_id` instead of calling customerColumn(), and
 * group_bookings has no such column — a group belongs to its organiser.
 *
 * The whole local suite passed anyway. SQLite quotes identifiers with double
 * quotes, and when the column does not exist it falls back to treating
 * "customer_id" as a *string literal* rather than raising an error, so the
 * query returned zero rows and looked healthy. MySQL correctly refuses, and the
 * customer portal returned 500 on the first real deployment.
 *
 * Asserting against the schema rather than against query results is what makes
 * this catch the problem on SQLite too.
 */
class BookingSourceColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_booking_source_table_exists(): void
    {
        foreach (BookingSource::cases() as $source) {
            $this->assertTrue(
                Schema::hasTable($source->table()),
                "{$source->value} names a table that does not exist: {$source->table()}",
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function columnAccessors(): array
    {
        return [
            'customerColumn' => ['customerColumn'],
            'serviceDateColumn' => ['serviceDateColumn'],
            'amountColumn' => ['amountColumn'],
            'currencyColumn' => ['currencyColumn'],
            'summaryColumn' => ['summaryColumn'],
        ];
    }

    #[DataProvider('columnAccessors')]
    public function test_the_named_column_exists_on_every_table(string $accessor): void
    {
        foreach (BookingSource::cases() as $source) {
            $column = $source->{$accessor}();

            $this->assertTrue(
                Schema::hasColumn($source->table(), $column),
                "{$source->value}->{$accessor}() returns '{$column}', which does not exist on "
                ."{$source->table()}. SQLite will not complain about this; MySQL will 500.",
            );
        }
    }

    public function test_the_customer_snapshot_queries_run_against_a_real_schema(): void
    {
        // Exercises the exact shape CustomerSnapshot builds, so a future edit
        // that reintroduces a hardcoded column is caught by the schema check
        // above rather than by a customer.
        foreach (BookingSource::cases() as $source) {
            $openStatuses = array_merge(
                $source->statusValuesInStage(BookingStage::AwaitingAction),
                $source->statusValuesInStage(BookingStage::Confirmed),
                $source->statusValuesInStage(BookingStage::InProgress),
            );

            $this->assertNotSame([], $openStatuses, "{$source->value} has no open statuses at all.");

            foreach (['customerColumn', 'serviceDateColumn', 'summaryColumn'] as $accessor) {
                $this->assertTrue(
                    Schema::hasColumn($source->table(), $source->{$accessor}()),
                    "{$source->value}->{$accessor}() names a missing column.",
                );
            }
        }
    }
}
