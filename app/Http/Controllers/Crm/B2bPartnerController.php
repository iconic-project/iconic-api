<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\B2bPartnerResource;
use App\Models\Agency;
use App\Models\Contact;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class B2bPartnerController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Crm\\B2bPartnerResource>}',
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        $agencies = Agency::query()
            ->with(['bookings.commissionPayout'])
            ->orderBy('name')
            ->get();

        return B2bPartnerResource::collection($agencies);
    }

    #[DocumentedResponse(status: 200, type: 'App\\Http\\Resources\\Crm\\B2bPartnerResource')]
    public function show(Agency $agency): B2bPartnerResource
    {
        $this->authorize('viewAny', Contact::class);

        $agency->load(['bookings.commissionPayout']);

        $resource = new B2bPartnerResource($agency);
        $resource->detailed = true;

        return $resource;
    }
}
