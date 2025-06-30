<?php

namespace Omniglies\LaravelRag\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RagAuth
{
    public function handle(Request $request, Closure $next, ...$guards)
    {
        $authMiddleware = config('rag.middleware.auth', 'auth');
        
        if ($authMiddleware === 'none' || !config('rag.ui.enabled', true)) {
            return $next($request);
        }

        if ($authMiddleware === 'auth') {
            if (!Auth::check()) {
                if ($request->expectsJson()) {
                    return response()->json(['error' => 'Unauthorized'], 401);
                }
                return redirect()->guest('/login');
            }
        }

        return $next($request);
    }
}