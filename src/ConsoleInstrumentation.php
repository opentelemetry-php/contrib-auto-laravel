<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Laravel;

use Illuminate\Console\Command;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class ConsoleInstrumentation
{
    public static function register(CachedInstrumentation $instrumentation): void
    {
        if(env('APP_LONG_RUNNING', false)) {
            return;
        }

        hook(
            Command::class,
            'execute',
            pre: static function (Command $command, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('Command %s', $command->getName() ?: 'unknown'))
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));

                return $params;
            },
            post: static function (Command $command, array $params, ?int $exitCode, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());
                $span->addEvent('command finished', [
                    'exit-code' => $exitCode,
                ]);

                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            }
        );
    }
}
