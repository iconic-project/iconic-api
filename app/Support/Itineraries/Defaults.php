<?php

declare(strict_types=1);

namespace App\Support\Itineraries;

use App\Enums\ItineraryStatus;

/**
 * New-itinerary defaults from prototype rms_index.html `mkItin` / `STD_*`.
 */
final class Defaults
{
    /** @var list<string> */
    public const CHIPS = ['16 guests', "8 suites + owner's", '7 nights · Sun→Sun', 'SCY ↔ SCY'];

    public const OVERVIEW = 'Embark & disembark San Cristóbal (SCY) · All expeditions include naturalist guides, snorkel, kayak, guided walks and wildlife observation.';

    /** @var list<array{0: string, 1: string}> */
    public const FACTS = [
        ['Accommodation', "9 cabins — 8 Suites + Owner's Suite, all facing the sea"],
        ['Dining', 'Sustainable, locally sourced — expedition gastronomy'],
        ['Property', 'Sundeck, jacuzzi & Wi-Fi · 16 guests'],
        ['Crew & Guides', 'Expert naturalist guides · full expedition crew'],
        ['Activities', 'Snorkel, kayak, guided walks, wildlife observation'],
        ['Language', 'English & Spanish'],
    ];

    /** @var list<string> */
    public const INCLUDED = [
        'All meals aboard, expedition gastronomy, house wines with dinner',
        'All guided excursions, snorkel & kayak equipment, wetsuits',
        'Expert naturalist guides · transfers in San Cristóbal',
    ];

    /** @var list<string> */
    public const EXCLUDED = [
        'International & domestic flights · PNG entry fee (paid at SCY airport) · TCT transit card (USD 20)',
        'Travel insurance — sole responsibility of the passenger; Iconic does not sell or intermediate travel insurance',
        'Spa, premium bar & boutique — arranged with our concierge after booking',
    ];

    /** @var list<array{0: string, 1: string}> */
    public const FAQS = [
        ['Do I pay anything today?', 'No — book now, pay later. We hold your cabins obligation-free and send a 10% deposit link once our team confirms.'],
        ['Can children join?', 'From age 6 on departure day. Ages 6–17 receive −15% ppdo (not on festive departures).'],
        ['Can I change dates later?', 'Date changes are free of charge, subject to availability.'],
    ];

    public const EMBARK = 'San Cristóbal (SCY)';

    public const TAGLINE = '8 days · 7 nights';

    /**
     * Attributes for a new DRAFT itinerary (no code or name).
     *
     * @return array<string, mixed>
     */
    public static function attributes(): array
    {
        return [
            'status' => ItineraryStatus::Draft,
            'sort_order' => 9,
            'festive' => false,
            'days' => 8,
            'nights' => 7,
            'embark' => self::EMBARK,
            'disembark' => self::EMBARK,
            'tagline' => self::TAGLINE,
            'hero_image_path' => null,
            'hero_alt' => '',
            'fallback_gradient' => Gradients::DEFAULT_KEY,
            'card_description' => '',
            'overview' => self::OVERVIEW,
            'long_description' => '',
            'highlights' => [],
            'chips' => self::CHIPS,
            'facts' => self::FACTS,
            'day_plan' => [],
            'included' => self::INCLUDED,
            'excluded' => self::EXCLUDED,
            'faqs' => self::FAQS,
            'slug' => null,
            'meta_title' => '',
            'meta_description' => '',
        ];
    }

    /**
     * Payload for GET /itineraries/defaults (no stored path).
     *
     * @return array{
     *     status: string,
     *     sort_order: int,
     *     festive: bool,
     *     days: int,
     *     nights: int,
     *     embark: string,
     *     disembark: string,
     *     tagline: string,
     *     hero_alt: string,
     *     fallback_gradient: string,
     *     fallback_gradient_key: string,
     *     gradients: list<array{key: string, css: string}>,
     *     card_description: string,
     *     overview: string,
     *     long_description: string,
     *     highlights: list<string>,
     *     chips: list<string>,
     *     facts: list<array{0: string, 1: string}>,
     *     day_plan: list<array{0: string, 1: string}>,
     *     included: list<string>,
     *     excluded: list<string>,
     *     faqs: list<array{0: string, 1: string}>,
     *     slug: string|null,
     *     meta_title: string,
     *     meta_description: string
     * }
     */
    public static function payload(): array
    {
        $attributes = self::attributes();
        unset($attributes['hero_image_path']);
        $attributes['fallback_gradient'] = Gradients::css(Gradients::DEFAULT_KEY);
        $attributes['fallback_gradient_key'] = Gradients::DEFAULT_KEY;
        $attributes['gradients'] = Gradients::catalog();
        $attributes['status'] = ItineraryStatus::Draft->value;

        return $attributes;
    }
}
