<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorporateAction extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'announcement_id',
        'stock_id',
        'dividend_percent',
        'bonus_percent',
        'right_issue',
        'ex_date',
        'notes',
    ];

    protected $dates = [
        'ex_date',
    ];

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }
}
