<?php

namespace App\Support;

final readonly class MailDeliveryResult
{
    public function __construct(
        public bool $success,
        public bool $wasQueued,
    ) {}
}
