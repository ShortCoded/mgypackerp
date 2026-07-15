<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\SettingService;

class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    protected static function booted(): void
    {
        $forgetCache = function (Setting $setting): void {
            if (is_string($setting->key) && $setting->key !== '') {
                SettingService::forgetPersistentCacheFor($setting->key);
            }
        };

        static::saved($forgetCache);
        static::deleted($forgetCache);
    }
}
