<?php

namespace App\Http\Middleware;

use App\Exceptions\UnauthenticatedDeviceException;
use App\Services\DeviceAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceAuth
{
    public function __construct(
        private readonly DeviceAuthService $deviceAuth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $this->deviceAuth->current();
        } catch (UnauthenticatedDeviceException) {
            $user = $this->deviceAuth->getOrCreate();
        }

        $user->touchLastSeen();
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
