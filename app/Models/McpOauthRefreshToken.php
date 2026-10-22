<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class McpOauthRefreshToken extends PgsqlModel
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'mcp_oauth_refresh_tokens';

    protected $fillable = [
        'family_id',
        'user_id',
        'client_id',
        'client_name',
        'access_token_id',
        'token_hash',
        'expires_at',
        'rotated_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
