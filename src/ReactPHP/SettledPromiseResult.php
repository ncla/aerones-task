<?php

namespace App\ReactPHP;

interface SettledPromiseResult
{
    const STATE_FULFILLED = 'fulfilled';
    const STATE_REJECTED = 'rejected';

    function getState(): string;
}
