<?php

namespace App\Domain\Approvals\Fingerprint;

/**
 * «Отпечаток» of an operation (TZ 03 §6.5 p.1): named groups of its data (items, party, discount, payments, …),
 * each kept as a sha256, plus the amounts that a decider may change (values: discount, debt, debt_due, …).
 * An approval is valid only while every group that was not released by «Разрешить с изменением» is the same.
 */
final class Fingerprint
{
    /**
     * @param  array<string, string>  $groups  group => sha256
     * @param  array<string, int|string|null>  $values
     */
    public function __construct(public readonly array $groups, public readonly array $values = []) {}

    /** @param array<string, mixed> $data group => raw data */
    public static function fromData(array $data, array $values = []): self
    {
        $groups = [];
        foreach ($data as $name => $payload) {
            $groups[$name] = hash('sha256', json_encode(self::normalize($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        }
        ksort($groups);

        return new self($groups, $values);
    }

    /** @param list<string> $released groups not compared @return list<string> differing groups */
    public function differences(array $storedGroups, array $released = []): array
    {
        $diff = [];
        foreach ($storedGroups as $name => $hash) {
            if (in_array($name, $released, true)) {
                continue;
            }
            if (($this->groups[$name] ?? null) !== $hash) {
                $diff[] = $name;
            }
        }
        foreach (array_keys($this->groups) as $name) {
            if (! array_key_exists($name, $storedGroups) && ! in_array($name, $released, true)) {
                $diff[] = $name;
            }
        }

        return $diff;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => self::normalize($v), $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_numeric($value) && ! is_string($value)) {
            return $value + 0;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value; // bigint columns may come back as strings
        }

        return $value;
    }
}
