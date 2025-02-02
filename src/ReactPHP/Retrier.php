<?php

declare(strict_types=1);

namespace App\ReactPHP;

use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Code taken from: https://github.com/rxak-php/ReactPHP-Retrier
 */
class Retrier
{
    public static function attempt(int $attempts, callable $action): PromiseInterface
    {
        return new Promise(static function (callable $resolve, callable $reject) use ($attempts, $action) {
            $exceptions = [];
            $retries = 0;

            $executeAction = static function (
                callable $action
            ) use (
                $attempts,
                &$retries,
                $resolve,
                $reject,
                &$executeAction,
                &$exceptions
            ) {
                $action($retries)
                    ->then($resolve)
                    ->catch(static function(\Throwable $e) use ($attempts, $action, &$retries, $executeAction, &$exceptions, $reject) {
                        $exceptions[] = $e;
                        $retries++;

                        if ($retries >= $attempts) {
                            $reject(new TooManyRetriesException(
                                sprintf('Max attempts of %d reached', $retries),
                                exceptions: $exceptions,
                            ));
                        } else {
                            $executeAction($action);
                        }
                    });
            };

            $executeAction($action);
        });
    }

    public function retry(int $attempts, callable $action): PromiseInterface
    {
        return self::attempt($attempts, $action);
    }
}
