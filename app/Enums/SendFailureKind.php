<?php

namespace App\Enums;

enum SendFailureKind
{
    /** Nothing was accepted and another attempt may succeed. */
    case Transient;

    /** The provider rejected the message; retrying unchanged will fail again. */
    case Permanent;

    /** The connection broke after the message was handed over, so it may already be on its way. */
    case Ambiguous;
}
