<?php

namespace App\Exceptions;

class ProviderLimited extends \RuntimeException
{
    public function __construct(public int $retryAfter, string $message)
    {
        parent::__construct($message);
    }
}
