<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApiTokenAuthMiddleware;
use App\Http\Middleware\EmployeeMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trimStrings(except: ['passwordConfirmation']);
        $middleware->append(SecurityHeadersMiddleware::class);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'employee' => EmployeeMiddleware::class,
            'auth.api' => ApiTokenAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request, $exception) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (\Throwable $exception, \Illuminate\Http\Request $request) {
            if (!$request->is('api/*')) { return null; }
            if ($exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return $exception->getResponse();
            }
            if ($exception instanceof \App\Services\ApiException) {
                return \App\Services\ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
            }
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                return \App\Services\ApiResponse::error('VALIDATION_ERROR', '入力内容を確認してください。', 422,
                    collect($exception->errors())->flatMap(fn ($messages, $field) =>
                        array_map(fn ($message) => ['field' => $field, 'message' => $message], $messages)
                    )->values()->all());
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $message = match ($status) {
                401 => 'ログインの有効期限が切れました。再度ログインしてください。',
                403 => 'この操作の権限がありません。',
                404 => '対象が見つかりません。',
                429 => 'アクセスが集中しています。しばらくしてから再試行してください。',
                default => 'サーバーで処理できませんでした。更新操作の場合は履歴を確認してください。',
            };
            $response = \App\Services\ApiResponse::error('HTTP_'.$status, $message, $status);
            return $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $response->withHeaders($exception->getHeaders()) : $response;
        });
    })->create();
