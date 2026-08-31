<?php

namespace App\Providers;

use App\Services\Messaging\MessagingTransportRegistry;
use App\Services\Messaging\Transports\LogTransport;
use App\Services\Messaging\Transports\WhatsAppCloudTransport;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the outbound WhatsApp transport.
 *
 * The real adapter is registered only when its credentials are present, so a
 * deployment without them falls back to the log transport — which records that
 * nothing was sent — instead of failing at the moment a member of staff tries
 * to answer a customer. The whole test suite therefore runs on the log
 * transport and never needs a WhatsApp Business account.
 */
class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessagingTransportRegistry::class, static function (): MessagingTransportRegistry {
            $registry = new MessagingTransportRegistry;

            $cloud = new WhatsAppCloudTransport;

            $registry->register($cloud->isConfigured() ? $cloud : new LogTransport);

            return $registry;
        });
    }
}
