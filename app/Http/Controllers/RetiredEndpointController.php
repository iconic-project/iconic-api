<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class RetiredEndpointController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'This endpoint has been removed.',
        ], 410);
    }
}
