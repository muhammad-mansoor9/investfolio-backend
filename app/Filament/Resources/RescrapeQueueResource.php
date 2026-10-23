<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\RescrapeQueueResource\Pages;
use App\Models\StockRescrapeQueue;
use App\Support\AdminModules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class RescrapeQueueResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::QUALITY;

    protected static ?string $model = StockRescrapeQueue::class;

    protected static ?string $modelLabel = 'rescrape request';

    protected static ?string $slug = 'rescrape-queue';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Data quality';

    protected static ?string $navigationLabel = 'Rescrape queue';

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $pending = StockRescrapeQueue::where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for the scraper';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('stock_id')
                ->label('Stock')
                ->relationship('stock', 'symbol', fn ($query) => $query->where('is_active', true)->orderBy('symbol'))
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('max_attempts')->numeric()->minValue(1)->maxValue(10)->default(3)->required(),
            Textarea::make('reason')->rows(3)->columnSpanFull()
                ->placeholder('e.g. Missing Sep \'26 quarterly results'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('stock.symbol')->label('Stock')->searchable()->sortable()->weight('medium')
                    ->placeholder('Unknown stock'),
                TextColumn::make('reason')->limit(70)->wrap()->tooltip(fn (StockRescrapeQueue $record) => $record->reason)->placeholder('—'),
                TextColumn::make('status')->badge()->sortable()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'warning',
                        'done', 'completed', 'success' => 'success',
                        'failed', 'error' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('attempts')->label('Attempts')
                    ->formatStateUsing(fn (StockRescrapeQueue $record) => "{$record->attempts} / {$record->max_attempts}")
                    ->color(fn (StockRescrapeQueue $record) => $record->attempts >= $record->max_attempts ? 'danger' : 'gray'),
                TextColumn::make('created_at')->label('Queued')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->dateTimeTooltip()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn () => StockRescrapeQueue::query()->distinct()->orderBy('status')->pluck('status')
                        ->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->iconButton()
                    ->tooltip('Reset to pending with no attempts')
                    ->visible(fn (StockRescrapeQueue $record) => $record->status !== 'pending' || $record->attempts > 0)
                    ->action(fn (StockRescrapeQueue $record) => self::reset(collect([$record]))),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('retry')
                        ->label('Reset to pending')
                        ->icon('heroicon-o-arrow-path')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => self::reset($records)),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing waiting to be rescraped')
            ->emptyStateDescription('Queue a stock here, from the Stocks list or from Financial coverage.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRescrapeRequests::route('/'),
        ];
    }

    private static function reset($records): void
    {
        StockRescrapeQueue::whereIn('id', $records->pluck('id'))->update([
            'status' => 'pending',
            'attempts' => 0,
            'updated_at' => now(),
        ]);
    }
}
