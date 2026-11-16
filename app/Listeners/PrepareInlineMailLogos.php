<?php

namespace App\Listeners;

use App\Support\OwwaMailLogo;
use Illuminate\Mail\Events\MessageSending;

class PrepareInlineMailLogos
{
    public function handle(MessageSending $event): void
    {
        OwwaMailLogo::prepareForGmail($event->message);
    }
}
