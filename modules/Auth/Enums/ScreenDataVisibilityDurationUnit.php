<?php

namespace Modules\Auth\Enums;

enum ScreenDataVisibilityDurationUnit: string
{
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';
    case Years = 'years';
}
