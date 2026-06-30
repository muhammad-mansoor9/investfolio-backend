-- Create saved_valuations table for valuation calculator feature
-- Owner: postgres (DDL owner)
-- This table stores user-created fair value calculations

CREATE TABLE IF NOT EXISTS saved_valuations (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id         UUID NOT NULL,
  stock_id        UUID NOT NULL REFERENCES stocks(id),
  name            TEXT NOT NULL,          -- user gives this a name before saving
  year_label      TEXT NOT NULL,          -- e.g. "FY27E" or "FY28F"
  eps             NUMERIC(10,4) NOT NULL,
  pe              NUMERIC(8,2) NOT NULL,
  revenue_growth  NUMERIC(8,2),           -- % optional
  gross_profit    NUMERIC(8,2),           -- % optional
  dps             NUMERIC(10,4),          -- optional

  -- Calculated and stored at save time
  current_price   NUMERIC(12,4),
  sector_pe       NUMERIC(8,2),
  fair_value      NUMERIC(12,4),
  upside_pct      NUMERIC(8,2),
  outlook         TEXT,                   -- Bullish / Neutral / Bearish
  signal_score    INTEGER,                -- 0 to 4
  signals         JSONB,

  created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_saved_valuations_user_created
  ON saved_valuations (user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_saved_valuations_stock
  ON saved_valuations (stock_id);
