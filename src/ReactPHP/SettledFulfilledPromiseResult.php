<?php

namespace App\ReactPHP;

class SettledFulfilledPromiseResult implements SettledPromiseResult
{
    public function __construct(
        private mixed $value
    ) {}

    public function getState(): string
    {
        return SettledPromiseResult::STATE_FULFILLED;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
