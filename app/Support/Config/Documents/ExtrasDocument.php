<?php

declare(strict_types=1);

namespace App\Support\Config\Documents;

use App\Enums\ConfigKind;
use App\Support\Config\ConfigDocument;

final class ExtrasDocument extends ConfigDocument
{
    /**
     * @param  list<ExtraItem>  $items
     */
    public function __construct(
        public readonly array $items,
    ) {}

    /**
     * @return array{items: list<array{
     *     code: string,
     *     name: string,
     *     unit: string,
     *     price_usd: int|null,
     *     triggers_transfer_voucher: bool,
     *     active: bool
     * }>}
     */
    public static function initial(): array
    {
        return [
            'items' => [
                [
                    'code' => 'FLT',
                    'name' => 'Domestic flights GYE/UIO ↔ SCY (round-trip)',
                    'unit' => 'per person',
                    'price_usd' => 420,
                    'triggers_transfer_voucher' => true,
                    'active' => true,
                ],
                [
                    'code' => 'HPRE',
                    'name' => 'Pre-cruise hotel — San Cristóbal (1 night, double)',
                    'unit' => 'per room-night',
                    'price_usd' => 320,
                    'triggers_transfer_voucher' => true,
                    'active' => true,
                ],
                [
                    'code' => 'HPOST',
                    'name' => 'Post-cruise hotel — San Cristóbal (1 night, double)',
                    'unit' => 'per room-night',
                    'price_usd' => 320,
                    'triggers_transfer_voucher' => true,
                    'active' => true,
                ],
                [
                    'code' => 'SPA',
                    'name' => 'Spa treatment',
                    'unit' => 'per treatment',
                    'price_usd' => null,
                    'triggers_transfer_voucher' => false,
                    'active' => true,
                ],
                [
                    'code' => 'BAR',
                    'name' => 'Premium bar package',
                    'unit' => 'per person',
                    'price_usd' => null,
                    'triggers_transfer_voucher' => false,
                    'active' => true,
                ],
                [
                    'code' => 'BTQ',
                    'name' => 'Iconic boutique',
                    'unit' => 'per item',
                    'price_usd' => null,
                    'triggers_transfer_voucher' => false,
                    'active' => true,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $items = [];

        foreach ($data['items'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $items[] = ExtraItem::fromArray($row);
        }

        return new self($items);
    }

    /**
     * @return array{items: list<array{
     *     code: string,
     *     name: string,
     *     unit: string,
     *     price_usd: int|null,
     *     triggers_transfer_voucher: bool,
     *     active: bool
     * }>}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                fn (ExtraItem $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.code' => ['required', 'string', 'max:16', 'distinct'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.unit' => ['required', 'string', 'max:64'],
            'items.*.price_usd' => ['nullable', 'integer', 'min:0'],
            'items.*.triggers_transfer_voucher' => ['required', 'boolean'],
            'items.*.active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'items' => 'Extras catalogue',
        ];
    }

    public static function kind(): ConfigKind
    {
        return ConfigKind::Extras;
    }

    public function find(string $code): ?ExtraItem
    {
        foreach ($this->items as $item) {
            if ($item->code === $code) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function publishErrors(?ConfigDocument $published): array
    {
        if (! $published instanceof self) {
            return [];
        }

        $newCodes = [];

        foreach ($this->items as $item) {
            $newCodes[$item->code] = true;
        }

        $errors = [];

        foreach ($published->items as $index => $item) {
            if (! isset($newCodes[$item->code])) {
                $errors['items.'.$index.'.code'] = [
                    'Catalogue codes cannot be removed or renamed once published. Deactivate the item instead.',
                ];
            }
        }

        return $errors;
    }
}
