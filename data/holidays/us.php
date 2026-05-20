<?php
/**
 * US federal holidays. year=null means "every year"; some holidays
 * (Thanksgiving, MLK Day, etc.) are computed by floating rules and are
 * NOT included here — they'd need a date-computation step rather than
 * a static row. v0.9 ships the fixed-date subset; floating holidays
 * land in v0.10 with a CLI flag to compute them per requested year.
 *
 * Returns array of ['day' => int, 'month' => int, 'description' => string].
 */
return [
    ['day' => 1,  'month' => 1,  'description' => 'New Year\'s Day'],
    ['day' => 19, 'month' => 6,  'description' => 'Juneteenth'],
    ['day' => 4,  'month' => 7,  'description' => 'Independence Day'],
    ['day' => 11, 'month' => 11, 'description' => 'Veterans Day'],
    ['day' => 25, 'month' => 12, 'description' => 'Christmas Day'],
];
