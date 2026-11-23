<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hanya meloloskan pengunjung yang sudah masuk dengan tanda tangan wallet. */
class RequireWallet
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('web3_address')) {
            return response()->json(['message' => 'Hubungkan wallet dulu untuk memakai Live Feed.'], 401);
        }

        return $next($request);
    }
}
