<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class JobHistory extends PgsqlModel
{
    public const STATUSES = ['running', 'success', 'failed', 'skipped'];

    // A run still "running" after this long died without reporting back.
    public const STALLED_AFTER_MINUTES = 120;

    protected $table = 'jobs_history';

    protected $casts = [
        'run_date' => 'date',
        'run_timestamp' => 'datetime',
        'execution_time_seconds' => 'float',
        'errors_count' => 'integer',
    ];

    public function scopeLatestPerScript(Builder $query): Builder
    {
        return $query->whereIn('id', fn ($sub) => $sub->from('jobs_history')->selectRaw('MAX(id)')->groupBy('script_name'));
    }

    public function isStalled(): bool
    {
        return $this->status === 'running'
            && $this->run_timestamp->diffInMinutes(now()) > self::STALLED_AFTER_MINUTES;
    }

    public function displayStatus(): string
    {
        return $this->isStalled() ? 'stalled' : $this->status;
    }
}
