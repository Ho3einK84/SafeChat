<?php

namespace App\Http\Middleware;

use App\Services\CsrfService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySafeChatCsrf
{
    public function __construct(
        private readonly CsrfService $csrf,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $field = $this->csrf->fieldName();
        $token = $request->isJson()
            ? (string) $request->json($field, '')
            : (string) $request->request->get($field, '');
        if ($token === '') {
            $token = (string) $request->header('X-CSRF-Token', '');
        }

        if (! $this->csrf->verify($token)) {
            return response()->json([
                'error' => 'درخواست نامعتبر است (CSRF). صفحه را رفرش کنید.',
                'csrf' => $this->csrf->token(),
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        return $next($request);
    }
}
