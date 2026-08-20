<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioTopic extends PgsqlModel
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'id',
        'portfolio_id',
        'stock_id',
        'topic_name',
    ];

    protected $casts = [
        'id' => 'string',
        'portfolio_id' => 'string',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(BasePortfolio::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Generate a topic name based on portfolio and stock.
     *
     * @param string $portfolioId
     * @param int $stockId
     * @return string
     */
    public static function generateTopicName(string $portfolioId, int $stockId): string
    {
        return "portfolio_{$portfolioId}_stock_{$stockId}";
    }
}
// Sync marker: 2026-08-20 17:39:39
