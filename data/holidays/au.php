<?php
/**
 * Australian national public holidays (fixed dates only). State-level
 * holidays (Labour Day in different states, etc.) are NOT included.
 * ANZAC Day (April 25) is technically national but the date is fixed
 * year-on-year so it stays in this list.
 */
return [
    ['day' => 1,  'month' => 1,  'description' => 'New Year\'s Day'],
    ['day' => 26, 'month' => 1,  'description' => 'Australia Day'],
    ['day' => 25, 'month' => 4,  'description' => 'ANZAC Day'],
    ['day' => 25, 'month' => 12, 'description' => 'Christmas Day'],
    ['day' => 26, 'month' => 12, 'description' => 'Boxing Day'],
];