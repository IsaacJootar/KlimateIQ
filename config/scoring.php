<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default scoring strategy
    |--------------------------------------------------------------------------
    |
    | 'formula' | 'trained_model'. A region can override this via its own
    | preferred_scoring_strategy column. See TrainedModelScoringStrategy's docblock for
    | what's needed before 'trained_model' actually takes effect anywhere.
    |
    */
    'strategy' => env('SCORING_STRATEGY', 'formula'),

];
