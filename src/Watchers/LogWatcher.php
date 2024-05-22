<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;

class LogWatcher extends Watcher
{
    private const DEBUG = 1;
    private const  INFO = 2;
    private const  NOTICE = 3;
    private const  WARNING = 4;
    private const  ERROR = 5;
    private const  CRITICAL = 6;
    private const  ALERT = 7;
    private const EMERGENCY = 8;

    private int $minLogLevel;

    /** @psalm-suppress UndefinedInterfaceMethod */
    public function register(Application $app): void
    {
        /** @phan-suppress-next-line PhanTypeArraySuspicious */
        $app['events']->listen(MessageLogged::class, [$this, 'recordLog']);

        $this->minLogLevel = self::INFO;
        $levelStr = strtoupper(env("OTEL_PHP_LOG_LEVEL", "INFO"));
        if (defined(self::class.'::'.$levelStr)) {
            $this->minLogLevel = constant(self::class . '::' . $levelStr);
        }
    }

    /**
     * Record a log.
     */
    public function recordLog(MessageLogged $log): void
    {
        if (
            defined(self::class.'::'.strtoupper($log->level)) &&
            constant(self::class . '::' . strtoupper($log->level)) < $this->minLogLevel
        ) {
            return;
        }

        $attributes = [
            'level' => $log->level,
        ];

        $attributes['context'] = json_encode(array_filter($log->context));

        $message = $log->message;

        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $span = Span::fromContext($scope->context());
        $span->addEvent($message, $attributes);
    }
}
