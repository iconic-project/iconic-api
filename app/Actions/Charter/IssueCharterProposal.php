<?php

declare(strict_types=1);

namespace App\Actions\Charter;

use App\Actions\Action;
use App\Enums\BookingAccessTokenPurpose;
use App\Enums\CharterEnquiryStatus;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\DeliveryTriggeredBy;
use App\Enums\DocumentKind;
use App\Enums\ReferenceType;
use App\Exceptions\ConflictException;
use App\Mail\Charter\CharterProposalMail;
use App\Models\BookingAccessToken;
use App\Models\CharterEnquiry;
use App\Models\Delivery;
use App\Models\Document;
use App\Models\User;
use App\Services\Config\CurrentConfig;
use App\Services\Documents\DocumentView;
use App\Services\Documents\PdfRenderer;
use App\Services\Pricing\ReservationQuoter;
use App\Services\References\ReferenceService;
use App\Support\Automations\AutomationCatalogue;
use App\Support\Automations\AutomationGate;
use App\Support\BusinessHours;
use App\Support\BusinessTime;
use App\Support\Config\Documents\CancellationBand;
use App\Support\History\History;
use App\Support\Iso;
use App\Support\Payments\CancellationPenalty;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IssueCharterProposal extends Action
{
    public function __construct(
        private readonly ReservationQuoter $quoter,
        private readonly CurrentConfig $config,
        private readonly PdfRenderer $pdf,
        private readonly ReferenceService $references,
        private readonly AutomationGate $gate,
    ) {}

    public function handle(CharterEnquiry $enquiry, User $actor, ?string $reason = null): Document
    {
        $path = null;
        $disk = Storage::disk('documents');

        try {
            return $this->transaction(function () use ($enquiry, $actor, $reason, $disk, &$path): Document {
                $enquiry = CharterEnquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();
                $enquiry->load(['contact', 'departure.yacht']);

                if (! in_array($enquiry->status, [
                    CharterEnquiryStatus::New,
                    CharterEnquiryStatus::Contacted,
                    CharterEnquiryStatus::Quoted,
                ], true)) {
                    throw new ConflictException('A proposal cannot be issued from '.$enquiry->status->value.'.');
                }

                if ($enquiry->departure_id === null || $enquiry->departure === null) {
                    throw ValidationException::withMessages([
                        'departure_id' => ['A charter proposal needs a departure.'],
                    ]);
                }

                $previous = Document::query()
                    ->where('charter_enquiry_id', $enquiry->id)
                    ->where('kind', DocumentKind::CharterProposal)
                    ->orderByDesc('version')
                    ->first();
                $version = $previous instanceof Document ? $previous->version + 1 : 1;

                if ($version > 1 && ($reason === null || trim($reason) === '')) {
                    throw ValidationException::withMessages([
                        'reason' => ['A reason is required to re-issue a proposal.'],
                    ]);
                }

                $quote = $this->quoter->quote([
                    'departure_id' => $enquiry->departure_id,
                    'type' => 'CHARTER',
                    'back_to_back' => false,
                    'cabins' => [[
                        'adults' => $enquiry->guests,
                        'children' => 0,
                    ]],
                ], $enquiry->departure);

                if ($quote->hasErrors() || $quote->parties === []) {
                    throw ValidationException::withMessages([
                        'departure_id' => $quote->errors() !== [] ? $quote->errors() : ['A charter price could not be calculated.'],
                    ]);
                }

                $priced = $quote->parties[0]->quote;

                if ($priced === null) {
                    throw ValidationException::withMessages([
                        'departure_id' => ['A charter price could not be calculated.'],
                    ]);
                }

                $rules = $this->config->businessRules();
                $rates = $this->config->rates();
                $validUntil = BusinessHours::fromDocument($rules)
                    ->endOfNthBusinessDay(BusinessTime::now(), $rules->charter->proposalValidBusinessDays);
                $number = $previous instanceof Document
                    ? $previous->number
                    : $this->references->next(ReferenceType::Proposal);
                $price = $priced->toArray();
                $bands = array_map(
                    fn (CancellationBand $band): string => CancellationPenalty::label($band, $rules->charterBands).' · '.$band->penaltyPct.'%',
                    $rules->charterBands,
                );
                $issuedAt = now();
                $snapshot = [
                    'number' => $number,
                    'version' => $version,
                    'yacht' => $enquiry->departure->yacht->name,
                    'departure' => $enquiry->departure->date->toDateString(),
                    'return' => $enquiry->departure->returnDate()->toDateString(),
                    'guests' => $enquiry->guests,
                    'lines' => $price['lines'],
                    'total' => $price['total'],
                    'deposit_pct' => $price['deposit_pct'],
                    'deposit' => $price['deposit'],
                    'deposit_business_days' => $rules->charter->depositBusinessDays,
                    'balance_days' => $rates->terms->charterBalanceDays,
                    'included' => array_map(
                        fn (array $line): string => (string) $line['label'],
                        $price['lines'],
                    ),
                    'excluded' => 'Extras and Galápagos fees are not part of this price.',
                    'bands' => $bands,
                    'valid_until' => $validUntil->setTimezone(BusinessTime::zone())->toDateString(),
                    'document' => [
                        'kind' => DocumentKind::CharterProposal->value,
                        'number' => $number,
                        'version' => $version,
                        'issued_at' => Iso::utc($issuedAt),
                    ],
                ];

                $html = view(DocumentView::name(DocumentKind::CharterProposal), ['snapshot' => $snapshot])->render();
                $bytes = $this->pdf->render($html);
                $path = 'charter-proposals/'.$enquiry->id.'/v'.$version.'.pdf';
                $disk->put($path, $bytes);

                $document = new Document;
                $document->booking_id = null;
                $document->charter_enquiry_id = $enquiry->id;
                $document->kind = DocumentKind::CharterProposal;
                $document->number = $number;
                $document->version = $version;
                $document->reason = $version > 1 ? trim((string) $reason) : null;
                $document->snapshot = $snapshot;
                $document->file_path = $path;
                $document->file_sha256 = hash('sha256', $bytes);
                $document->issued_at = $issuedAt;
                $document->issued_by = $actor->id;
                $document->save();

                $token = bin2hex(random_bytes(32));
                $pageUrl = rtrim((string) config('iconic.engine_url'), '/').'/charter-proposal/'.$token;

                BookingAccessToken::query()->create([
                    'booking_id' => null,
                    'charter_enquiry_id' => $enquiry->id,
                    'document_id' => $document->id,
                    'token_hash' => BookingAccessToken::hashToken($token),
                    'purpose' => BookingAccessTokenPurpose::CharterProposal,
                    'expires_at' => $validUntil,
                    'page_url' => $pageUrl,
                ]);

                $this->send($enquiry, $document, $pageUrl);

                $before = $enquiry->status;

                if ($enquiry->status !== CharterEnquiryStatus::Quoted) {
                    $enquiry->status = CharterEnquiryStatus::Quoted;
                    $enquiry->save();
                    History::record($enquiry, 'charter_enquiry.status_changed', before: [
                        'status' => $before->value,
                    ], after: [
                        'status' => CharterEnquiryStatus::Quoted->value,
                    ], actor: $actor);
                }

                History::record($enquiry, 'charter_enquiry.proposal_issued', after: [
                    'number' => $number,
                    'version' => $version,
                ], reason: $version > 1 ? trim((string) $reason) : null, actor: $actor);

                return $document;
            });
        } catch (Throwable $exception) {
            if (is_string($path)) {
                $disk->delete($path);
            }

            throw $exception;
        }
    }

    private function send(CharterEnquiry $enquiry, Document $document, string $pageUrl): void
    {
        $key = 'charter-proposal:'.$enquiry->id.':'.$document->version;
        $email = is_string($enquiry->contact->email) ? trim($enquiry->contact->email) : '';

        if ($email === '') {
            Delivery::query()->create([
                'booking_id' => null,
                'kind' => DeliveryKind::CharterProposal,
                'idempotency_key' => $key,
                'to' => [],
                'cc' => [],
                'subject' => 'Your Iconic charter proposal',
                'status' => DeliveryStatus::Blocked,
                'blocked_reason' => 'No email address on the contact',
                'triggered_by' => DeliveryTriggeredBy::System,
            ]);

            return;
        }

        $delivery = Delivery::query()->create([
            'booking_id' => null,
            'kind' => DeliveryKind::CharterProposal,
            'idempotency_key' => $key,
            'to' => [$email],
            'cc' => [],
            'subject' => 'Your Iconic charter proposal',
            'status' => DeliveryStatus::Queued,
            'triggered_by' => DeliveryTriggeredBy::System,
        ]);

        if (! $this->gate->allows(AutomationCatalogue::CHARTER_PROPOSAL)) {
            $delivery->status = DeliveryStatus::Blocked;
            $delivery->blocked_reason = $this->gate->reason(AutomationCatalogue::CHARTER_PROPOSAL);
            $delivery->save();
            History::record($enquiry, AutomationGate::SKIPPED, after: [
                'key' => AutomationCatalogue::CHARTER_PROPOSAL,
                'delivery_id' => $delivery->id,
            ], reason: $delivery->blocked_reason, system: true);

            return;
        }

        Mail::send(new CharterProposalMail($delivery, $pageUrl));

        $delivery->status = DeliveryStatus::Sent;
        $delivery->sent_at = now();
        $delivery->save();
    }
}
