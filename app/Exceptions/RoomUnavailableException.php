<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RoomUnavailableException extends HttpException
{
    public function __construct(
        public readonly string $roomType,
        public readonly string $night,
    ) {
        parent::__construct(409, "{$roomType} is unavailable on {$night}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 409);
    }
}
