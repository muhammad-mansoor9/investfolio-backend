@php
    use App\Filament\Pages\DailyIntegrity;
    use Carbon\Carbon;

    $report = $this->report;
    $summary = $report['summary'];
    $day = fn (string $date, string $format = 'j M Y') => Carbon::parse($date)->format($format);
@endphp

<x-filament-panels::page>
    <section class="psx-stack">
        <div>
            <h2 class="psx-heading">Dataset freshness</h2>
            <p class="psx-muted">
                Latest load per dataset and trading days it has never covered since it started.
                @if ($report['latestTradingDate'])
                    Latest trading day: <strong>{{ $day($report['latestTradingDate']) }}</strong>.
                @endif
            </p>
        </div>

        <div class="psx-tiles psx-tiles-5">
            @foreach (DailyIntegrity::DATASETS as $key => $label)
                @php $overview = $report['datasets'][$key]; @endphp
                <div class="psx-tile">
                    <p class="psx-tile-label">{{ $label }}</p>
                    <div class="psx-row">
                        <span class="psx-tile-value">{{ $overview['latestDate'] ? $day($overview['latestDate']) : 'No data' }}</span>
                        @if ($overview['upToDate'])
                            <x-filament::badge color="success" icon="heroicon-m-check-circle" size="sm">Current</x-filament::badge>
                        @else
                            <x-filament::badge color="warning" icon="heroicon-m-clock" size="sm">Behind</x-filament::badge>
                        @endif
                    </div>
                    <div class="psx-tile-foot">
                        @if ($overview['firstDate'])
                            <p>Since {{ $day($overview['firstDate']) }}</p>
                        @endif
                        @if ($overview['missingDays'] === 0)
                            <p class="psx-ok">No missing trading days</p>
                        @else
                            <details class="psx-details">
                                <summary class="psx-bad">
                                    {{ $overview['missingDays'] }} trading {{ \Illuminate\Support\Str::plural('day', $overview['missingDays']) }} never loaded
                                </summary>
                                <ul class="psx-date-list">
                                    @foreach ($overview['recentMissing'] as $date)
                                        <li>{{ $day($date, 'D j M y') }}</li>
                                    @endforeach
                                </ul>
                                @if ($overview['missingDays'] > count($overview['recentMissing']))
                                    <p>and {{ $overview['missingDays'] - count($overview['recentMissing']) }} earlier</p>
                                @endif
                            </details>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <x-filament::section>
        <x-slot name="heading">Daily coverage</x-slot>
        <x-slot name="description">Every trading day should have all five datasets.</x-slot>
        <x-slot name="headerEnd">
            <div class="psx-row psx-gap-lg">
                <div class="psx-row">
                    <x-filament::icon-button icon="heroicon-m-chevron-left" color="gray" label="Previous month" wire:click="shiftMonth(-1)" />
                    <span class="psx-month">{{ $this->monthLabel() }}</span>
                    <x-filament::icon-button icon="heroicon-m-chevron-right" color="gray" label="Next month" wire:click="shiftMonth(1)" :disabled="$this->isCurrentMonth()" />
                </div>
                <label class="psx-row psx-toggle">
                    <x-filament::input.checkbox wire:model.live="issuesOnly" />
                    <span>Issues only</span>
                </label>
            </div>
        </x-slot>

        <dl class="psx-figures">
            <div><dt>Trading days</dt><dd>{{ $summary['tradingDays'] }}</dd></div>
            <div><dt>Complete</dt><dd class="psx-ok">{{ $summary['completeDays'] }}</dd></div>
            <div><dt>With issues</dt><dd @class(['psx-bad' => $summary['issueDays'] > 0])>{{ $summary['issueDays'] }}</dd></div>
            @if ($summary['pendingDays'] > 0)
                <div><dt>Loading</dt><dd>{{ $summary['pendingDays'] }}</dd></div>
            @endif
            @if ($summary['closedWeekdays'] > 0)
                <div><dt>Weekday closures</dt><dd>{{ $summary['closedWeekdays'] }}</dd></div>
            @endif
        </dl>

        <div class="psx-table-wrap" wire:loading.class="psx-loading">
            <table class="psx-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        @foreach (DailyIntegrity::DATASETS as $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        @if ($row['datasets'] === null)
                            <tr class="psx-closed">
                                <td>{{ $day($row['date'], 'D, j M') }}</td>
                                <td colspan="{{ count(DailyIntegrity::DATASETS) + 1 }}">
                                    {{ $row['status'] === 'weekend' ? 'Weekend' : 'Market closed (no prices or index data)' }}
                                </td>
                            </tr>
                        @else
                            <tr @class(['psx-issue-row' => $row['status'] === 'issues'])>
                                <td class="psx-num">{{ $day($row['date'], 'D, j M') }}</td>
                                <td>
                                    @switch($row['status'])
                                        @case('complete')
                                            <x-filament::badge color="success" icon="heroicon-m-check-circle" size="sm">Complete</x-filament::badge>
                                            @break
                                        @case('issues')
                                            <x-filament::badge color="danger" icon="heroicon-m-exclamation-triangle" size="sm">Issues</x-filament::badge>
                                            @break
                                        @default
                                            <x-filament::badge color="gray" icon="heroicon-m-clock" size="sm">Loading</x-filament::badge>
                                    @endswitch
                                </td>
                                @foreach (array_keys(DailyIntegrity::DATASETS) as $key)
                                    @php $cell = $row['datasets'][$key]; @endphp
                                    <td>
                                        @switch($cell['status'])
                                            @case('untracked')
                                                <span class="psx-muted">Not tracked yet</span>
                                                @break
                                            @case('pending')
                                                <span class="psx-muted">Not loaded yet</span>
                                                @break
                                            @case('missing')
                                                <span class="psx-bad psx-strong">Missing</span>
                                                @break
                                            @case('partial')
                                                <span class="psx-warn"
                                                    title="{{ $cell['missingSymbols'] ? 'Priced but missing: '.implode(', ', $cell['missingSymbols']) : 'Well below this month\'s typical count' }}">
                                                    {{ number_format($cell['count']) }}
                                                    <strong>{{ $cell['missingSymbols'] ? '−'.count($cell['missingSymbols']) : 'low' }}</strong>
                                                </span>
                                                @break
                                            @default
                                                <span class="psx-num"><span class="psx-dot psx-dot-ok"></span>{{ number_format($cell['count']) }}</span>
                                        @endswitch
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ count(DailyIntegrity::DATASETS) + 2 }}" class="psx-empty">
                                {{ $issuesOnly ? 'No trading day this month has missing data.' : 'No days to show for this month yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($summary['tradingDays'] > 0)
                    <tfoot>
                        <tr>
                            <td colspan="2">Month total</td>
                            @foreach (array_keys(DailyIntegrity::DATASETS) as $key)
                                @php
                                    $month = $summary['datasets'][$key];
                                    $parts = array_filter([
                                        $month['missingDays'] ? "{$month['missingDays']} missing" : null,
                                        $month['partialDays'] ? "{$month['partialDays']} partial" : null,
                                    ]);
                                @endphp
                                <td>{{ $parts ? implode(', ', $parts).' '.\Illuminate\Support\Str::plural('day', $month['missingDays'] + $month['partialDays']) : 'All days complete' }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @foreach (DailyIntegrity::DATASETS as $key => $label)
            @if (! empty($report['uncovered'][$key]))
                <p class="psx-note">
                    <strong>Not carried by the {{ $label }} feed this month:</strong>
                    {{ implode(', ', $report['uncovered'][$key]) }}. These stocks are priced but never had a row, so they are excluded from the daily checks above.
                </p>
            @endif
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
