<?php

namespace Modules\Auth\Enums;

enum ScreenDataVisibilityRecordScope: string
{
    case OwnRecords = 'own_records';
    case AuthorizedScope = 'authorized_scope';
}
