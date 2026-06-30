<?php

namespace App\Models;

use App\Models\PgsqlModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends PgsqlModel
{
    use HasFactory;

    protected $table = 'company_announcements';

    protected $fillable = [
        'stock_id',
        'title',
        'pdf_url',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Get the stock that owns the announcement.
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function corporateAction()
    {
        return $this->hasOne(CorporateAction::class);
    }
}
