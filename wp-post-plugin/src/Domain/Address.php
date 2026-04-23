<?php

declare(strict_types=1);

namespace WPPost\Domain;

/**
 * Postal address value object. Covers the subset DCAPI's generateAddressLabel uses.
 */
final class Address
{
    public function __construct(
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly string $company = '',
        public readonly string $street = '',
        public readonly string $houseNo = '',
        public readonly string $zip = '',
        public readonly string $city = '',
        public readonly string $country = 'CH',
        public readonly string $email = '',
        public readonly string $phone = ''
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->street . $this->zip . $this->city) === '';
    }

    /**
     * DCAPI expects addresses as an object with name + address fields.
     * Keys intentionally match the Swiss Post field names.
     */
    public function toApiArray(): array
    {
        $name = trim($this->firstName . ' ' . $this->lastName);
        return array_filter([
            'name1'         => $this->company !== '' ? $this->company : $name,
            'name2'         => $this->company !== '' ? $name : '',
            'street'        => trim($this->street),
            'houseNo'       => $this->houseNo,
            'zip'           => $this->zip,
            'city'          => $this->city,
            'country'       => strtoupper($this->country),
            'email'         => $this->email,
            'phone'         => $this->phone,
        ], static fn ($v) => $v !== '' && $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            firstName: (string) ($data['first_name'] ?? ''),
            lastName:  (string) ($data['last_name']  ?? ''),
            company:   (string) ($data['company']    ?? ''),
            street:    (string) ($data['street']     ?? ''),
            houseNo:   (string) ($data['house_no']   ?? ''),
            zip:       (string) ($data['zip']        ?? ''),
            city:      (string) ($data['city']       ?? ''),
            country:   (string) ($data['country']    ?? 'CH'),
            email:     (string) ($data['email']      ?? ''),
            phone:     (string) ($data['phone']      ?? ''),
        );
    }
}
