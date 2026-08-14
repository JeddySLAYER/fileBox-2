<?php

namespace App\Support;

final class DurationHours
{
    public static function toHours(mixed $amount, mixed $unit = 'hours'): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $hours = (int) $amount;
        if ($hours < 1) {
            return null;
        }

        return $unit === 'days' ? $hours * 24 : $hours;
    }

    /**
     * @return array{amount: int, unit: string}|null
     */
    public static function toParts(?int $hours): ?array
    {
        if ($hours === null || $hours < 1) {
            return null;
        }

        if ($hours % 24 === 0) {
            return ['amount' => intdiv($hours, 24), 'unit' => 'days'];
        }

        return ['amount' => $hours, 'unit' => 'hours'];
    }
}
