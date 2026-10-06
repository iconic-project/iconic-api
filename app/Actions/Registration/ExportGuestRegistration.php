<?php

declare(strict_types=1);

namespace App\Actions\Registration;

use App\Actions\Action;
use App\Enums\Permission;
use App\Enums\RegistrationField;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Property;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Documents\PdfRenderer;
use App\Support\FrontDesk\FrontDeskLists;
use App\Support\History\History;
use App\Support\Registration\RegistrationSheet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ExportGuestRegistration extends Action
{
    public function __construct(
        private readonly CurrentConfig $config,
        private readonly FrontDeskLists $lists,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * @return array{body: string, filename: string, content_type: string}
     */
    public function handle(string $date, string $format, User $actor): array
    {
        $rules = $this->config->businessRules()->registration;

        if (! $rules->allowsFormat($format)) {
            throw ValidationException::withMessages([
                'format' => ['This format is not enabled for guest registration.'],
            ]);
        }

        $fields = $this->visibleFields($rules->fieldEnums(), $actor);
        $bookings = $this->lists->occupying($actor, $date);
        $sheet = RegistrationSheet::build($fields, $bookings);
        $upper = strtoupper($format);

        /** @var array{body: string, filename: string, content_type: string} $file */
        $file = $this->transaction(function () use ($date, $upper, $actor, $bookings, $fields, $sheet): array {
            $this->record($date, $upper, $actor, $bookings, $fields);

            if ($upper === 'PDF') {
                return [
                    'body' => $this->pdf->render($this->html($date, $sheet['headers'], $sheet['rows'])),
                    'filename' => 'registration-'.$date.'.pdf',
                    'content_type' => 'application/pdf',
                ];
            }

            return [
                'body' => $this->csv($sheet['headers'], $sheet['rows']),
                'filename' => 'registration-'.$date.'.csv',
                'content_type' => 'text/csv',
            ];
        });

        return $file;
    }

    /**
     * @param  list<RegistrationField>  $fields
     * @return list<RegistrationField>
     */
    private function visibleFields(array $fields, User $actor): array
    {
        if ($actor->hasPermission(Permission::GuestsViewSensitive)) {
            return $fields;
        }

        return array_values(array_filter(
            $fields,
            fn (RegistrationField $field): bool => ! $field->sensitive(),
        ));
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @param  list<RegistrationField>  $fields
     */
    private function record(string $date, string $format, User $actor, Collection $bookings, array $fields): void
    {
        $codes = array_map(
            fn (RegistrationField $field): string => $field->value,
            $fields,
        );
        $groups = $bookings->groupBy('property_id');

        if ($groups->isEmpty()) {
            $property = Property::query()->orderBy('id')->first();

            if ($property instanceof Property) {
                $this->entry($property, $date, $format, 0, 0, $codes, $actor);
            }

            return;
        }

        foreach ($groups as $propertyId => $group) {
            $property = Property::query()->find($propertyId);

            if (! $property instanceof Property) {
                continue;
            }

            $guests = 0;

            foreach ($group as $booking) {
                $guests += $booking->guests
                    ->filter(fn (Guest $guest): bool => $guest->first_name !== '' || $guest->last_name !== '')
                    ->count();
            }

            $this->entry($property, $date, $format, $group->count(), $guests, $codes, $actor);
        }
    }

    /**
     * @param  list<string>  $fields
     */
    private function entry(
        Property $property,
        string $date,
        string $format,
        int $bookings,
        int $guests,
        array $fields,
        User $actor,
    ): void {
        History::record(
            $property,
            'registration.exported',
            null,
            [
                'date' => $date,
                'format' => $format,
                'booking_count' => $bookings,
                'guest_count' => $guests,
                'fields' => $fields,
            ],
            null,
            $actor,
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Could not build the registration CSV.');
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new RuntimeException('Could not build the registration CSV.');
        }

        return $csv;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function html(string $date, array $headers, array $rows): string
    {
        $head = '';

        foreach ($headers as $header) {
            $head .= '<th>'.e($header).'</th>';
        }

        $body = '';

        foreach ($rows as $row) {
            $body .= '<tr>';

            foreach ($row as $cell) {
                $body .= '<td>'.e($cell).'</td>';
            }

            $body .= '</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            .'body{font-family:Archivo,sans-serif;font-size:11px}'
            .'table{border-collapse:collapse;width:100%}'
            .'th,td{border:1px solid #ccc;padding:4px;text-align:left}'
            .'</style></head><body><h1>Guest registration '.e($date).'</h1>'
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></body></html>';
    }
}
