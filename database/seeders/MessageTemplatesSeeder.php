<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AutomationKind;
use App\Enums\JourneyStepAction;
use App\Enums\JourneySubject;
use App\Enums\TemplateVariable;
use App\Models\JourneyStep;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Support\Templates\TemplateTokens;
use Illuminate\Database\Seeder;
use RuntimeException;

class MessageTemplatesSeeder extends Seeder
{
    public const string APPROVAL_REFERENCE = 'Sprint 14: initial journey template';

    /**
     * Prototype AUTOS subjects. [ID] is {{booking_reference}}.
     *
     * @var array<string, string>
     */
    private const SUBJECTS = [
        'welcome_web_lead' => 'Your Galápagos adventure begins here — Iconic',
        'request_acknowledgement' => 'We have received your booking — {{booking_reference}}',
        'deposit_link' => 'Complete your reservation — {{booking_reference}}',
        'reengagement_6_months' => 'Back to Galápagos? A new expedition awaits you',
        'winback' => 'Sorry we missed you — what changed?',
        'cart_recovery_1' => 'Can we help you plan your Galápagos expedition?',
        'cart_recovery_2' => 'Still dreaming of Galápagos? We are here to help.',
        'cart_recovery_3' => 'Can we help plan your trip?',
    ];

    public function run(): void
    {
        $steps = JourneyStep::query()
            ->where('action', JourneyStepAction::Send)
            ->with('journey')
            ->orderBy('id')
            ->get();

        if (! in_array($steps->count(), [15, 18], true)) {
            throw new RuntimeException('Expected 15 or 18 journey send steps, found '.$steps->count().'.');
        }

        foreach ($steps as $step) {
            $existing = MessageTemplate::query()->where('key', $step->template_key)->first();

            if ($existing instanceof MessageTemplate && $existing->versions()->exists()) {
                continue;
            }

            $template = $existing instanceof MessageTemplate
                ? $existing
                : MessageTemplate::query()->create([
                    'key' => $step->template_key,
                    'name' => $step->name,
                    'kind' => $step->journey->kind,
                ]);

            $subject = self::SUBJECTS[$step->template_key] ?? $step->name;
            $body = $this->body($step);
            $variables = TemplateTokens::extract($subject, $body);
            $unsubscribe = in_array(TemplateVariable::UnsubscribeLink->value, $variables, true);

            if ($template->kind === AutomationKind::Marketing && ! $unsubscribe) {
                throw new RuntimeException($template->key.' is marketing and has no unsubscribe link.');
            }

            if ($template->kind === AutomationKind::Transactional && $unsubscribe) {
                throw new RuntimeException($template->key.' is transactional and carries an unsubscribe link.');
            }

            MessageTemplateVersion::query()->create([
                'template_id' => $template->id,
                'version' => 1,
                'subject' => $subject,
                'body' => $body,
                'variables' => $variables,
                'published' => true,
                'published_at' => now(),
                'approval_reference' => self::APPROVAL_REFERENCE,
            ]);
        }
    }

    /**
     * @return array{paragraphs: list<string>, list: list<string>, cta: array{label: string, link_key: string}|null}
     */
    private function body(JourneyStep $step): array
    {
        $journey = $step->journey;
        $booking = $journey->subject === JourneySubject::Booking && $journey->kind === AutomationKind::Transactional;
        $paragraph = $booking
            ? '{{first_name}}, this note about {{booking_reference}} is a placeholder until the client approves the copy.'
            : '{{first_name}}, this note is a placeholder until the client approves the copy.';

        return [
            'paragraphs' => [$paragraph],
            'list' => [],
            'cta' => $journey->kind === AutomationKind::Marketing
                ? ['label' => 'Unsubscribe', 'link_key' => TemplateVariable::UnsubscribeLink->value]
                : null,
        ];
    }
}
