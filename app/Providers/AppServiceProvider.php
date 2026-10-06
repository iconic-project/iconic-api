<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ConfigKind;
use App\Enums\Permission;
use App\Events\AgencyApproved;
use App\Events\AvailabilityChanged;
use App\Events\BookingChargesChanged;
use App\Events\BookingCreated;
use App\Events\BookingOverdueFlagged;
use App\Events\BookingStatusChanged;
use App\Events\CharterEnquiryReceived;
use App\Events\ConfigPublished;
use App\Events\DealMarkedLost;
use App\Events\DeliveryOutcomeRecorded;
use App\Events\HoldExpired;
use App\Events\PaymentAwaitingWire;
use App\Events\PaymentSettled;
use App\Events\RefundRequested;
use App\Listeners\BumpEngineFeedVersion;
use App\Listeners\ClearCurrentConfigCache;
use App\Listeners\ExpireWebCheckoutSession;
use App\Listeners\MarkRequestHoldExpired;
use App\Listeners\OfferWaitlistRooms;
use App\Listeners\OpenDealOnBookingCreated;
use App\Listeners\OpenDealOnCharterEnquiryReceived;
use App\Listeners\RaiseAlertsOnBookingCreated;
use App\Listeners\RaiseAlertsOnBookingOverdueFlagged;
use App\Listeners\RaiseAlertsOnBookingStatusChanged;
use App\Listeners\RaiseAlertsOnDeliveryOutcome;
use App\Listeners\RaiseAlertsOnPaymentAwaitingWire;
use App\Listeners\RaiseAlertsOnPaymentSettled;
use App\Listeners\RaiseTasksOnBookingCreated;
use App\Listeners\RaiseTasksOnBookingStatusChanged;
use App\Listeners\RaiseTasksOnCharterEnquiry;
use App\Listeners\RaiseTasksOnPaymentAwaitingWire;
use App\Listeners\RaiseTasksOnRefundRequested;
use App\Listeners\SendOnBookingChargesChanged;
use App\Listeners\SendOnBookingStatusChanged;
use App\Listeners\SendOnPaymentSettled;
use App\Listeners\SyncJourneys;
use App\Listeners\SyncJourneysOnHoldExpired;
use App\Models\Agency;
use App\Models\Alert;
use App\Models\AutomationSetting;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\BusinessRuleVersion;
use App\Models\Campaign;
use App\Models\CharterEnquiry;
use App\Models\CheckoutSession;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\ContactActivity;
use App\Models\ContactAlias;
use App\Models\ContactMerge;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Departure;
use App\Models\Document;
use App\Models\EngineSettingsVersion;
use App\Models\ExtraVersion;
use App\Models\Group;
use App\Models\Guest;
use App\Models\InternalBlock;
use App\Models\Itinerary;
use App\Models\Journey;
use App\Models\JourneyEnrolment;
use App\Models\Manifest;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Property;
use App\Models\RateVersion;
use App\Models\RefundRequest;
use App\Models\ReportRun;
use App\Models\ReportSubscription;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SalesMaterial;
use App\Models\Segment;
use App\Models\SubjectRequest;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Policies\SyncPolicy;
use App\Services\Config\ConfigRegistry;
use App\Services\Config\CurrentConfig;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeGateway;
use App\Services\Stripe\StripeSdkGateway;
use App\Support\Config\Documents\BusinessRulesDocument;
use App\Support\Config\Documents\EngineSettingsDocument;
use App\Support\Config\Documents\ExtrasDocument;
use App\Support\Config\Documents\RatesDocument;
use App\Support\Crm\CrmSync;
use App\Support\Iso;
use App\Support\Mail\GraphMailbox;
use App\Support\Mail\MailboxReader;
use App\Support\Mail\MailpitMailbox;
use App\Support\Schedule\IconicSchedule;
use App\Support\Stripe\StripeGatewayBinding;
use DateTimeInterface;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Telescope\TelescopeServiceProvider as TelescopePackageServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentConfig::class);
        $this->app->bind(MailboxReader::class, function (Application $app): MailboxReader {
            if (config('iconic.inbox.driver') === 'graph') {
                return $app->make(GraphMailbox::class);
            }

            return $app->make(MailpitMailbox::class);
        });
        $this->app->singleton(ConfigRegistry::class);

        $this->app->singleton(FakeStripeGateway::class);
        $this->app->singleton(StripeSdkGateway::class);
        $this->app->singleton(StripeGateway::class, function (Application $app): StripeGateway {
            if (StripeGatewayBinding::usesFake($app)) {
                $fake = $app->make(FakeStripeGateway::class);
                if ($app->environment('local')) {
                    $fake->includeFileFixture = true;
                }

                return $fake;
            }

            return $app->make(StripeSdkGateway::class);
        });

        if ($this->app->environment('local') && class_exists(TelescopePackageServiceProvider::class)) {
            $this->app->register(TelescopePackageServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('engine', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('engine-promo', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('engine-checkout', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('engine-waitlist', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('engine-charter', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('engine-complete', function (Request $request): array {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(20)->by('ip:'.($request->ip() ?? 'unknown')),
                Limit::perMinute(10)->by('token:'.hash('sha256', $token)),
            ];
        });

        RateLimiter::for('engine-events', function (Request $request): array {
            $session = (string) $request->input('session_id');

            return [
                Limit::perMinute(30)->by('ip:'.($request->ip() ?? 'unknown')),
                Limit::perMinute(20)->by('session:'.$session),
            ];
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.($request->ip() ?? 'unknown'));
        });

        RateLimiter::for('auth-email', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('stripe-webhook', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->ip() ?? 'stripe');
        });

        Password::defaults(function (): Password {
            $rule = Password::min(8);

            return App::isProduction() ? $rule->uncompromised() : $rule;
        });

        ResetPassword::createUrlUsing(function (User $notifiable, string $token): string {
            return rtrim((string) config('iconic.panel_url'), '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        Blueprint::macro('auditColumns', function (): void {
            /** @var Blueprint $this */
            $this->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $this->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'rate_version' => RateVersion::class,
            'business_rule_version' => BusinessRuleVersion::class,
            'engine_settings_version' => EngineSettingsVersion::class,
            'extra_version' => ExtraVersion::class,
            'itinerary' => Itinerary::class,
            'departure' => Departure::class,
            'property' => Property::class,
            'room' => Room::class,
            'room_type' => RoomType::class,
            'internal_block' => InternalBlock::class,
            'contact' => Contact::class,
            'deal' => Deal::class,
            'crm_task' => CrmTask::class,
            'alert' => Alert::class,
            'campaign' => Campaign::class,
            'subject_request' => SubjectRequest::class,
            'contact_activity' => ContactActivity::class,
            'contact_merge' => ContactMerge::class,
            'contact_alias' => ContactAlias::class,
            'group' => Group::class,
            'agency' => Agency::class,
            'offer' => Offer::class,
            'booking' => Booking::class,
            'checkout_session' => CheckoutSession::class,
            'charter_enquiry' => CharterEnquiry::class,
            'document' => Document::class,
            'manifest' => Manifest::class,
            'report_run' => ReportRun::class,
            'report_subscription' => ReportSubscription::class,
            'sales_material' => SalesMaterial::class,
            'segment' => Segment::class,
            'journey' => Journey::class,
            'message_template' => MessageTemplate::class,
            'journey_enrolment' => JourneyEnrolment::class,
            'automation_setting' => AutomationSetting::class,
            'guest' => Guest::class,
            'consent' => Consent::class,
            'conversation' => Conversation::class,
            'message' => Message::class,
            'booking_request' => BookingRequest::class,
            'waitlist_entry' => WaitlistEntry::class,
            'payment' => Payment::class,
            'payment_link' => PaymentLink::class,
            'refund_request' => RefundRequest::class,
        ]);

        $this->app->make(ConfigRegistry::class)->register(
            ConfigKind::Rates,
            RateVersion::class,
            RatesDocument::class,
            RatesDocument::initial(),
        );

        $this->app->make(ConfigRegistry::class)->register(
            ConfigKind::EngineSettings,
            EngineSettingsVersion::class,
            EngineSettingsDocument::class,
            EngineSettingsDocument::initial(),
        );

        $this->app->make(ConfigRegistry::class)->register(
            ConfigKind::BusinessRules,
            BusinessRuleVersion::class,
            BusinessRulesDocument::class,
            BusinessRulesDocument::initial(),
        );

        $this->app->make(ConfigRegistry::class)->register(
            ConfigKind::Extras,
            ExtraVersion::class,
            ExtrasDocument::class,
            ExtrasDocument::initial(),
        );

        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }

        Gate::policy(CrmSync::class, SyncPolicy::class);

        Date::serializeUsing(fn (DateTimeInterface $date): string => Iso::utc($date));

        Event::listen(ConfigPublished::class, ClearCurrentConfigCache::class);
        Event::listen(ConfigPublished::class, BumpEngineFeedVersion::class);
        Event::listen(AvailabilityChanged::class, BumpEngineFeedVersion::class);
        Event::listen(AvailabilityChanged::class, OfferWaitlistRooms::class);
        Event::listen(HoldExpired::class, MarkRequestHoldExpired::class);
        Event::listen(HoldExpired::class, SyncJourneysOnHoldExpired::class);
        Event::listen(HoldExpired::class, OfferWaitlistRooms::class);
        Event::listen(HoldExpired::class, ExpireWebCheckoutSession::class);
        Event::listen(BookingStatusChanged::class, SendOnBookingStatusChanged::class);
        Event::listen(BookingStatusChanged::class, OfferWaitlistRooms::class);
        Event::listen(BookingCreated::class, OpenDealOnBookingCreated::class);
        Event::listen(BookingCreated::class, RaiseTasksOnBookingCreated::class);
        Event::listen(BookingCreated::class, RaiseAlertsOnBookingCreated::class);
        Event::listen(BookingCreated::class, SyncJourneys::class);
        Event::listen(CharterEnquiryReceived::class, OpenDealOnCharterEnquiryReceived::class);
        Event::listen(CharterEnquiryReceived::class, RaiseTasksOnCharterEnquiry::class);
        Event::listen(BookingStatusChanged::class, RaiseTasksOnBookingStatusChanged::class);
        Event::listen(BookingStatusChanged::class, RaiseAlertsOnBookingStatusChanged::class);
        Event::listen(BookingStatusChanged::class, SyncJourneys::class);
        Event::listen(BookingOverdueFlagged::class, RaiseAlertsOnBookingOverdueFlagged::class);
        Event::listen(RefundRequested::class, RaiseTasksOnRefundRequested::class);
        Event::listen(PaymentAwaitingWire::class, RaiseTasksOnPaymentAwaitingWire::class);
        Event::listen(PaymentAwaitingWire::class, RaiseAlertsOnPaymentAwaitingWire::class);
        Event::listen(PaymentSettled::class, SendOnPaymentSettled::class);
        Event::listen(PaymentSettled::class, RaiseAlertsOnPaymentSettled::class);
        Event::listen(PaymentSettled::class, SyncJourneys::class);
        Event::listen(AgencyApproved::class, SyncJourneys::class);
        Event::listen(DealMarkedLost::class, SyncJourneys::class);
        Event::listen(DeliveryOutcomeRecorded::class, RaiseAlertsOnDeliveryOutcome::class);
        Event::listen(BookingChargesChanged::class, SendOnBookingChargesChanged::class);

        $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
            IconicSchedule::register($schedule);
        });

        if ($this->app->resolved(Schedule::class)) {
            IconicSchedule::register($this->app->make(Schedule::class));
        }

        if ($this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(base_path('tests/database/migrations'));
        }
    }
}
