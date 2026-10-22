<?php

namespace App\Models;

use App\Traits\HasUuid;

class McpOauthClient extends PgsqlModel
{
    use HasUuid;

    protected $table = 'mcp_oauth_clients';

    protected $fillable = ['name', 'redirect_uris'];

    protected $casts = ['redirect_uris' => 'array'];
}
