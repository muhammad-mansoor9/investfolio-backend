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
        'board_meeting_date',
        'time',
        'place',
        'to_consider',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'board_meeting_date' => 'date',
        'time' => 'datetime:H:i',
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
     * Scope a query to only include upcoming meetings.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('board_meeting_date', '>=', Carbon::today())
            ->orderBy('board_meeting_date', 'asc')
            ->orderBy('time', 'asc');
    }

    /**
     * Scope a query to only include past meetings.
     */
    public function scopePast($query)
    {
        return $query->where('board_meeting_date', '<', Carbon::today())
            ->orderBy('board_meeting_date', 'desc')
            ->orderBy('time', 'desc');
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
        return $this->board_meeting_date->format('M d, Y') . ' at ' .
            Carbon::parse($this->time)->format('h:i A');
    }

    /**
     * Check if meeting is today.
     */
    public function getIsTodayAttribute(): bool
    {
        return $this->board_meeting_date->isToday();
    }

    /**
     * Check if meeting is in the next 7 days.
     */
    public function getIsUpcomingAttribute(): bool
    {
        return $this->board_meeting_date->between(
            Carbon::today(),
            Carbon::today()->addDays(7)
        );
    }

    /**
     * Get days until meeting.
     */
    public function getDaysUntilAttribute(): int
    {
        return Carbon::today()->diffInDays($this->board_meeting_date, false);
    }
}
