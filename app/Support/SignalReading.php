<?php

namespace App\Support;

/**
 * Clarity Pass A3 — turn a raw signal value into a phrase a person reads once: "8 mm of rain
 * this week — very little", "hot dry air", "soil that's drying out". The qualitative word comes
 * from absolute, climatology-grounded thresholds for Nigeria (not the per-index calibration
 * bounds, which describe risk, not the physical quantity), so "heavy rain" means heavy rain
 * whether the index treats it as good or bad.
 *
 * Signals without a meaningful absolute scale (population, elevation) just state the number.
 * The curated month-by-zone "normal for the season" dataset is a later enrichment — a region's
 * own recent history (passed as $recent) is used for the "up from / down from" clause instead,
 * which is grounded in real data and sharpens as history accumulates.
 */
class SignalReading
{
    /**
     * code => [
     *   'noun'  => short noun phrase for the quantity,
     *   'unit'  => display unit,
     *   'bands' => ordered list of [upperBound, adjective]; a null upperBound is the ceiling
     *              catch-all. First band whose upper bound the value falls under wins.
     * ]
     *
     * @var array<string, array{noun: string, unit: string, bands: array<int, array{0: float|null, 1: string}>}>
     */
    private const SCALE = [
        'RAINFALL' => ['noun' => 'rain', 'unit' => 'mm', 'bands' => [[10, 'very little'], [30, 'light'], [70, 'moderate'], [120, 'heavy'], [null, 'very heavy']]],
        'STANDING_WATER' => ['noun' => 'standing water', 'unit' => '%', 'bands' => [[5, 'almost none'], [20, 'some'], [45, 'widespread'], [null, 'extensive']]],
        'TEMPERATURE' => ['noun' => 'temperature', 'unit' => '°C', 'bands' => [[22, 'cool'], [28, 'mild'], [33, 'warm'], [38, 'hot'], [null, 'very hot']]],
        'SOIL_MOISTURE' => ['noun' => 'soil moisture', 'unit' => 'm³/m³', 'bands' => [[0.12, 'very dry'], [0.20, 'dry'], [0.30, 'adequate'], [null, 'wet']]],
        'EVAPOTRANSPIRATION' => ['noun' => 'evaporation demand', 'unit' => 'mm', 'bands' => [[15, 'low'], [28, 'moderate'], [40, 'high'], [null, 'very high']]],
        'HUMIDITY' => ['noun' => 'air humidity', 'unit' => '%', 'bands' => [[25, 'very dry'], [45, 'dry'], [70, 'moderate'], [null, 'humid']]],
        'WIND_SPEED' => ['noun' => 'wind', 'unit' => 'km/h', 'bands' => [[12, 'light'], [25, 'moderate'], [40, 'strong'], [null, 'very strong']]],
        'DUST' => ['noun' => 'airborne dust', 'unit' => 'µg/m³', 'bands' => [[20, 'clear'], [80, 'hazy'], [200, 'heavy'], [null, 'severe']]],
        'AIR_QUALITY_PM25' => ['noun' => 'fine particle pollution', 'unit' => 'µg/m³', 'bands' => [[15, 'clean'], [35, 'moderate'], [75, 'unhealthy'], [null, 'hazardous']]],
        'AIR_QUALITY_PM10' => ['noun' => 'coarse particle pollution', 'unit' => 'µg/m³', 'bands' => [[30, 'clean'], [75, 'moderate'], [150, 'unhealthy'], [null, 'hazardous']]],
        'OZONE' => ['noun' => 'ground-level ozone', 'unit' => 'µg/m³', 'bands' => [[60, 'low'], [120, 'moderate'], [180, 'high'], [null, 'very high']]],
        'NO2' => ['noun' => 'nitrogen dioxide', 'unit' => 'µg/m³', 'bands' => [[20, 'low'], [50, 'moderate'], [100, 'high'], [null, 'very high']]],
        'VEGETATION' => ['noun' => 'vegetation cover', 'unit' => 'NDVI', 'bands' => [[0.15, 'bare'], [0.3, 'sparse'], [0.5, 'moderate'], [null, 'lush']]],
        'ACTIVE_FIRE' => ['noun' => 'fire detections', 'unit' => '', 'bands' => [[0.5, 'no'], [3, 'a few'], [10, 'several'], [null, 'many']]],
    ];

    /**
     * @return array{noun: string, adjective: string, value: string, sentence: string}
     */
    public static function describe(string $code, float $value): array
    {
        $scale = self::SCALE[$code] ?? null;

        if ($scale === null) {
            $noun = strtolower(str_replace('_', ' ', $code));

            return [
                'noun' => $noun,
                'adjective' => '',
                'value' => self::num($value),
                'sentence' => ucfirst($noun).' at '.self::num($value),
            ];
        }

        $adjective = self::band($scale['bands'], $value);
        $unit = $scale['unit'] !== '' ? ' '.$scale['unit'] : '';
        $valueText = self::num($value).$unit;

        $sentence = match ($code) {
            'RAINFALL' => "{$valueText} of rain — {$adjective}",
            'ACTIVE_FIRE' => ucfirst($adjective).' fire detections nearby',
            default => ucfirst($scale['noun'])." {$adjective} ({$valueText})",
        };

        return [
            'noun' => $scale['noun'],
            'adjective' => $adjective,
            'value' => $valueText,
            'sentence' => $sentence,
        ];
    }

    /**
     * "up from ~18 mm in recent weeks" / "" when the change is small. $recent is the mean of
     * the region's own prior readings for this signal.
     */
    public static function versusRecent(string $code, float $value, ?float $recent): string
    {
        if ($recent === null || $recent == 0.0) {
            return '';
        }

        $change = ($value - $recent) / abs($recent);

        if (abs($change) < 0.25) {
            return '';
        }

        $unit = isset(self::SCALE[$code]) && self::SCALE[$code]['unit'] !== '' ? ' '.self::SCALE[$code]['unit'] : '';

        return ($change > 0 ? 'up from ~' : 'down from ~').self::num($recent).$unit.' in recent weeks';
    }

    /**
     * @param  array<int, array{0: float|null, 1: string}>  $bands
     */
    private static function band(array $bands, float $value): string
    {
        foreach ($bands as [$upper, $adjective]) {
            if ($upper === null || $value < $upper) {
                return $adjective;
            }
        }

        return $bands[array_key_last($bands)][1];
    }

    private static function num(float $value): string
    {
        if (abs($value) >= 100 || $value == (int) $value) {
            return (string) round($value);
        }

        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
