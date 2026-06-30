<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeductionSlab extends PgsqlModel
{
    use HasFactory;

    protected $fillable = [
        'min_share_price',
        'max_share_price',
        'charge_per_share',
    ];

    protected $casts = [
        'charge_per_share' => 'decimal:2',
    ];

    public function exchangeMarket()
    {
        return $this->belongsTo(ExchangeMarket::class, 'exchange_id');
    }

    /**
     * Get the default system slabs.
     */
    public static function getDefaultSlabs()
    {
        return [
            [
                'from' => 0.01,
                'to' => 9.99,
                'type' => 'per_share',
                'commission' => 0.06
            ],
            [
                'from' => 10,
                'to' => 24.99,
                'type' => 'per_share',
                'commission' => 0.08
            ],
            [
                'from' => 25,
                'to' => 49.99,
                'type' => 'per_share',
                'commission' => 0.12
            ],
            [
                'from' => 50,
                'to' => 99.99,
                'type' => 'per_share',
                'commission' => 0.15
            ],
            [
                'from' => 100,
                'to' => null, // Any
                'type' => 'percentage',
                'commission' => 0.18
            ]
        ];
    }

    /**
     * Create a default system slab.
     */
    public static function createDefaultSlab($userId = null, $portfolioId = null)
    {
        return self::create([
            'name' => 'Default Commission Slab',
            'description' => 'System generated default commission slab',
            'is_system' => true,
            'slabs' => self::getDefaultSlabs(),
            'user_id' => $userId,
            'portfolio_id' => $portfolioId,
        ]);
    }
}
