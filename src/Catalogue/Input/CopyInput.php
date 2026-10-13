<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Input;

use DigitalLibrary\Catalogue\Vocabulary;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

final class CopyInput
{
    public function __construct(
        public readonly string $ownerId,
        public readonly string $locationId,
        public readonly string $inventoryCode,
        public readonly string $condition,
        public readonly string $status,
        public readonly bool $isSigned,
        public readonly string $acquiredOn,
        public readonly string $acquisitionSource,
        public readonly string $price,
        public readonly string $currency,
        public readonly string $notes,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $s = static fn (string $k): string => trim((string) $request->input($k, ''));

        return new self(
            $s('owner_user_id'), $s('location_id'), $s('inventory_code'), $s('copy_condition'),
            $s('status') !== '' ? $s('status') : 'available', $request->input('is_signed') === '1',
            $s('acquired_on'), $s('acquisition_source'), $s('price'), $s('currency'), $s('notes'),
        );
    }

    /**
     * @param array<string, mixed> $copy
     */
    public static function fromRecord(array $copy): self
    {
        $str = static fn (string $k): string => ($copy[$k] ?? null) === null ? '' : (string) $copy[$k];
        $cents = $copy['purchase_price_cents'] ?? null;

        return new self(
            $str('owner_user_id'), $str('location_id'), $str('inventory_code'), $str('copy_condition'), $str('status'),
            (bool) ($copy['is_signed'] ?? false), $str('acquired_on'), $str('acquisition_source'),
            $cents === null ? '' : sprintf('%d.%02d', intdiv((int) $cents, 100), (int) $cents % 100),
            $str('currency'), $str('notes'),
        );
    }

    public static function blank(int $ownerId): self
    {
        return new self((string) $ownerId, '', '', '', 'available', false, '', '', '', '', '');
    }

    public function validate(Validator $validator): void
    {
        $checks = Validator::make([
            'owner_user_id' => $this->ownerId,
            'location_id' => $this->locationId,
            'inventory_code' => $this->inventoryCode,
            'copy_condition' => $this->condition,
            'status' => $this->status,
            'acquired_on' => $this->acquiredOn,
            'acquisition_source' => $this->acquisitionSource,
            'price' => $this->price,
            'currency' => $this->currency,
            'notes' => $this->notes,
        ], [
            'owner_user_id' => ['required', 'integer'],
            'location_id' => ['integer'],
            'inventory_code' => ['max:64', 'no_control'],
            'copy_condition' => ['in:' . implode(',', Vocabulary::CONDITIONS)],
            'status' => ['required', 'in:' . implode(',', Vocabulary::STATUSES)],
            'acquired_on' => ['date'],
            'acquisition_source' => ['max:255', 'no_control'],
            'price' => ['money'],
            'currency' => ['in:' . implode(',', Vocabulary::CURRENCIES)],
            'notes' => ['max:5000'],
        ]);
        foreach ($checks->errors() as $field => $errors) {
            foreach ($errors as $error) {
                $validator->addError($field, $error);
            }
        }
        if ($this->price !== '' && $this->currency === '') {
            $validator->addError('currency', 'validation.currency_required');
        }
    }

    public function ownerId(): int
    {
        return (int) $this->ownerId;
    }

    public function locationId(): ?int
    {
        return $this->locationId === '' ? null : (int) $this->locationId;
    }

    /**
     * @return array<string, mixed> column values for CopyRepository
     */
    public function toRecord(): array
    {
        $cents = null;
        if ($this->price !== '') {
            [$whole, $fraction] = array_pad(preg_split('/[.,]/', $this->price) ?: [], 2, '0');
            $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        }

        return [
            'owner_user_id' => $this->ownerId(),
            'location_id' => $this->locationId(),
            'inventory_code' => WorkInput::nullable($this->inventoryCode),
            'copy_condition' => WorkInput::nullable($this->condition),
            'status' => $this->status,
            'is_signed' => $this->isSigned,
            'acquired_on' => WorkInput::nullable($this->acquiredOn),
            'acquisition_source' => WorkInput::nullable($this->acquisitionSource),
            'purchase_price_cents' => $cents,
            'currency' => $cents === null ? null : WorkInput::nullable($this->currency),
            'notes' => WorkInput::nullable($this->notes),
        ];
    }
}
