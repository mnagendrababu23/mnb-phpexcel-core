<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Events;

use Throwable;

/**
 * Tiny framework-neutral event dispatcher. Listeners are process-local and optional.
 */
final class EventDispatcher
{
    /** @var array<string,list<callable(array<string,mixed>):mixed>> */
    private static array $listeners = [];

    /** @param callable(array<string,mixed>):mixed $listener */
    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function dispatch(string $event, array $payload = []): array
    {
        $payload['event'] = $event;
        $payload['dispatched_at'] = $payload['dispatched_at'] ?? gmdate('c');

        foreach (self::$listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
        foreach (self::$listeners['*'] ?? [] as $listener) {
            $listener($payload);
        }

        return $payload;
    }

    public static function clear(?string $event = null): void
    {
        if ($event === null) {
            self::$listeners = [];
            return;
        }
        unset(self::$listeners[$event]);
    }

    /** @return array<string,int> */
    public static function summary(): array
    {
        $summary = [];
        foreach (self::$listeners as $event => $listeners) {
            $summary[$event] = count($listeners);
        }
        return $summary;
    }

    /** Dispatch import failure without allowing listener failures to hide the real exception. */
    public static function safeDispatch(string $event, array $payload = []): void
    {
        try {
            self::dispatch($event, $payload);
        } catch (Throwable) {
            // Event hooks must never corrupt import/export control flow.
        }
    }
}
