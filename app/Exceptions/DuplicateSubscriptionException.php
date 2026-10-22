<?php

namespace App\Exceptions;

class DuplicateSubscriptionException extends \RuntimeException
{
    public function __construct(public readonly string $currentSubscriptionId, public readonly string $duplicateSubscriptionId)
    {
        parent::__construct("Duplicate live subscription {$duplicateSubscriptionId}; user already has {$currentSubscriptionId}. Cancel and refund the duplicate in Paddle.");
    }
}
