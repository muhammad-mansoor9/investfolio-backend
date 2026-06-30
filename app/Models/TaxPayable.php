<?php
namespace App\Models;

use Investfolio\InvestfolioShared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxPayable extends PgsqlModel
{
    use HasUuid;
    use HasFactory;

    protected $fillable = [
        'portfolio_id',
        'stock_id',
        'trade_id',
        'dividend_id',
        'completed_trade_id',
        'tax_type',
        'tax_amount',
        'tax_rate',
        'calculation_base',
        'tax_date',
        'due_date',
        'status',
        'paid_date',
        'paid_amount',
        'payment_reference',
        'notes'
    ];

    protected $casts = [
        'tax_date' => 'date',
        'due_date' => 'date',
        'paid_date' => 'date',
        'tax_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'calculation_base' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function portfolio()
    {
        return $this->belongsTo(BasePortfolio::class, 'portfolio_id');
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class);
    }

    public function trade()
    {
        return $this->belongsTo(Trade::class);
    }

    public function completedTrade()
    {
        return $this->belongsTo(CompletedTrade::class);
    }

    // Helper methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isPaid()
    {
        return $this->status === 'paid';
    }

    public function isOverdue()
    {
        return $this->status === 'overdue';
    }

    public function markAsPaid($amount = null, $paymentReference = null, $paidDate = null)
    {
        $this->update([
            'status' => 'paid',
            'paid_amount' => $amount ?? $this->tax_amount,
            'payment_reference' => $paymentReference,
            'paid_date' => $paidDate ?? now()->toDateString()
        ]);
    }
}
