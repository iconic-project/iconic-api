<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\GuestExperience\RecordBriefPrinted;
use App\Actions\GuestExperience\RecordGuestPreferences;
use App\Enums\PreferenceSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\ArrivalRangeRequest;
use App\Http\Requests\Rms\ArrivalsBriefRequest;
use App\Http\Requests\Rms\UpdateGuestPreferencesRequest;
use App\Http\Resources\Rms\ArrivalGuestListResource;
use App\Http\Resources\Rms\GuestPreferencesResource;
use App\Http\Resources\Rms\PreferenceQuestionResource;
use App\Models\Guest;
use App\Models\GuestPreference;
use App\Models\User;
use App\Support\GuestExperience\ArrivalGuestList;
use App\Support\GuestExperience\ArrivalsBrief;
use App\Support\GuestExperience\PreferenceHistory;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class GuestExperienceController extends Controller
{
    public function index(ArrivalRangeRequest $request, ArrivalGuestList $experience): ArrivalGuestListResource
    {
        $this->authorize('viewAny', GuestPreference::class);

        return new ArrivalGuestListResource(
            $experience->forArrivals($request->from(), $request->to(), $this->sensitive()),
        );
    }

    #[DocumentedResponse(status: 200, type: PreferenceQuestionResource::class)]
    public function questions(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GuestPreference::class);

        return PreferenceQuestionResource::collection(PreferenceQuestionResource::questions());
    }

    public function preferences(Guest $guest): GuestPreferencesResource
    {
        $this->authorize('viewAny', GuestPreference::class);
        $this->authorize('view', $guest);

        return new GuestPreferencesResource(
            PreferenceHistory::forGuest($guest, $this->sensitive()),
        );
    }

    public function update(
        UpdateGuestPreferencesRequest $request,
        Guest $guest,
        RecordGuestPreferences $action,
    ): GuestPreferencesResource {
        $this->authorize('view', $guest);
        $actor = $request->user();
        assert($actor instanceof User);

        $action->handle(
            $guest,
            $request->answers(),
            PreferenceSource::Staff,
            $actor->can('viewSensitive', GuestPreference::class),
            $actor,
        );

        return new GuestPreferencesResource(
            PreferenceHistory::forGuest($guest, $this->sensitive()),
        );
    }

    public function arrivals(
        ArrivalsBriefRequest $request,
        ArrivalsBrief $brief,
        RecordBriefPrinted $printed,
    ): Response {
        $this->authorize('viewAny', GuestPreference::class);
        $actor = $request->user();
        assert($actor instanceof User);
        $date = $request->arrivalDate();
        $format = $request->briefFormat();
        $sensitive = $this->sensitive();
        $printed->handle($date, $actor, $format);

        if ($format === 'pdf') {
            return new Response($brief->pdf($date, $sensitive), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="arrivals-brief-'.$date.'.pdf"',
            ]);
        }

        return new Response($brief->html($date, $sensitive), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function sensitive(): bool
    {
        $actor = request()->user();

        return $actor instanceof User && $actor->can('viewSensitive', GuestPreference::class);
    }
}
