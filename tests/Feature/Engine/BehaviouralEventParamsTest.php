<?php

declare(strict_types=1);

use App\Enums\BehaviouralEventName;
use App\Support\Engine\BehaviouralEventParams;
use Illuminate\Validation\ValidationException;

test('unknown parameter keys are dropped', function (): void {
    $filtered = BehaviouralEventParams::filter(BehaviouralEventName::PageView, [
        'page_path' => '/itineraries/western-realm',
        'email' => 'ada@iconic.test',
        'name' => 'Ada',
    ]);

    expect($filtered)->toBe(['page_path' => '/itineraries/western-realm']);
});

test('coupon codes are only kept on promotion events', function (): void {
    $promo = BehaviouralEventParams::filter(BehaviouralEventName::ApplyPromotion, [
        'coupon_code' => 'OPENING-27',
        'page_path' => '/checkout',
    ]);

    expect($promo)->toBe(['coupon_code' => 'OPENING-27']);

    $page = BehaviouralEventParams::filter(BehaviouralEventName::PageView, [
        'page_path' => '/itineraries/western-realm',
        'coupon_code' => 'OPENING-27',
    ]);

    expect($page)->toBe(['page_path' => '/itineraries/western-realm']);
});

test('a free-text value is refused', function (): void {
    BehaviouralEventParams::filter(BehaviouralEventName::SubmitBookingRequest, [
        'value' => 'Ada Lovelace',
    ]);
})->throws(ValidationException::class);

test('identity.stitched is refused from the client', function (): void {
    BehaviouralEventParams::filter(BehaviouralEventName::IdentityStitched, [
        'count' => 3,
    ]);
})->throws(ValidationException::class);

test('page_path is redacted before it is accepted', function (): void {
    $filtered = BehaviouralEventParams::filter(BehaviouralEventName::PageView, [
        'page_path' => '/complete/abc123secret/billing?x=1',
    ]);

    expect($filtered)->toBe(['page_path' => '/complete/[token]']);
});
