<?php

use App\Services\Facilities\Grid3StaticProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Facility provider
    |--------------------------------------------------------------------------
    |
    | Where "the schools and health centres in this LGA" comes from. Must
    | implement App\Services\Facilities\FacilityProvider. Grid3StaticProvider
    | reads the local `facilities` table (imported from GRID3 Nigeria). To move
    | to a live source later — e.g. a HealthsitesApiProvider — write the class
    | and point this at it; nothing downstream changes.
    |
    */
    'provider' => Grid3StaticProvider::class,

];
