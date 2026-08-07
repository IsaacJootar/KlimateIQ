<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nigerian states
    |--------------------------------------------------------------------------
    |
    | The 36 states plus the Federal Capital Territory, for registration and any
    | other state-scoped selection. Static reference data — not a database table,
    | since it never changes and every consumer just needs the plain list.
    |
    */
    'states' => [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
        'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'Gombe', 'Imo', 'Jigawa',
        'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger',
        'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe',
        'Zamfara', 'FCT (Abuja)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration designations
    |--------------------------------------------------------------------------
    |
    | Shown at signup so every user self-identifies their role from day one — this is
    | what makes per-role dashboard defaults possible later, not just a label.
    |
    */
    'designations' => [
        'LGA_OFFICER' => 'LGA Officer',
        'STATE_GOVERNMENT_OFFICER' => 'State Government Officer',
        'NGO' => 'NGO / Development Partner',
        'RESEARCHER' => 'Researcher',
        'OTHER' => 'Other',
    ],

];
