<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class BoardMeeting extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'board_meetings';

    protected $fillable = [
        'stock_id',
        'bm_date',
        'bm_time',
        'place',
        'to_consider',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'bm_date' => 'date',
        'bm_time' => 'datetime:H:i',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the stock that owns the board meeting.
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    /**
     * Scope a query to only include upcoming meetings (all future meetings).
     */
    public function scopeUpcoming($query)
    {
        return $query->where('bm_date', '>=', Carbon::today())
            ->orderBy('bm_date', 'asc')
            ->orderBy('bm_time', 'asc');
    }

    /**
     * Scope a query to search meetings.
     */
    public function scopeSearch($query, $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->whereHas('stock', function ($stockQuery) use ($search) {
                $stockQuery->where('symbol', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%");
            })
                ->orWhere('place', 'ILIKE', "%{$search}%")
                ->orWhere('to_consider', 'ILIKE', "%{$search}%");
        });
    }

    /**
     * Get formatted meeting date and time.
     */
    public function getFormattedDateTimeAttribute(): string
    {
        return $this->bm_date->format('M d, Y') . ' at ' .
            Carbon::parse($this->bm_time)->format('h:i A');
    }

    /**
     * Check if meeting is today.
     */
    public function getIsTodayAttribute(): bool
    {
        return $this->bm_date->isToday();
    }

    /**
     * Check if meeting is upcoming (on or after today).
     */
    public function getIsUpcomingAttribute(): bool
    {
        return $this->bm_date->gte(Carbon::today());
    }

    /**
     * Get days until meeting.
     */
    public function getDaysUntilAttribute(): int
    {
        return Carbon::today()->diffInDays($this->bm_date, false);
    }
}
