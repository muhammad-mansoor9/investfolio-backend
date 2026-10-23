<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\JobRunResource\Pages;
use App\Models\JobHistory;
use App\Support\AdminModules;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class JobRunResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::QUALITY;

    protected static ?string $model = JobHistory::class;

    protected static ?string $modelLabel = 'job run';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Data quality';

    protected static ?string $navigationLabel = 'Job runs';

    protected static ?int $navigationSort = 3;

    public const STATUS_COLORS = [
        'success' => 'success',
        'failed' => 'danger',
        'running' => 'info',
        'stalled' => 'warning',
        'skipped' => 'gray',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = JobHistory::where('status', 'failed')->where('run_timestamp', '>=', now()->subDay())->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Failed runs in the last 24 hours';
    }

    public static function formatDuration(?float $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return $seconds < 60
            ? number_format($seconds, 1).' s'
            : intdiv((int) $seconds, 60).' m '.((int) $seconds % 60).' s';
    }

    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->badge()
            ->state(fn (JobHistory $record) => $record->displayStatus())
            ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray')
            ->formatStateUsing(fn (string $state) => ucfirst($state))
            ->tooltip(fn (JobHistory $record) => $record->isStalled()
                ? 'Still marked running after '.JobHistory::STALLED_AFTER_MINUTES.' minutes; the run probably died'
                : null);
    }

    public static function latestRunColumns(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('script_name')->label('Script')->weight('medium')->searchable(),
                self::statusColumn(),
                TextColumn::make('run_timestamp')->label('Last run')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('execution_time_seconds')->label('Duration')
                    ->formatStateUsing(fn ($state) => self::formatDuration($state))->placeholder('—'),
                TextColumn::make('success_rate')->label('Success (30 days)')
                    ->state(function (JobHistory $record) {
                        $runs = self::recentOutcomes()->get($record->script_name);

                        return $runs ? round($runs->ok / $runs->total * 100).'% of '.$runs->total : null;
                    })
                    ->placeholder('No runs')
                    ->color(fn (?string $state) => $state === null ? 'gray' : ((int) $state >= 90 ? 'success' : ((int) $state >= 60 ? 'warning' : 'danger'))),
                TextColumn::make('exception_message')->label('Last error')->limit(60)
                    ->tooltip(fn (JobHistory $record) => $record->exception_message)->placeholder('—')->wrap(),
            ])
            ->recordUrl(fn (JobHistory $record) => self::getUrl('index', ['tableFilters' => ['script_name' => ['value' => $record->script_name]]]));
    }

    // Memoized per request so the status table runs one query instead of one per script.
    private static function recentOutcomes(): \Illuminate\Support\Collection
    {
        $attributes = request()->attributes;

        if (! $attributes->has('jobs.recent_outcomes')) {
            $attributes->set('jobs.recent_outcomes', DB::table('jobs_history')
                ->where('run_timestamp', '>=', now()->subDays(30))
                ->whereIn('status', ['success', 'failed'])
                ->groupBy('script_name')
                ->selectRaw("script_name, COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'success') AS ok")
                ->get()
                ->keyBy('script_name'));
        }

        return $attributes->get('jobs.recent_outcomes');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('run_timestamp', 'desc')
            ->columns([
                TextColumn::make('script_name')->label('Script')->searchable()->sortable()->weight('medium'),
                self::statusColumn(),
                TextColumn::make('run_timestamp')->label('Started')->dateTime('d M Y, H:i')->sortable()
                    ->description(fn (JobHistory $record) => $record->run_timestamp->diffForHumans()),
                TextColumn::make('execution_time_seconds')->label('Duration')->sortable()
                    ->formatStateUsing(fn ($state) => self::formatDuration($state))->placeholder('—'),
                TextColumn::make('errors_count')->label('Errors')->numeric()->sortable()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('exception_message')->label('Error')->limit(80)->wrap()->placeholder('—')
                    ->tooltip(fn (JobHistory $record) => $record->exception_message),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(JobHistory::STATUSES, array_map('ucfirst', JobHistory::STATUSES))),
                SelectFilter::make('script_name')->label('Script')->searchable()
                    ->options(fn () => JobHistory::query()->distinct()->orderBy('script_name')->pluck('script_name', 'script_name')->all()),
                Filter::make('stalled')->label('Stalled runs only')->toggle()
                    ->query(fn (Builder $query) => self::stalled($query)),
                Filter::make('run_date')
                    ->form([
                        DatePicker::make('from')->label('Ran from'),
                        DatePicker::make('until')->label('Ran until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('run_date', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('run_date', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'From '.$data['from'] : null,
                        ($data['until'] ?? null) ? 'Until '.$data['until'] : null,
                    ])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\Action::make('markFailed')
                    ->label('Mark as failed')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->iconButton()
                    ->visible(fn (JobHistory $record) => $record->isStalled())
                    ->requiresConfirmation()
                    ->modalDescription('The run never reported back. Marking it failed keeps it from looking like it is still in progress.')
                    ->action(fn (JobHistory $record) => self::markFailed(collect([$record]))),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('markFailed')
                        ->label('Mark stalled runs as failed')
                        ->icon('heroicon-o-x-circle')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => self::markFailed($records->filter->isStalled())),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Run')
                ->columns(['md' => 3])
                ->schema([
                    TextEntry::make('script_name')->label('Script'),
                    TextEntry::make('status')->badge()
                        ->state(fn (JobHistory $record) => $record->displayStatus())
                        ->formatStateUsing(fn (string $state) => ucfirst($state))
                        ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                    TextEntry::make('errors_count')->label('Errors')->numeric(),
                    TextEntry::make('run_date')->date('D j M Y'),
                    TextEntry::make('run_timestamp')->label('Started')->dateTime('d M Y, H:i:s'),
                    TextEntry::make('execution_time_seconds')->label('Duration')
                        ->formatStateUsing(fn ($state) => self::formatDuration($state))->placeholder('—'),
                ]),
            Section::make('Exception')
                ->visible(fn (JobHistory $record) => filled($record->exception_message) || filled($record->exception_stacktrace))
                ->schema([
                    TextEntry::make('exception_message')->label('Message')->placeholder('—')->copyable(),
                    TextEntry::make('exception_stacktrace')->label('Stack trace')->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => new HtmlString('<pre class="psx-pre">'.e($state).'</pre>')),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJobRuns::route('/'),
            'view' => Pages\ViewJobRun::route('/{record}'),
        ];
    }

    private static function stalled(Builder $query): Builder
    {
        return $query->where('status', 'running')->where('run_timestamp', '<', now()->subMinutes(JobHistory::STALLED_AFTER_MINUTES));
    }

    private static function markFailed($records): void
    {
        $ids = $records->pluck('id');

        JobHistory::whereIn('id', $ids)->where('status', 'running')->update([
            'status' => 'failed',
            'exception_message' => 'Marked failed from the admin panel: the run never reported a result.',
            'updated_at' => now(),
        ]);

        Notification::make()->title($ids->count().' '.str('run')->plural($ids->count()).' marked as failed')->success()->send();
    }
}
