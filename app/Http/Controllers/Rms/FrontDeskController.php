<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Registration\ExportGuestRegistration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\ExportRegistrationRequest;
use App\Http\Requests\Rms\FrontDeskDateRequest;
use App\Http\Resources\Rms\FrontDeskListsResource;
use App\Models\Booking;
use App\Models\User;
use App\Support\FrontDesk\FrontDeskDay;
use App\Support\FrontDesk\FrontDeskLists;
use Symfony\Component\HttpFoundation\Response;

final class FrontDeskController extends Controller
{
    public function index(FrontDeskDateRequest $request, FrontDeskLists $lists): FrontDeskListsResource
    {
        $this->authorize('viewAny', Booking::class);

        $actor = $this->actor();
        $date = (string) $request->validated('date');
        $day = $lists->forDate($actor, $date);

        return new FrontDeskListsResource(new FrontDeskDay(
            $date,
            $day['arrivals'],
            $day['in_house'],
            $day['departures'],
        ));
    }

    public function registration(ExportRegistrationRequest $request, ExportGuestRegistration $export): Response
    {
        $this->authorize('viewAny', Booking::class);

        $file = $export->handle(
            (string) $request->validated('date'),
            (string) $request->validated('format'),
            $this->actor(),
        );

        return response($file['body'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    private function actor(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
