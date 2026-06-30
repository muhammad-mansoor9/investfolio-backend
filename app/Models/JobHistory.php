<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobHistory extends PgsqlModel
{
    protected $fillable = [
        'job_name', 'status', 'started_at', 'finished_at', 'meta_data', 'error_message'
    ];

    protected $casts = [
        'meta_data' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Get the announcement associated with this job history.
     */
    public function announcement()
    {
        if (isset($this->meta_data['announcement_id'])) {
            return $this->belongsTo(Announcement::class, 'meta_data->announcement_id');
        }
        return null;
    }

    /**
     * Scope a query to only include successful jobs.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope a query to only include failed jobs.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'error');
    }

    /**
     * Scope a query to only include jobs of a specific type.
     */
    public function scopeOfType($query, $jobName)
    {
        return $query->where('job_name', $jobName);
    }
}
