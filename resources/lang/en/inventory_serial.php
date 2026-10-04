<?php

return [
    'numbers' => 'Serial numbers',
    'number' => 'Serial number',
    'input_help' => 'For a new serialized receipt, enter one serial per line. For issue, select its existing receipt layer.',
    'invalid_serial' => 'Enter a valid serial number for a serial-tracked product.',
    'exact_unit_required' => 'Each serial must represent exactly one base unit and use its current receipt layer.',
    'count_mismatch' => 'The serial count must equal the received base quantity. Enter each serial once.',
    'already_received' => 'This serial already exists or is currently stocked. Return it through its original source document.',
    'select_existing' => 'Select the existing serial receipt layer for an issue or transfer.',
    'identity_immutable' => 'A serial identity cannot be changed or deleted after creation.',
    'tracking_history' => 'Serial tracking cannot be changed after this product has inventory history. Use a controlled source-based conversion.',
    'staging_selection' => 'Select the exact staged serials for consumption, waste or return.',
    'selection_mismatch' => 'Select one current serial receipt layer for each returned base unit.',
    'source_selection' => 'Select distinct serials from the accepted source document. A serial already selected by another receipt is unavailable.',
    'accepted_numbers' => 'Accepted serial numbers',
];
