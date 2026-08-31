<?php

namespace App\Support;

/**
 * Nigeria's agro-ecological zones and the states that fall in each — the bridge between a
 * region's `state` and the zone-scoped crop calendar (Clarity Pass D2). A coarse, widely-used
 * classification (FAO / FEWS NET); a state that straddles two zones is placed in the one that
 * covers most of its cropland.
 */
class AgroZone
{
    public const SAHEL = 'Sahel';

    public const SUDAN_SAVANNA = 'Sudan Savanna';

    public const NORTHERN_GUINEA = 'Northern Guinea Savanna';

    public const SOUTHERN_GUINEA = 'Southern Guinea Savanna';

    public const DERIVED_SAVANNA = 'Derived Savanna';

    public const HUMID_FOREST = 'Humid Forest';

    /** @var array<string, string> state (lower-case) => zone */
    private const STATE_ZONE = [
        // Sahel — the far north-east/north-west fringe
        'borno' => self::SAHEL,
        'yobe' => self::SAHEL,

        // Sudan Savanna — the core dry north
        'sokoto' => self::SUDAN_SAVANNA,
        'kebbi' => self::SUDAN_SAVANNA,
        'zamfara' => self::SUDAN_SAVANNA,
        'katsina' => self::SUDAN_SAVANNA,
        'kano' => self::SUDAN_SAVANNA,
        'jigawa' => self::SUDAN_SAVANNA,
        'bauchi' => self::SUDAN_SAVANNA,
        'gombe' => self::SUDAN_SAVANNA,

        // Northern Guinea Savanna — the middle belt north
        'kaduna' => self::NORTHERN_GUINEA,
        'niger' => self::NORTHERN_GUINEA,
        'plateau' => self::NORTHERN_GUINEA,
        'adamawa' => self::NORTHERN_GUINEA,
        'taraba' => self::NORTHERN_GUINEA,
        'fct' => self::NORTHERN_GUINEA,
        'nasarawa' => self::NORTHERN_GUINEA,

        // Southern Guinea Savanna — the middle belt south
        'kwara' => self::SOUTHERN_GUINEA,
        'kogi' => self::SOUTHERN_GUINEA,
        'benue' => self::SOUTHERN_GUINEA,

        // Derived Savanna — the forest-savanna transition
        'oyo' => self::DERIVED_SAVANNA,
        'osun' => self::DERIVED_SAVANNA,
        'ekiti' => self::DERIVED_SAVANNA,
        'enugu' => self::DERIVED_SAVANNA,
        'ebonyi' => self::DERIVED_SAVANNA,

        // Humid Forest — the south and the coast
        'lagos' => self::HUMID_FOREST,
        'ogun' => self::HUMID_FOREST,
        'ondo' => self::HUMID_FOREST,
        'edo' => self::HUMID_FOREST,
        'delta' => self::HUMID_FOREST,
        'rivers' => self::HUMID_FOREST,
        'bayelsa' => self::HUMID_FOREST,
        'cross river' => self::HUMID_FOREST,
        'akwa ibom' => self::HUMID_FOREST,
        'abia' => self::HUMID_FOREST,
        'imo' => self::HUMID_FOREST,
        'anambra' => self::HUMID_FOREST,
    ];

    /** @return list<string> */
    public static function zones(): array
    {
        return [
            self::SAHEL,
            self::SUDAN_SAVANNA,
            self::NORTHERN_GUINEA,
            self::SOUTHERN_GUINEA,
            self::DERIVED_SAVANNA,
            self::HUMID_FOREST,
        ];
    }

    public static function forState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        return self::STATE_ZONE[trim(mb_strtolower($state))] ?? null;
    }
}
