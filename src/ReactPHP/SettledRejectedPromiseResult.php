<?php

namespace App\ReactPHP;

class SettledRejectedPromiseResult implements SettledPromiseResult
{
    public function __construct(
        private \Throwable $reason
    ) {}

    public function getState(): string
    {
        return SettledPromiseResult::STATE_REJECTED;
    }

    public function getReason(): \Throwable
    {
        return $this->reason;
    }
}
