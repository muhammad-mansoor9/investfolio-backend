<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');
$response = $kernel->handle($request = Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;

$quarterCounts = DB::select("
  SELECT
    stock_id,
    COUNT(*) as quarter_count
  FROM financial_results
  WHERE eps IS NOT NULL
  GROUP BY stock_id
");

$countByQuarters = [];
foreach ($quarterCounts as $row) {
  $q = $row->quarter_count;
  if (!isset($countByQuarters[$q])) {
    $countByQuarters[$q] = 0;
  }
  $countByQuarters[$q]++;
}

ksort($countByQuarters);
echo "Distribution of quarters per stock:\n";
$excluded = 0;
$maxQ = max(array_keys($countByQuarters));
for ($i = 1; $i <= $maxQ; $i++) {
  $count = $countByQuarters[$i] ?? 0;
  printf("%2d quarters: %3d stocks\n", $i, $count);
  if ($i < 3) $excluded += $count;
}
echo "\nStocks with < 3 quarters (EXCLUDED): " . $excluded . "\n";
echo "Stocks with >= 3 quarters (ELIGIBLE): " . (array_sum($countByQuarters) - $excluded) . "\n";
echo "\nExpected expected earnings results: " . (array_sum($countByQuarters) - $excluded) . "\n";
echo "Actual expected earnings results: 340\n";
echo "Gap: " . ((array_sum($countByQuarters) - $excluded) - 340) . " (other filters)\n";
?>
