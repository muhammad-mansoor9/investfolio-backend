@php
    use App\Filament\Pages\FinancialIntegrity;

    $report = $this->report;
    $summary = $report['summary'];
    $copy = FinancialIntegrity::SOURCES[$source];
    $tiles = [
        ['filter' => 'COMPLETE', 'label' => 'Complete series', 'value' => $summary['complete'], 'tone' => 'psx-ok'],
        ['filter' => 'GAPS', 'label' => 'Gaps in series', 'value' => $summary['gaps'], 'hint' => "{$summary['gapPeriods']} quarters missing", 'tone' => 'psx-bad'],
        ['filter' => 'OVERDUE', 'label' => 'Latest overdue', 'value' => $summary['overdue'], 'hint' => "{$summary['overduePeriods']} quarters late", 'tone' => 'psx-warn'],
        ['filter' => 'THIN', 'label' => 'Thin periods', 'value' => $summary['thin'], 'tone' => 'psx-warn'],
        ['filter' => 'NO_DATA', 'label' => 'No data', 'value' => $summary['noData'], 'tone' => ''],
        ['filter' => 'anomalies', 'label' => 'Label issues', 'value' => $summary['withAnomalies'], 'tone' => 'psx-warn'],
    ];
@endphp

<x-filament-panels::page>
    <x-filament::tabs>
        @foreach (FinancialIntegrity::SOURCES as $key => $meta)
            <x-filament::tabs.item :active="$source === $key" wire:click="setSource('{{ $key }}')">
                {{ $meta['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="psx-tiles psx-tiles-6">
        @foreach ($tiles as $tile)
            <button type="button" wire:click="toggleFilter('{{ $tile['filter'] }}')" aria-pressed="{{ $filter === $tile['filter'] ? 'true' : 'false' }}"
                @class(['psx-tile psx-tile-button', 'is-active' => $filter === $tile['filter']])>
                <span class="psx-tile-label">{{ $tile['label'] }}</span>
                <span @class(['psx-tile-number', $tile['tone'] => $tile['value'] > 0])>{{ number_format($tile['value']) }}</span>
                @isset($tile['hint'])
                    <span class="psx-muted psx-small">{{ $tile['hint'] }}</span>
                @endisset
            </button>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">{{ $copy['label'] }}</x-slot>
        <x-slot name="description">
            {{ $copy['description'] }} {{ $summary['checkedStocks'] }} active stocks checked; latest ended quarter {{ $report['latestEndedQuarter'] }}.
        </x-slot>
        <x-slot name="headerEnd">
            <div class="psx-row">
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search symbol" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="filter">
                        <option value="attention">Needs attention</option>
                        <option value="all">All stocks</option>
                        @foreach (FinancialIntegrity::STATUSES as $status => $meta)
                            <option value="{{ $status }}">{{ $meta['label'] }}</option>
                        @endforeach
                        <option value="anomalies">Label issues</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </x-slot>

        <ul class="psx-legend">
            @foreach (FinancialIntegrity::PERIODS as $status => $label)
                <li><span class="psx-q psx-q-{{ $status }}"></span>{{ $label }}</li>
            @endforeach
        </ul>

        <div class="psx-table-wrap" wire:loading.class="psx-loading">
            <table class="psx-table">
                <thead>
                    <tr>
                        <th>Stock</th>
                        <th>Coverage</th>
                        <th>
                            <div class="psx-timeline">
                                @foreach ($this->timeline as $quarter)
                                    <span class="psx-q-axis">{{ str_starts_with($quarter, 'Mar') ? '’'.substr($quarter, -2) : '' }}</span>
                                @endforeach
                            </div>
                        </th>
                        <th>Issues</th>
                        <th>Status</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $stock)
                        @php
                            $byPeriod = array_column($stock['series'], null, 'period');
                            $issues = array_filter([
                                'Missing' => $stock['gaps'],
                                "Overdue (due by now: {$stock['expectedLatest']})" => $stock['overdue'],
                                'Thin' => $stock['thin'],
                            ]);
                        @endphp
                        <tr wire:key="{{ $source }}-{{ $stock['stockId'] }}">
                            <td>
                                <p class="psx-strong">{{ $stock['symbol'] }}</p>
                                @if ($stock['sector'])
                                    <p class="psx-muted psx-small psx-truncate">{{ $stock['sector'] }}</p>
                                @endif
                            </td>
                            <td class="psx-num psx-small">
                                @if ($stock['firstPeriod'])
                                    <p>{{ $stock['firstPeriod'] }} → {{ $stock['latestPeriod'] }}</p>
                                    <p class="psx-muted">{{ $stock['available'] }} {{ \Illuminate\Support\Str::plural('quarter', $stock['available']) }}</p>
                                @else
                                    <span class="psx-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="psx-timeline" role="img" aria-label="{{ $stock['symbol'] }} quarterly timeline">
                                    @foreach ($this->timeline as $quarter)
                                        @php $period = $byPeriod[$quarter] ?? null; @endphp
                                        <span
                                            @class(['psx-q', 'psx-q-'.($period['status'] ?? 'none')])
                                            title="{{ $quarter }}: {{ $period ? FinancialIntegrity::PERIODS[$period['status']].($period['rows'] ? ' · '.number_format($period['rows']).' '.$copy['rows'] : '') : 'before first period' }}"
                                        ></span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="psx-small psx-issues">
                                @if (empty($issues) && empty($stock['anomalies']))
                                    <span class="psx-muted">{{ $stock['status'] === 'NO_DATA' ? 'No quarterly data loaded' : '—' }}</span>
                                @else
                                    <ul>
                                        @foreach ($issues as $label => $periods)
                                            <li>
                                                <span class="psx-muted">{{ $label }}:</span>
                                                <span class="psx-num" title="{{ implode(', ', $periods) }}">
                                                    {{ implode(', ', array_slice($periods, 0, 5)) }}@if (count($periods) > 5) <span class="psx-muted">+{{ count($periods) - 5 }} more</span>@endif
                                                </span>
                                            </li>
                                        @endforeach
                                        @foreach ($stock['anomalies'] as $anomaly)
                                            <li class="psx-muted">{{ $anomaly }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="psx-status">
                                <x-filament::badge :color="FinancialIntegrity::STATUSES[$stock['status']]['color']" size="sm">
                                    {{ FinancialIntegrity::STATUSES[$stock['status']]['label'] }}
                                </x-filament::badge>
                            </td>
                            <td>
                                @if ($stock['status'] !== 'COMPLETE')
                                    <x-filament::icon-button
                                        icon="heroicon-m-arrow-path-rounded-square"
                                        color="gray"
                                        size="sm"
                                        label="Queue {{ $stock['symbol'] }} for rescrape"
                                        tooltip="Queue for rescrape"
                                        wire:click="queueRescrape('{{ $stock['stockId'] }}', '{{ $stock['symbol'] }}')"
                                    />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="psx-empty">
                                {{ $filter === 'attention' && $search === '' ? 'Every stock has a complete quarterly series.' : 'No stocks match these filters.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="psx-note">
            Showing {{ count($this->rows) }} of {{ $summary['checkedStocks'] }} stocks. The timeline covers the last
            {{ FinancialIntegrity::TIMELINE_QUARTERS }} quarters; older gaps are listed under Issues.
        </p>
    </x-filament::section>
</x-filament-panels::page>
