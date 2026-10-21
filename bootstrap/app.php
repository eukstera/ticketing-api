<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $envelope = fn (string $message, int $status, ?array $errors = null, array $headers = []) => response()->json(
            array_filter([
                'success' => false,
                'message' => $message,
                'errors' => $errors,
            ], fn ($value) => $value !== null),
            $status,
            $headers
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope('Unauthenticated', 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage(), 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage() ?: 'This action is unauthorized.', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope('Resource not found.', 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($envelope) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $message = match (true) {
                $status === 404 => 'Resource not found.',
                $status === 429 => 'Too many requests. Please try again later.',
                $e->getMessage() !== '' => $e->getMessage(),
                default => Response::$statusTexts[$status] ?? 'Request failed.',
            };

            return $envelope($message, $status, null, $e->getHeaders());
        });
    })->create();
