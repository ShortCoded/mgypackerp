<?php

namespace Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class MailConfiguration extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'mailer',
        'host',
        'port',
        'username',
        'password',
        'scheme',
        'from_address',
        'from_name',
        'timeout',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'timeout' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
