<?php

use App\Http\Middleware\DeviceAuth;
use App\Http\Middleware\RateLimitSafeChat;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifySafeChatCsrf;
use App\Services\CsrfService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->api(prepend: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'device.auth' => DeviceAuth::class,
            'safechat.csrf' => VerifySafeChatCsrf::class,
            'safechat.ratelimit' => RateLimitSafeChat::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $csrf = null;
                try {
                    $csrf = app(CsrfService::class)->token();
                } catch (Throwable) {
                    $csrf = null;
                }

                $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 500;
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $status = 422;
                    $message = $e->validator->errors()->first() ?: 'Validation failed';
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $status = $e->getStatusCode();
                    $message = match ($status) {
                        400 => 'درخواست نامعتبر است',
                        401, 403 => 'دسترسی غیرمجاز',
                        404 => 'یافت نشد',
                        405 => 'متد نامعتبر است',
                        419 => 'درخواست منقضی شده است',
                        429 => 'تعداد درخواست‌ها بیش از حد مجاز است',
                        default => 'درخواست ناموفق',
                    };

                    if ((bool) config('app.debug', false) && $e->getMessage() !== '') {
                        $message = $e->getMessage();
                    }
                } elseif ($e instanceof \App\Exceptions\UnauthenticatedDeviceException) {
                    $status = 401;
                    $message = 'دسترسی غیرمجاز';
                } else {
                    $message = 'خطای سرور';
                    if ($status < 400 || $status >= 600) {
                        $status = 500;
                    }

                    if ($status >= 500) {
                        Log::channel('safechat')->error('Unhandled exception', [
                            'exception' => $e::class,
                            'path' => $request->path(),
                            'method' => $request->method(),
                        ]);
                    }
                }

                $payload = ['error' => $message];
                if (is_string($csrf) && $csrf !== '') {
                    $payload['csrf'] = $csrf;
                }

                $response = response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
                if (is_string($csrf) && $csrf !== '' && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                    $response->header('X-CSRF-Token', $csrf);
                }

                return $response;
            }

            return null;
        });
    })->create();
