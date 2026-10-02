<?php

declare(strict_types=1);

use App\Support\Blocks\ScopeSummary;

test('consecutive suite numbers collapse to a range', function (): void {
    expect(ScopeSummary::format([
        ['property_code' => 'ANATIVA', 'date' => '2027-11-14', 'cabin_codes' => ['S7', 'S8']],
    ]))->toBe('ANATIVA · Suite 07–08 · 14 Nov 2027');
});

test('the owner suite is listed by name after suite ranges', function (): void {
    expect(ScopeSummary::format([
        ['property_code' => 'ANAMARA', 'date' => '2028-04-02', 'cabin_codes' => ['S7', 'S8', 'OWNER']],
    ]))->toBe("ANAMARA · Suite 07–08, Owner's Suite · 2 Apr 2028");
});

test('all nine cabins read Full property', function (): void {
    expect(ScopeSummary::format([
        ['property_code' => 'ANAMARA', 'date' => '2027-10-31', 'cabin_codes' => ScopeSummary::ALL_CABIN_CODES],
    ]))->toBe('ANAMARA · Full property · 31 Oct 2027');
});

test('gapped suites stay listed and several property-dates join with a semicolon', function (): void {
    expect(ScopeSummary::format([
        ['property_code' => 'ANATIVA', 'date' => '2027-11-21', 'cabin_codes' => ['S1', 'S3']],
        ['property_code' => 'ANAMARA', 'date' => '2027-11-14', 'cabin_codes' => ['S2']],
    ]))->toBe('ANAMARA · Suite 02 · 14 Nov 2027; ANATIVA · Suite 01, Suite 03 · 21 Nov 2027');
});
