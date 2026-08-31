<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\MessagingTransport;
use App\Services\Messaging\Transports\LogTransport;

/**
 * Resolves the transport for outbound WhatsApp.
 *
 * Registration happens in a service provider rather than at call sites, so a
 * test can swap in a fake and an unconfigured deployment degrades to the log
 * transport — visibly not sending — instead of throwing at the moment a member
 * of staff tries to answer a customer.
 */
class MessagingTransportRegistry
{
    private ?MessagingTransport $transport = null;

    public function register(MessagingTransport $transport): void
    {
        $this->transport = $transport;
    }

    public function resolve(): MessagingTransport
    {
        if ($this->transport !== null) {
            return $this->transport;
        }

        return $this->transport = new LogTransport;
    }

    /** Whether outbound WhatsApp actually leaves this deployment. */
    public function canSend(): bool
    {
        $transport = $this->resolve();

        return $transport->name() !== 'log' && $transport->isConfigured();
    }
}
