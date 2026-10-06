<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rms;

use App\Actions\Manifests\RecordManifestDownload;
use App\Enums\ManifestFormat;
use App\Enums\ManifestKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rms\ManifestIndexRequest;
use App\Http\Resources\Rms\ManifestDepartureResource;
use App\Http\Resources\Rms\ManifestVersionResource;
use App\Models\Departure;
use App\Models\Manifest;
use App\Models\User;
use App\Support\Manifests\ManifestIndex;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ManifestController extends Controller
{
    public function index(ManifestIndexRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Manifest::class);

        $from = $request->validated('from');
        $to = $request->validated('to');

        return ManifestDepartureResource::collection(
            ManifestIndex::between(
                is_string($from) ? $from : null,
                is_string($to) ? $to : null,
            ),
        );
    }

    public function versions(Departure $departure): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Manifest::class);

        $versions = Manifest::query()
            ->with('generatedBy')
            ->where('departure_id', $departure->id)
            ->orderBy('kind')
            ->orderBy('version')
            ->get();

        return ManifestVersionResource::collection($versions);
    }

    public function store(Departure $departure, string $kind): never
    {
        if (! $departure->exists || ManifestKind::tryFrom($kind) === null) {
            abort(404);
        }

        abort(410, 'Manifests are retired.');
    }

    public function file(
        Departure $departure,
        Manifest $manifest,
        string $format,
        RecordManifestDownload $downloads,
    ): StreamedResponse {
        $this->authorize('download', $manifest);

        abort_unless($manifest->departure_id === $departure->id, 404);

        $manifestFormat = ManifestFormat::tryFrom($format) ?? abort(404);

        if ($manifest->kind === ManifestKind::Captain && $manifestFormat !== ManifestFormat::Pdf) {
            abort(404);
        }

        abort_if($manifest->purged_at !== null, 404);

        $path = $manifest->pathFor($manifestFormat);

        abort_if($path === null || ! Storage::disk('manifests')->exists($path), 404);

        $downloads->handle($manifest, $manifestFormat, $this->actor());

        $filename = $departure->reference.'-'.$manifest->kind->value.'-v'.$manifest->version.'.'.$manifestFormat->value;

        return Storage::disk('manifests')->download($path, $filename, [
            'Content-Type' => $manifestFormat->contentType(),
        ]);
    }

    private function actor(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
