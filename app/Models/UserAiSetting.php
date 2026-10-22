<?php

declare(strict_types=1);

namespace App\Models;

class UserAiSetting extends PgsqlModel
{
    protected $table = 'user_ai_settings';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'provider',
        'model',
        'api_key',
    ];

    protected $hidden = [
        'api_key',
    ];

    // Encrypted with APP_KEY: rotating APP_KEY makes every stored key unreadable,
    // and users would have to re-enter them.
    protected $casts = [
        'api_key' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
