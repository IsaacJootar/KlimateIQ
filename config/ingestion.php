<?php

use App\Services\Ingestion\AirQualityPm10IngestionService;
use App\Services\Ingestion\AirQualityPm25IngestionService;
use App\Services\Ingestion\ElevationIngestionService;
use App\Services\Ingestion\EvapotranspirationIngestionService;
use App\Services\Ingestion\PopulationExposureIngestionService;
use App\Services\Ingestion\RainfallIngestionService;
use App\Services\Ingestion\SoilMoistureIngestionService;
use App\Services\Ingestion\StandingWaterIngestionService;
use App\Services\Ingestion\TemperatureIngestionService;
use App\Services\Ingestion\VegetationIngestionService;

return [

    /*
    |--------------------------------------------------------------------------
    | Signal ingestion sources
    |--------------------------------------------------------------------------
    |
    | Every class here must implement App\Services\Ingestion\SignalIngestionService.
    | The scheduler and the signals:ingest command both iterate this list, so adding
    | or removing a data source is a one-line change here plus one new class — see
    | the developer guide (docs/INGESTION_GUIDE.md) for how to plug in a new source.
    |
    */
    'sources' => [
        RainfallIngestionService::class,
        TemperatureIngestionService::class,
        VegetationIngestionService::class,
        ElevationIngestionService::class,
        StandingWaterIngestionService::class,
        PopulationExposureIngestionService::class,
        AirQualityPm25IngestionService::class,
        AirQualityPm10IngestionService::class,
        SoilMoistureIngestionService::class,
        EvapotranspirationIngestionService::class,
    ],

];
