<?php

use App\Services\Ingestion\ActiveFireIngestionService;
use App\Services\Ingestion\AirQualityNo2IngestionService;
use App\Services\Ingestion\AirQualityOzoneIngestionService;
use App\Services\Ingestion\AirQualityPm10IngestionService;
use App\Services\Ingestion\AirQualityPm25IngestionService;
use App\Services\Ingestion\DustIngestionService;
use App\Services\Ingestion\ElevationIngestionService;
use App\Services\Ingestion\EvapotranspirationIngestionService;
use App\Services\Ingestion\HumidityIngestionService;
use App\Services\Ingestion\PopulationExposureIngestionService;
use App\Services\Ingestion\RainfallEnsembleService;
use App\Services\Ingestion\RainfallForecastService;
use App\Services\Ingestion\RainfallIngestionService;
use App\Services\Ingestion\RiverDischargeEnsembleService;
use App\Services\Ingestion\RiverDischargeForecastService;
use App\Services\Ingestion\RiverDischargeIngestionService;
use App\Services\Ingestion\SoilMoistureIngestionService;
use App\Services\Ingestion\StandingWaterIngestionService;
use App\Services\Ingestion\TemperatureEnsembleService;
use App\Services\Ingestion\TemperatureForecastService;
use App\Services\Ingestion\TemperatureIngestionService;
use App\Services\Ingestion\VegetationIngestionService;
use App\Services\Ingestion\WindIngestionService;

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
        AirQualityOzoneIngestionService::class,
        AirQualityNo2IngestionService::class,
        SoilMoistureIngestionService::class,
        EvapotranspirationIngestionService::class,
        HumidityIngestionService::class,
        WindIngestionService::class,
        DustIngestionService::class,
        ActiveFireIngestionService::class,
        RiverDischargeIngestionService::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Forecast ingestion sources
    |--------------------------------------------------------------------------
    |
    | Every class here must implement App\Services\Ingestion\ForecastIngestionService.
    | signals:ingest-forecast and its scheduler iterate this list. Kept separate from
    | 'sources' above because forecast and observed data live in separate tables and
    | separate pipelines end to end — see BUILD_PLAN.md T4.
    |
    */
    'forecast_sources' => [
        RiverDischargeForecastService::class,
        RainfallForecastService::class,
        TemperatureForecastService::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ensemble forecast sources
    |--------------------------------------------------------------------------
    |
    | Every class here must implement App\Services\Ingestion\EnsembleForecastIngestionService.
    | signals:ingest-ensemble and its scheduler iterate this list. These write many member rows
    | per (region, signal) into region_forecast_signals — the same lane as the deterministic
    | forecast, tagged with a member id — and feed the probabilistic score (BUILD_PLAN.md T5).
    |
    */
    'ensemble_sources' => [
        RiverDischargeEnsembleService::class,
        RainfallEnsembleService::class,
        TemperatureEnsembleService::class,
    ],

    'ensemble' => [
        // Weather models pooled for the rainfall / temperature member spread. Multiple
        // independent models give a better-calibrated spread than one model's perturbations
        // alone. ECMWF runs ~15 days (shorter members are fine — scoring tolerates a ragged
        // tail); GFS and ICON cover the full horizon.
        'weather_models' => ['gfs_seamless', 'ecmwf_ifs04', 'icon_seamless'],
    ],

];
