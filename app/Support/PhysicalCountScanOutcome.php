<?php

namespace App\Support;

enum PhysicalCountScanOutcome: string
{
    case Found = 'found';
    case Duplicate = 'duplicate';
    case Overage = 'overage';
    case NotFound = 'not_found';
}
