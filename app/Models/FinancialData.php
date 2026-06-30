<?php

declare(strict_types=1);

namespace App\Models;

class FinancialData extends PgsqlModel
{
    protected $table = 'financial_data';

    protected $fillable = [
        'symbol',
        'statement',
        'type',
        'table_name',
        'identifier',
        'header',
        'value',
        'row_order',
        'col_order',
    ];

    protected $casts = [
        'row_order' => 'integer',
        'col_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the stock that owns this financial data
     */
    public function stock()
    {
        return $this->belongsTo(Stock::class, 'symbol', 'symbol');
    }

    /**
     * Scope: Filter by statement type
     */
    public function scopeStatement($query, $statement)
    {
        return $query->where('statement', $statement);
    }

    /**
     * Scope: Filter by type (e.g., ANNUAL, QUARTERLY)
     */
    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Filter by identifier (metric name)
     */
    public function scopeIdentifier($query, $identifier)
    {
        return $query->where('identifier', $identifier);
    }

    /**
     * Scope: Filter by header (e.g., LTM, year)
     */
    public function scopeHeader($query, $header)
    {
        return $query->where('header', $header);
    }

    /**
     * Scope: Filter by table name
     */
    public function scopeTableName($query, $tableName)
    {
        return $query->where('table_name', $tableName);
    }

    /**
     * Scope: Get latest data for a symbol
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Get numeric value
     */
    public function getNumericValue()
    {
        return $this->value ? (float) $this->value : null;
    }
}
