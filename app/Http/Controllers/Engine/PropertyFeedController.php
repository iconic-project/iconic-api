<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Controller;
use App\Http\Resources\Engine\EnginePropertyResource;
use App\Services\Engine\EnginePropertyFeed;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PropertyFeedController extends Controller
{
    #[DocumentedResponse(status: 200, type: EnginePropertyResource::class)]
    #[DocumentedResponse(status: 304, description: 'Not modified')]
    public function __invoke(Request $request, EnginePropertyFeed $feed): Response
    {
        $payload = $feed->payload();
        $response = (new EnginePropertyResource($payload))
            ->toResponse($request)
            ->header('Cache-Control', 'public, max-age=15, stale-while-revalidate=15')
            ->setEtag($feed->etag($payload));

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
