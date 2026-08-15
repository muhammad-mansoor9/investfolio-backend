<?php

return [
    // Market Regime Configuration
    'market_regime' => [
        'enabled' => true,

        // Structural Trend: Long-term trend analysis
        'structural' => [
            'ema_periods' => [50, 100, 200],
            'mode' => 'ema_slope_and_position', // close vs EMA200, EMA100 vs EMA200
            'thresholds' => [
                'bullish' => 1.5,   // % above reference
                'bearish' => -1.5,  // % below reference
            ],
            'weight' => 40,
        ],

        // Directional Bias: Medium-term direction
        'directional' => [
            'ema_periods' => [20, 50],
            'mode' => 'ema_crossover_and_slope',
            'thresholds' => [
                'bullish' => 1.0,
                'bearish' => -1.0,
            ],
            'weight' => 35,
        ],

        // Tactical Momentum: Short-term momentum
        'tactical' => [
            'ema_periods' => [6, 20],
            'rsi_period' => 14,
            'macd_fast' => 12,
            'macd_slow' => 26,
            'macd_signal' => 9,
            'mode' => 'rsi_and_macd',
            'thresholds' => [
                'rsi_bullish' => 50,
                'rsi_bearish' => 50,
                'macd_bullish_crossover' => true,
            ],
            'weight' => 25,
        ],

        // Regime score mapping (0-100 scale)
        'score_scale' => [
            'bullish' => [70, 100],
            'neutral' => [30, 69],
            'bearish' => [0, 29],
        ],

        // Hysteresis to avoid flipping
        'hysteresis' => [
            'enable' => true,
            'strong_threshold' => 100.5,
            'weak_threshold' => 99.5,
            'confirmation_sessions' => 1,
        ],
    ],

    // Sector Rotation Configuration
    'sector_rotation' => [
        'enabled' => true,

        // Relative Strength calculation
        'relative_strength' => [
            'ema_fast_period' => 10,
            'ema_slow_period' => 30,
            'momentum_lookback' => 10,
        ],

        // Quadrant classification
        'quadrants' => [
            'rs_ratio_threshold' => 100.0,
            'rs_momentum_threshold' => 100.0,
            'hysteresis_enable' => true,
            'hysteresis_band' => 0.5, // %
        ],

        // Rotation states
        'states' => [
            'leading' => [
                'rs_ratio_min' => 100.0,
                'rs_momentum_min' => 100.0,
                'description' => 'Strong RS + Strong Momentum',
            ],
            'improving' => [
                'rs_ratio_max' => 100.0,
                'rs_momentum_min' => 100.0,
                'description' => 'Weak RS + Strong Momentum',
            ],
            'weakening' => [
                'rs_ratio_min' => 100.0,
                'rs_momentum_max' => 100.0,
                'description' => 'Strong RS + Weak Momentum',
            ],
            'lagging' => [
                'rs_ratio_max' => 100.0,
                'rs_momentum_max' => 100.0,
                'description' => 'Weak RS + Weak Momentum',
            ],
        ],

        // Weighting for sector index construction
        'weighting' => [
            'method' => 'free_float', // 'free_float' or 'equal_weight'
            'use_previous_session_weights' => true,
        ],

        // Data quality thresholds
        'data_quality' => [
            'min_eligible_stocks' => 3,
            'min_coverage_ratio' => 0.5,
        ],
    ],

    // Technical Breadth Configuration
    'technical_breadth' => [
        'enabled' => true,

        'metrics' => [
            'price_above_ema20' => ['weight' => 25, 'period' => 20],
            'price_above_ema50' => ['weight' => 20, 'period' => 50],
            'price_above_ema100' => ['weight' => 15, 'period' => 100],
            'price_above_ema200' => ['weight' => 15, 'period' => 200],
            'ema20_above_ema50' => ['weight' => 10],
            'ema50_above_ema200' => ['weight' => 10],
            'rsi_above_50' => ['weight' => 15, 'period' => 14],
            'macd_bullish' => ['weight' => 15],
            'di_plus_above_di_minus' => ['weight' => 10],
        ],

        'breadth_score_thresholds' => [
            'strong' => 70,
            'moderate' => 50,
            'weak' => 30,
        ],
    ],

    // Participation/Concentration Configuration
    'participation' => [
        'enabled' => true,

        'thresholds' => [
            'broad' => 0.85,        // Ratio of equal_weight to free_float >= 0.85
            'moderate' => 0.70,
            'concentrated' => 0.70, // < 0.70 is concentrated
        ],
    ],

    // Settlement Participation Configuration
    'settlement' => [
        'enabled' => true,

        'metrics' => [
            'above_own_baseline' => [
                'baseline_periods' => 20,
                'weight' => 50,
            ],
            'elevated_settlement_value' => [
                'baseline_periods' => 20,
                'ratio_threshold' => 1.3,
                'weight' => 30,
            ],
            'price_confirmation' => [
                'weight' => 20,
            ],
        ],

        'settlement_breadth_thresholds' => [
            'strong' => 70,
            'moderate' => 50,
            'weak' => 30,
        ],
    ],

    // FIPI/LIPI Flow Configuration
    'fipi_lipi_flow' => [
        'enabled' => true,

        'flow_windows' => [
            '1d' => 1,
            '5d' => 5,
            '20d' => 20,
        ],

        'flow_direction_thresholds' => [
            'accumulation' => 100000,      // USD
            'neutral' => [-100000, 100000],
            'distribution' => -100000,
        ],

        'flow_score_thresholds' => [
            'strong_accumulation' => 80,
            'accumulation' => 60,
            'neutral' => 50,
            'distribution' => 40,
            'strong_distribution' => 20,
        ],
    ],

    // Sector Quality Scores Configuration
    'sector_quality' => [
        'strength_score' => [
            'weights' => [
                'rs_ratio' => 30,
                'rs_momentum' => 25,
                'breadth' => 20,
                'participation' => 15,
                'settlement' => 10,
            ],
        ],

        'improvement_score' => [
            'weights' => [
                'rs_momentum_level' => 25,
                'rs_momentum_acceleration' => 20,
                'rs_ratio_slope' => 20,
                'breadth_improvement' => 15,
                'settlement_improvement' => 10,
                'distance_to_leading' => 10,
            ],
        ],

        'leadership_quality_score' => [
            'weights' => [
                'rs_ratio' => 20,
                'rs_momentum' => 20,
                'breadth' => 25,
                'breadth_trend' => 10,
                'participation' => 10,
                'settlement' => 10,
                'rotation_direction' => 5,
            ],
        ],
    ],

    // UNIFIED STOCK ANALYSIS (Four unified score dimensions)
    // NO separate uin_score, volume_score, adx_score, fipi_score columns
    // All supporting evidence lives in metadata JSONB

    'stock_analysis' => [
        // Dimension 1: Relative Leadership (35% of Stock Strength)
        'relative_leadership' => [
            'vs_kse100_weight' => 50,
            'vs_sector_weight' => 50,
            'momentum_factor' => 0.2, // 20% from momentum acceleration
        ],

        // Dimension 2: Trend Structure (30% of Stock Strength)
        // EMA structure + ADX/DI + EMA slopes
        'trend_structure' => [
            'ema_position_weight' => 50,  // Price vs EMA alignment
            'ema_alignment_weight' => 30, // EMA > EMA structure
            'adx_weight' => 20,           // Trend strength
        ],

        // Dimension 3: Momentum (20% of Stock Strength)
        // RSI + MACD + short-term behavior
        'momentum' => [
            'rsi_weight' => 50,
            'macd_weight' => 50,
        ],

        // Dimension 4: Participation / Conviction (15% of Stock Strength)
        // Volume + UIN + Sector Flow Context
        'participation' => [
            'relative_volume_weight' => 40,
            'uin_participation_weight' => 40,  // UIN details in metadata
            'sector_flow_context_weight' => 20, // Sector FIPI in metadata
        ],

        // Final Stock Strength Score composition
        'stock_strength_score' => [
            'weights' => [
                'relative_leadership' => 35,
                'trend_structure' => 30,
                'momentum' => 20,
                'participation' => 15,
            ],
        ],

        // Watch Score: Independent from Stock Strength
        // Answers: "How interesting is this for investigation NOW?"
        'watch_score' => [
            // Can emphasize emerging setups
            // Uses Stock Strength but also considers phase transitions
            'stock_strength_base_weight' => 60,
            'market_phase_adjustment' => 20,   // Early Uptrend gets boost
            'momentum_acceleration_weight' => 20, // Improving momentum gets boost
        ],

        'adx_thresholds' => [
            'weak' => 20,
            'developing' => 25,
            'meaningful' => 25,
        ],

        'rsi_interpretation' => [
            'oversold' => 30,
            'weak' => 40,
            'neutral_recovery' => 50,
            'healthy_bullish' => 65,
            'strong' => 75,
            'overbought' => 80,
        ],
    ],

    // DOW THEORY-INSPIRED MARKET PHASE CLASSIFICATION
    'market_phase' => [
        'phases' => [
            'Accumulation',      // Stabilizing from weakness
            'Early Uptrend',     // Initial strength forming
            'Advancing',         // Established participation
            'Extended',          // Stretched conditions
            'Distribution',      // Deteriorating momentum
            'Declining',         // Clear weakness
        ],

        'accumulation' => [
            'rs_deterioration_slowing' => true,
            'price_stabilizing' => true,
            'volume_improving' => true,
            'uin_improving' => true,
            'momentum_recovering' => true,
        ],

        'early_uptrend' => [
            'rs_improving' => true,
            'price_above_ema20' => true,
            'ema20_slope_positive' => true,
            'momentum_above_50' => true,
            'macd_improving' => true,
        ],

        'advancing' => [
            'rs_strong' => true,
            'price_above_ema50' => true,
            'ema_structure_bullish' => true,
            'adx_above_25' => true,
            'volume_confirming' => true,
        ],

        'extended' => [
            'rsi_above_75' => true,
            'price_far_above_ema20' => true,
            'momentum_slowing' => true,
            'rs_momentum_weakening' => true,
        ],

        'distribution' => [
            'price_relatively_high' => true,
            'longer_trend_strong' => true,
            'momentum_deteriorating' => true,
            'breadth_cooling' => true,
            'volume_without_progress' => true,
        ],

        'declining' => [
            'rs_weak' => true,
            'price_below_ema50' => true,
            'ema_structure_bearish' => true,
            'macd_negative' => true,
            'participation_weak' => true,
        ],
    ],

    // STOCK LEADERSHIP STATES (separate from market phase)
    'leadership_states' => [
        'Strong Leader',
        'Emerging Leader',
        'Confirmed Leader',
        'Sector Follower',
        'Cooling',
        'Weak',
    ],

    // Signal Age Classification
    'signal_age' => [
        'fresh' => [1, 3],
        'developing' => [4, 10],
        'established' => [11, 25],
        'mature' => [26, 1000],
    ],

    // Rotation Direction Classification
    'rotation_direction' => [
        'strengthening' => [
            'rs_ratio_delta_5_min' => 0.5,
            'rs_momentum_delta_5_min' => 0.5,
        ],
        'stable' => [
            'rs_ratio_delta_5_range' => [-0.5, 0.5],
            'rs_momentum_delta_5_range' => [-0.5, 0.5],
        ],
        'deteriorating' => [
            'rs_ratio_delta_5_max' => -0.5,
            'rs_momentum_delta_5_max' => -0.5,
        ],
    ],

    // Model Versioning
    'model_versions' => [
        'market_regime' => 'v1',
        'sector_rotation' => 'v1',
        'stock_watch' => 'v1',
    ],

    // Performance and Processing
    'performance' => [
        'batch_size' => 500,
        'cache_ttl_minutes' => 1440, // 24 hours
        'calculate_daily_at' => '17:00', // HH:MM in server timezone
    ],
];
