<?php

declare(strict_types=1);

namespace App\ReactPHP;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base retry code taken from: https://github.com/rxak-php/ReactPHP-Retrier
 */
class Retrier
{
    public static function attempt(
        LoopInterface $loop,
        OutputInterface $output,
        int $attempts,
        callable $action
    ): PromiseInterface
    {
        return new Promise(static function (callable $resolve, callable $reject) use ($output, $loop, $attempts, $action) {
            $exceptions = [];
            $retries = 0;

            $executeAction = static function (
                callable $action
            ) use (
                $output,
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
                        $output,
                        $loop,
                        $attempts, $action, &$retries, $executeAction, &$exceptions, $reject
                    ) {
                        $exceptions[] = $e;
                        $retries++;

                        if ($retries >= $attempts) {
                            $output->writeln('Max attempts reached');

                            $reject(new TooManyRetriesException(
                                sprintf('Max attempts of %d reached', $retries),
                                exceptions: $exceptions,
                            ));
                        } else {
                            // TODO: Configurable delay
                            $delay = 5 * $retries;

                            $output->writeln(sprintf('Retrying in %d seconds', $delay));

                            $loop->addTimer($delay, function() use ($action, $executeAction) {
                                $executeAction($action);
                            });
                        }
                    });
            };

            $executeAction($action);
        });
    }
}
