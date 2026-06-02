<?php
/**
 * UK fixed-date public holidays. Bank holidays computed by floating
 * rules (Easter Monday, Spring Bank Holiday, etc.) are NOT here — they
 * need a date-computation step. Christmas + Boxing + New Year are the
 * three fixed dates that recur every year unchanged.
 *
 * If Christmas Day or Boxing Day falls on a weekend, the bank holiday
 * moves to the following Monday. v0.9 doesn't handle this shift — the
 * merchant can add a one-off "working" exception for the Saturday/Sunday
 * and a "holiday" exception for the Monday. Floating-rule support lands
 * in v0.10.
 */
return [
    ['day' => 1,  'month' => 1,  'description' => 'New Year\'s Day'],
    ['day' => 25, 'month' => 12, 'description' => 'Christmas Day'],
    ['day' => 26, 'month' => 12, 'description' => 'Boxing Day'],
];