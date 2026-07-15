<?php

$phaseModes = ['legacy', 'expanded'];
$phaseMode = strtolower(trim((string) env('ERP_PHASE_MODE', 'legacy')));

return [
    'phase_modes' => $phaseModes,
    'phase_mode' => in_array($phaseMode, $phaseModes, true) ? $phaseMode : 'legacy',
];
