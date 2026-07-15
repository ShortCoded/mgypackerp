<?php

namespace Modules\Production\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\ItemLookup;

class ProductionIdentifierType extends ItemLookup
{
    protected $table = 'production_identifier_types';

    public function identifiers(): HasMany
    {
        return $this->hasMany(ProductionIdentifier::class, 'production_identifier_type_id');
    }
}
