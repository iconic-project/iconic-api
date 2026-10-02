<?php

declare(strict_types=1);

namespace App\Support\Manifests;

use App\Enums\ManifestKind;
use App\Models\Departure;
use App\Services\Documents\DocumentFonts;
use App\Services\Documents\PdfRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

final class ManifestFiles
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * @param  Collection<int, ManifestPassenger>  $passengers
     * @return array{pdf: string, csv: string|null, xlsx: string|null}
     */
    public function store(
        Departure $departure,
        ManifestKind $kind,
        int $version,
        Collection $passengers,
        ManifestDue $due,
    ): array {
        $departure->loadMissing(['property', 'itinerary']);
        $stem = $departure->id.'/'.$kind->value.'/v'.$version;
        $disk = Storage::disk('manifests');
        $written = [];

        try {
            $html = $this->html($departure, $kind, $passengers, $due);
            $pdfPath = $stem.'.pdf';
            $disk->put($pdfPath, $this->pdf->render(DocumentFonts::embed($html)));
            $written[] = $pdfPath;

            $csvPath = null;
            $xlsxPath = null;

            if ($kind === ManifestKind::Dpng) {
                $csvPath = $stem.'.csv';
                $disk->put($csvPath, $this->csv($passengers));
                $written[] = $csvPath;

                $xlsxPath = $stem.'.xlsx';
                $disk->put($xlsxPath, $this->xlsx($passengers));
                $written[] = $xlsxPath;
            }

            return [
                'pdf' => $pdfPath,
                'csv' => $csvPath,
                'xlsx' => $xlsxPath,
            ];
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                $disk->delete($path);
            }

            throw $e;
        }
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $passengers
     */
    public function html(Departure $departure, ManifestKind $kind, Collection $passengers, ManifestDue $due): string
    {
        $departure->loadMissing(['property', 'itinerary']);
        $view = $kind === ManifestKind::Dpng ? 'manifests.dpng' : 'manifests.captain';
        $complete = $passengers->filter(fn (ManifestPassenger $passenger): bool => $passenger->complete())->count();

        return view($view, [
            'departure' => $departure,
            'passengers' => $passengers,
            'due' => $due,
            'complete' => $complete,
            'returnDate' => $departure->returnDate()->format('j M Y'),
            'departureDate' => $departure->date->format('j M Y'),
        ])->render();
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $passengers
     */
    public function csv(Collection $passengers): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not build the DPNG CSV.');
        }

        fputcsv($handle, ManifestPassenger::dpngHeaders());

        foreach ($passengers as $passenger) {
            fputcsv($handle, $passenger->dpngCells());
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new RuntimeException('Could not build the DPNG CSV.');
        }

        return $csv;
    }

    /**
     * @param  Collection<int, ManifestPassenger>  $passengers
     */
    public function xlsx(Collection $passengers): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'manifest');

        if ($tmp === false) {
            throw new RuntimeException('Could not build the DPNG spreadsheet.');
        }

        $writer = new Writer;
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues(ManifestPassenger::dpngHeaders()));

        foreach ($passengers as $passenger) {
            $writer->addRow(Row::fromValues($passenger->dpngCells()));
        }

        $writer->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);

        if ($bytes === false) {
            throw new RuntimeException('Could not build the DPNG spreadsheet.');
        }

        return $bytes;
    }
}
