<?php

use App\Services\Ingestion\RainfallIngestionService;
use App\Services\Ingestion\TemperatureIngestionService;

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
    ],

];
