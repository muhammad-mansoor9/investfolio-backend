<?php
namespace App\Models;

use Investfolio\InvestfolioShared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use HasUuid;
    use HasFactory;
    use Notifiable;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function role()
    {
        return $this->belongsTo(AdminRole::class, 'admin_role_id');
    }
}
