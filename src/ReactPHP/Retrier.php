<?php

declare(strict_types=1);

namespace App\ReactPHP;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

/**
 * Base retry code taken from: https://github.com/rxak-php/ReactPHP-Retrier
 */
class Retrier
{
    public static function attempt(
        LoopInterface $loop,
        int $attempts,
        callable $action
    ): PromiseInterface
    {
        return new Promise(static function (callable $resolve, callable $reject) use ($loop, $attempts, $action) {
            $exceptions = [];
            $retries = 0;

            $executeAction = static function (
                callable $action
            ) use (
                $loop,
                $attempts,
                &$retries,
                $resolve,
                $reject,
                &$executeAction,
                &$exceptions
            ) {
                $action($retries)
                    ->then($resolve)
                    ->catch(static function(\Throwable $e) use (
                        $loop,
                        $attempts, $action, &$retries, $executeAction, &$exceptions, $reject
                    ) {
                        $exceptions[] = $e;
                        $retries++;

                        if ($retries >= $attempts) {
                            $reject(new TooManyRetriesException(
                                sprintf('Max attempts of %d reached', $retries),
                                exceptions: $exceptions,
                            ));
                        } else {
                            // TODO: Configurable delay
                            $delay = 5 * $retries;

                            $loop->addTimer($delay, function() use ($action, $executeAction) {
                                $executeAction($action);
                            });
                        }
                    });
            };

            $executeAction($action);
        });
    }

    public function retry(LoopInterface $loop, int $attempts, callable $action): PromiseInterface
    {
        return self::attempt($loop, $attempts, $action);
    }
}
