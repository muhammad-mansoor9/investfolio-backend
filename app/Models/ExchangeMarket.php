<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class ExchangeMarket extends PgsqlModel
{
    protected $fillable = ['country_id', 'identification_code', 'name', 'currency'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
