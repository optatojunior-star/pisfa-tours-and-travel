<?php

namespace App\Providers;

use App\Models\Airport;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use App\Models\AuditLog;
use App\Models\CarHireBooking;
use App\Models\CarHireContract;
use App\Models\CarHireDocument;
use App\Models\Conversation;
use App\Models\CorporateAccount;
use App\Models\Document;
use App\Models\Expense;
use App\Models\FlightInquiry;
use App\Models\GroupBooking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Post;
use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\Review;
use App\Models\Setting;
use App\Models\TourBooking;
use App\Models\TourCategory;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImportOrder;
use App\Models\VehicleLease;
use App\Models\VehicleLeaseApplication;
use App\Models\VehicleLeasePayout;
use App\Models\VehicleListing;
use App\Models\VehicleSalesEnquiry;
use App\Policies\AirportPolicy;
use App\Policies\AirportTransferBookingPolicy;
use App\Policies\AirportTransferLocationPolicy;
use App\Policies\AirportTransferRatePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CarHireBookingPolicy;
use App\Policies\CarHireContractPolicy;
use App\Policies\CarHireDocumentPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\CorporateAccountPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FlightInquiryPolicy;
use App\Policies\GroupBookingPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PayrollLinePolicy;
use App\Policies\PayrollRunPolicy;
use App\Policies\PostPolicy;
use App\Policies\PropertyBookingPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\QuotationPolicy;
use App\Policies\QuotationRequestPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\SettingPolicy;
use App\Policies\TourBookingPolicy;
use App\Policies\TourCategoryPolicy;
use App\Policies\TourDeparturePolicy;
use App\Policies\TourPackagePolicy;
use App\Policies\VehicleImportOrderPolicy;
use App\Policies\VehicleLeaseApplicationPolicy;
use App\Policies\VehicleLeasePayoutPolicy;
use App\Policies\VehicleLeasePolicy;
use App\Policies\VehicleListingPolicy;
use App\Policies\VehiclePolicy;
use App\Policies\VehicleSalesEnquiryPolicy;
use App\Services\StaffUserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, StaffUserPolicy::class);
        Gate::policy(TourCategory::class, TourCategoryPolicy::class);
        Gate::policy(TourPackage::class, TourPackagePolicy::class);
        Gate::policy(TourDeparture::class, TourDeparturePolicy::class);
        Gate::policy(TourBooking::class, TourBookingPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(CarHireBooking::class, CarHireBookingPolicy::class);
        Gate::policy(CarHireDocument::class, CarHireDocumentPolicy::class);
        Gate::policy(CarHireContract::class, CarHireContractPolicy::class);
        Gate::policy(Airport::class, AirportPolicy::class);
        Gate::policy(AirportTransferLocation::class, AirportTransferLocationPolicy::class);
        Gate::policy(AirportTransferRate::class, AirportTransferRatePolicy::class);
        Gate::policy(AirportTransferBooking::class, AirportTransferBookingPolicy::class);
        Gate::policy(FlightInquiry::class, FlightInquiryPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(VehicleImportOrder::class, VehicleImportOrderPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(QuotationRequest::class, QuotationRequestPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(VehicleListing::class, VehicleListingPolicy::class);
        Gate::policy(VehicleSalesEnquiry::class, VehicleSalesEnquiryPolicy::class);
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(PropertyBooking::class, PropertyBookingPolicy::class);
        Gate::policy(VehicleLeaseApplication::class, VehicleLeaseApplicationPolicy::class);
        Gate::policy(VehicleLease::class, VehicleLeasePolicy::class);
        Gate::policy(VehicleLeasePayout::class, VehicleLeasePayoutPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);
        Gate::policy(PayrollLine::class, PayrollLinePolicy::class);
        Gate::policy(CorporateAccount::class, CorporateAccountPolicy::class);
        Gate::policy(GroupBooking::class, GroupBookingPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);

        RateLimiter::for('tour-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('tours.mail.max_per_minute', 8),
        )->by('tour-notification-mail'));

        RateLimiter::for('car-hire-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('car_hire.mail.max_per_minute', 8),
        )->by('car-hire-notification-mail'));

        RateLimiter::for('airport-transfer-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('airport_transfers.mail.max_per_minute', 8),
        )->by('airport-transfer-notification-mail'));

        RateLimiter::for('loyalty-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('loyalty.mail.max_per_minute', 8),
        )->by('loyalty-notification-mail'));

        RateLimiter::for('vehicle-import-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('vehicle_imports.mail.max_per_minute', 8),
        )->by('vehicle-import-notification-mail'));

        RateLimiter::for('flight-inquiry-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('flight_inquiries.mail.max_per_minute', 8),
        )->by('flight-inquiry-notification-mail'));

        RateLimiter::for('review-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('reviews.mail.max_per_minute', 8),
        )->by('review-notification-mail'));

        RateLimiter::for('billing-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('billing.mail.max_per_minute', 8),
        )->by('billing-notification-mail'));

        RateLimiter::for('fleet-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('fleet.mail.max_per_minute', 8),
        )->by('fleet-notification-mail'));

        RateLimiter::for('sales-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('sales.mail.max_per_minute', 8),
        )->by('sales-notification-mail'));

        RateLimiter::for('accommodation-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('accommodation.mail.max_per_minute', 8),
        )->by('accommodation-notification-mail'));

        RateLimiter::for('leasing-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('leasing.mail.max_per_minute', 8),
        )->by('leasing-notification-mail'));

        RateLimiter::for('finance-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('payroll.mail.max_per_minute', 8),
        )->by('finance-notification-mail'));

        RateLimiter::for('corporate-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('corporate.mail.max_per_minute', 8),
        )->by('corporate-notification-mail'));

        RateLimiter::for('messaging-notification-mail', static fn (): Limit => Limit::perMinute(
            (int) config('messaging.mail.max_per_minute', 8),
        )->by('messaging-notification-mail'));

        Route::bind('customerTourBooking', function (string $reference): TourBooking {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return TourBooking::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('customerCarHireBooking', function (string $reference): CarHireBooking {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return CarHireBooking::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('customerAirportTransferBooking', function (string $reference): AirportTransferBooking {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return AirportTransferBooking::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('ownerLease', function (string $reference): VehicleLease {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return VehicleLease::query()
                ->forOwner($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('customerPropertyBooking', function (string $reference): PropertyBooking {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return PropertyBooking::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('customerReview', function (string $reference): Review {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return Review::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('customerVehicleImport', function (string $reference): VehicleImportOrder {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return VehicleImportOrder::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('customerFlightInquiry', function (string $reference): FlightInquiry {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return FlightInquiry::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        Route::bind('customerQuotationRequest', function (string $reference): QuotationRequest {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return QuotationRequest::query()
                ->forCustomer($user)
                ->where('reference', $reference)
                ->firstOrFail();
        });

        // Scoped *and* filtered to customer-visible statuses, so a draft never
        // resolves in the portal even for the customer it is addressed to.
        Route::bind('customerQuotation', function (string $number): Quotation {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return Quotation::query()
                ->forCustomer($user)
                ->visibleToCustomer()
                ->where('number', $number)
                ->firstOrFail();
        });

        Route::bind('customerInvoice', function (string $number): Invoice {
            $user = request()->user();
            abort_unless($user instanceof User, 404);

            return Invoice::query()
                ->forCustomer($user)
                ->visibleToCustomer()
                ->where('number', $number)
                ->firstOrFail();
        });
    }
}
