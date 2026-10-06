<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Actions\Contacts\MergeContacts;
use App\Actions\Contacts\UpdateContact;
use App\Enums\ContactConsentFilter;
use App\Enums\ContactLifecycle;
use App\Enums\ContactType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\IndexContactsRequest;
use App\Http\Requests\Crm\MergeContactRequest;
use App\Http\Requests\Crm\UpdateContactRequest;
use App\Http\Resources\Crm\ContactDuplicateResource;
use App\Http\Resources\Crm\ContactMergeResultResource;
use App\Http\Resources\Crm\ContactResource;
use App\Models\Contact;
use App\Models\User;
use App\Support\Crm\ContactDerived;
use App\Support\Crm\DuplicateContacts;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ContactController extends Controller
{
    #[DocumentedResponse(
        status: 200,
        type: 'array{data: list<App\\Http\\Resources\\Crm\\ContactResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string|null, per_page: int, to: int|null, total: int, filters: array{type: list<array{value: string, label: string}>, lifecycle: list<array{value: string, label: string}>}}}',
    )]
    public function index(IndexContactsRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        $validated = $request->validated();
        $search = trim((string) ($validated['q'] ?? ''));

        $contacts = Contact::query()
            ->notMerged()
            ->withDerived()
            ->when(
                isset($validated['type']),
                fn (Builder $query) => $query->where('contacts.type', $validated['type']),
            )
            ->when(
                isset($validated['lifecycle']),
                fn (Builder $query) => $query->whereRaw('('.ContactDerived::lifecycleSql().') = ?', [$validated['lifecycle']]),
            )
            ->when(
                isset($validated['main_channel']),
                fn (Builder $query) => $query->whereRaw('('.ContactDerived::firstBookingColumnSql('main_channel').') = ?', [$validated['main_channel']]),
            )
            ->when(
                isset($validated['channel_of_origin']),
                fn (Builder $query) => $query->whereRaw('('.ContactDerived::firstBookingColumnSql('channel_of_origin').') = ?', [$validated['channel_of_origin']]),
            )
            ->when(
                isset($validated['consent']),
                function (Builder $query) use ($validated): void {
                    $wanted = $validated['consent'] === ContactConsentFilter::Marketing->value ? 1 : 0;
                    $query->whereRaw('('.ContactDerived::marketingConsentSql().') = ?', [$wanted]);
                },
            )
            ->when(
                $search !== '',
                function (Builder $query) use ($search): void {
                    $like = '%'.$search.'%';
                    $query->where(function (Builder $inner) use ($like): void {
                        $inner->where('contacts.name', 'like', $like)
                            ->orWhere('contacts.email', 'like', $like)
                            ->orWhere('contacts.phone', 'like', $like);
                    });
                },
            )
            ->orderBy('contacts.name')
            ->orderBy('contacts.id')
            ->paginate($request->integer('per_page', 50));

        return ContactResource::collection($contacts)->additional([
            'meta' => [
                'filters' => [
                    'type' => ContactType::options(),
                    'lifecycle' => ContactLifecycle::options(),
                ],
            ],
        ]);
    }

    public function show(Contact $contact): ContactResource
    {
        $this->authorize('view', $contact);

        $loaded = Contact::query()
            ->withDerived()
            ->with(['bookings' => function ($query): void {
                $query->withChargesSummary()
                    ->with(['roomType', 'owner'])
                    ->orderBy('id');
            }])
            ->whereKey($contact->id)
            ->firstOrFail();

        $loaded->resolvedFromAliasId = $contact->resolvedFromAliasId;
        $loaded->resolvedMergeId = $contact->resolvedMergeId;

        return new ContactResource($loaded);
    }

    public function duplicates(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Contact::class);

        return ContactDuplicateResource::collection(DuplicateContacts::pairs());
    }

    public function merge(MergeContactRequest $request, Contact $contact, MergeContacts $action): ContactMergeResultResource
    {
        $this->authorize('merge', $contact);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $other = Contact::query()->findOrFail((int) $request->validated('contact_id'));
        $result = $action->handle($contact, $other, (string) $request->validated('reason'), $actor);
        $profile = $this->show($result['survivor']);

        return new ContactMergeResultResource([
            'swapped' => $result['swapped'],
            'merge' => $result['merge'],
            'contact' => $profile->resource,
        ]);
    }

    #[DocumentedResponse(
        status: 409,
        description: 'Email belongs to another contact',
        type: 'array{message: string, conflicting_contact: array{id: int, name: string}}',
    )]
    public function update(UpdateContactRequest $request, Contact $contact, UpdateContact $action): ContactResource
    {
        $this->authorize('update', $contact);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $action->handle($contact, $request->validated(), $actor);

        return $this->show($contact->fresh() ?? $contact);
    }
}
