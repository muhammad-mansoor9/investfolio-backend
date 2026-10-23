<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\PaddleWebhookEventResource\Pages;
use App\Models\PaddleWebhookEvent;
use App\Support\AdminModules;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

// Read-only: events are replayed by Paddle or /billing/sync, never from here.
class PaddleWebhookEventResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::BILLING;

    protected static ?string $model = PaddleWebhookEvent::class;

    protected static ?string $modelLabel = 'Paddle event';

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Paddle events';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $failed = PaddleWebhookEvent::where('status', 'failed')->where('created_at', '>=', now()->subWeek())->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'processed' => 'success',
            'failed' => 'danger',
            'ignored', 'skipped' => 'gray',
            default => 'info',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('event_type')->label('Event')->weight('medium')->searchable()
                    ->description(fn (PaddleWebhookEvent $record) => $record->event_id),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('error')->limit(70)->wrap()->placeholder('—')->color('danger')
                    ->tooltip(fn (PaddleWebhookEvent $record) => $record->error),
                TextColumn::make('occurred_at')->label('Occurred')->dateTime('d M Y, H:i')->sortable()
                    ->description(fn (PaddleWebhookEvent $record) => $record->occurred_at?->diffForHumans()),
                TextColumn::make('processed_at')->label('Processed')->since()->dateTimeTooltip()->placeholder('Not yet')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn () => PaddleWebhookEvent::query()->distinct()->orderBy('status')->pluck('status')
                        ->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all()),
                SelectFilter::make('event_type')->label('Event')->searchable()
                    ->options(fn () => PaddleWebhookEvent::query()->distinct()->orderBy('event_type')->pluck('event_type', 'event_type')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
            ])
            ->emptyStateHeading('No webhooks received yet')
            ->emptyStateDescription('Paddle posts subscription and transaction events to /api/paddle/webhook.')
            ->emptyStateIcon('heroicon-o-bolt');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Event')
                ->columns(['md' => 3])
                ->schema([
                    TextEntry::make('event_type')->label('Type'),
                    TextEntry::make('event_id')->label('Event ID')->copyable()->fontFamily('mono'),
                    TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => ucfirst($state))
                        ->color(fn (string $state) => self::statusColor($state)),
                    TextEntry::make('occurred_at')->dateTime('d M Y, H:i:s'),
                    TextEntry::make('created_at')->label('Received')->dateTime('d M Y, H:i:s'),
                    TextEntry::make('processed_at')->dateTime('d M Y, H:i:s')->placeholder('Not yet'),
                    TextEntry::make('error')->columnSpanFull()->color('danger')->placeholder('—')
                        ->visible(fn (PaddleWebhookEvent $record) => filled($record->error)),
                ]),
            Section::make('Payload')
                ->collapsible()
                ->schema([
                    TextEntry::make('payload')->hiddenLabel()
                        ->formatStateUsing(fn (PaddleWebhookEvent $record) => new HtmlString(
                            '<pre class="psx-pre">'.e(json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre>'
                        )),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaddleWebhookEvents::route('/'),
            'view' => Pages\ViewPaddleWebhookEvent::route('/{record}'),
        ];
    }
}
