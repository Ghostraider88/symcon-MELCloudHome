<?php

declare(strict_types=1);

final class MELCloudDataTools
{
    /** @return array<string,float> Zeitstempel (UTC) => Wh */
    public static function parseEnergyEntries(array $data, DateTimeImmutable $now, float $maxWh = 100000.0): array
    {
        $entries = [];
        foreach ($data['measureData'] ?? [] as $measure) {
            foreach ($measure['values'] ?? [] as $entry) {
                if (!is_array($entry) || !isset($entry['value']) || !is_numeric($entry['value'])) {
                    continue;
                }
                $value = (float) $entry['value'];
                if (!is_finite($value) || $value < 0 || $value > $maxWh) {
                    continue;
                }
                $timestamp = self::timestampFromValue($entry['timestamp'] ?? $entry['recordedAt'] ?? $entry['time'] ?? $entry['date'] ?? $entry['x'] ?? null);
                if ($timestamp === null || $timestamp > $now->getTimestamp() + 300) {
                    continue;
                }
                $entries[(string) $timestamp] = $value;
            }
        }
        ksort($entries, SORT_NUMERIC);
        return $entries;
    }

    /** @return array{state:array<string,mixed>, rolling24hKWh:float, deltaKWh:float, rejected:int} */
    public static function updateEnergyState(array $state, array $entries, int $now): array
    {
        $samples = is_array($state['samples'] ?? null) ? $state['samples'] : [];
        $total = is_numeric($state['totalKWh'] ?? null) ? max(0.0, (float) $state['totalKWh']) : 0.0;
        $initialized = (bool) ($state['initialized'] ?? false);
        $delta = 0.0;
        $rejected = 0;

        if (!$initialized) {
            foreach ($entries as $timestamp => $value) {
                $samples[(string) $timestamp] = (float) $value;
            }
            $initialized = true;
        } else {
            foreach ($entries as $timestamp => $value) {
                $key = (string) $timestamp;
                if (!array_key_exists($key, $samples)) {
                    $delta += (float) $value / 1000.0;
                    $samples[$key] = (float) $value;
                    continue;
                }
                $previous = (float) $samples[$key];
                if ($value > $previous) {
                    $delta += ((float) $value - $previous) / 1000.0;
                    $samples[$key] = (float) $value;
                } elseif ($value < $previous) {
                    $rejected++;
                }
            }
            $total += $delta;
        }

        foreach ($samples as $timestamp => $_value) {
            if ((int) $timestamp < $now - 172800) {
                unset($samples[$timestamp]);
            }
        }

        $rollingWh = 0.0;
        foreach ($entries as $timestamp => $value) {
            if ((int) $timestamp >= $now - 86400) {
                $rollingWh += (float) $value;
            }
        }

        return [
            'state'         => [
                'version'     => 1,
                'initialized' => $initialized,
                'totalKWh'    => round($total, 6),
                'samples'     => $samples,
                'updatedAt'   => $now
            ],
            'rolling24hKWh' => $rollingWh / 1000.0,
            'deltaKWh'      => $delta,
            'rejected'      => $rejected
        ];
    }

    /** @return array{value:float,recordedAt:int}|null */
    public static function parseOutdoorReading(array $data, string $timezone, int $now): ?array
    {
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        try {
            $zone = new DateTimeZone($timezone !== '' ? $timezone : 'Europe/Berlin');
        } catch (Exception) {
            $zone = new DateTimeZone('Europe/Berlin');
        }
        $latest = null;
        foreach ($data['datasets'] ?? [] as $dataset) {
            if (stripos((string) ($dataset['label'] ?? ''), 'OUTDOOR_TEMPERATURE') === false) {
                continue;
            }
            foreach ($dataset['data'] ?? [] as $point) {
                if (!is_array($point) || !isset($point['y']) || !is_numeric($point['y'])) {
                    continue;
                }
                $timestamp = self::timestampFromValue($point['x'] ?? $point['timestamp'] ?? $point['time'] ?? null, $zone);
                if ($timestamp === null || $timestamp > $now + 300 || (int) date('i', $timestamp) === 0) {
                    continue;
                }
                if ($latest === null || $timestamp > $latest['recordedAt']) {
                    $latest = ['value' => (float) $point['y'], 'recordedAt' => $timestamp];
                }
            }
        }
        return $latest;
    }

    private static function timestampFromValue(mixed $value, ?DateTimeZone $timezone = null): ?int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{10,13}$/', $value))) {
            $number = (int) $value;
            return strlen((string) $value) >= 13 ? (int) floor($number / 1000) : $number;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, $timezone ?? new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }
}
