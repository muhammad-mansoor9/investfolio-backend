<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomJob extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'job_type',
        'scheduled_for',
        'executed_at',
        'status',
        'error_message',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'executed_at' => 'datetime',
    ];

    /**
     * Scope a query to only include pending jobs.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include completed jobs.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include failed jobs.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include jobs that are due to be executed.
     */
    public function scopeDue($query)
    {
        return $query->where('scheduled_for', '<=', now())
            ->where('status', 'pending');
    }

    /**
     * Mark the job as completed.
     */
    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'executed_at' => now(),
        ]);
    }

    /**
     * Mark the job as failed with an error message.
     */
    public function markAsFailed(string $errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'executed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }
}
