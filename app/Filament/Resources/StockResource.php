<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\StockResource\Pages;
use App\Models\Stock;
use App\Models\StockRescrapeQueue;
use App\Support\AdminModules;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

// Rows are owned by the stock sync scraper, so the panel never creates or deletes them.
class StockResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::MARKET;

    protected static ?string $model = Stock::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Market data';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'symbol';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter(['Sector' => $record->sector?->name, 'Name' => $record->description]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Listing')
                ->columns(2)
                ->schema([
                    TextInput::make('symbol')->disabled()->dehydrated(false),
                    Select::make('sector_id')->label('Sector')->relationship('sector', 'name')->searchable()->preload(),
                    Textarea::make('description')->label('Company name')->rows(2)->columnSpanFull(),
                    TextInput::make('year_ending')->label('Financial year ends')->maxLength(20)
                        ->datalist(['MAR', 'JUNE', 'SEP', 'DEC'])
                        ->helperText('Month the fiscal year closes; drives the overdue-quarter checks in Financial coverage.'),
                ]),
            Section::make('Shares')
                ->columns(2)
                ->schema([
                    TextInput::make('total_shares_outstanding')->label('Shares outstanding')->numeric()->minValue(0)->required(),
                    TextInput::make('free_float')->numeric()->minValue(0)->required(),
                    TextInput::make('face_value')->numeric()->minValue(0)->step('0.0001')->required(),
                    TextInput::make('market_cap')->label('Market cap')->numeric()->disabled()->dehydrated(false)
                        ->helperText('Recalculated from the latest price.'),
                ]),
            Section::make('Status')
                ->columns(2)
                ->schema([
                    Toggle::make('is_active')->label('Active')
                        ->helperText('Inactive stocks are hidden from screens and skipped by integrity checks.'),
                    Toggle::make('is_shariah')->label('Shariah compliant'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('symbol')
            ->modifyQueryUsing(fn ($query) => $query->with('sector'))
            ->columns([
                TextColumn::make('symbol')->searchable()->sortable()->weight('bold')
                    ->description(fn (Stock $record) => str($record->description)->limit(40)->toString()),
                TextColumn::make('sector.name')->label('Sector')->sortable()->toggleable()->wrap(),
                TextColumn::make('market_cap')->label('Market cap')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state) => $state ? Number::abbreviate((float) $state, maxPrecision: 1) : '—'),
                TextColumn::make('total_shares_outstanding')->label('Shares')->numeric()->sortable()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('free_float')->numeric()->sortable()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('year_ending')->label('FY end')->toggleable()->placeholder('—'),
                IconColumn::make('is_shariah')->label('Shariah')->boolean()->sortable(),
                ToggleColumn::make('is_active')->label('Active')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('sector')->relationship('sector', 'name')->searchable()->preload(),
                TernaryFilter::make('is_active')->label('Active')->default(true),
                TernaryFilter::make('is_shariah')->label('Shariah compliant'),
                TernaryFilter::make('year_ending')->label('Financial year end')->nullable()
                    ->trueLabel('Set')->falseLabel('Missing'),
            ])
            ->actions([
                Tables\Actions\Action::make('rescrape')
                    ->label('Queue rescrape')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->iconButton()
                    ->color('gray')
                    ->tooltip('Queue for rescrape')
                    ->visible(fn () => AdminModules::allows(AdminModules::QUALITY))
                    ->action(fn (Stock $record) => self::queueRescrape(collect([$record]))),
                Tables\Actions\EditAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')->label('Mark active')->icon('heroicon-o-check-circle')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => Stock::whereIn('id', $records->modelKeys())->update(['is_active' => true])),
                    Tables\Actions\BulkAction::make('deactivate')->label('Mark inactive')->icon('heroicon-o-no-symbol')->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => Stock::whereIn('id', $records->modelKeys())->update(['is_active' => false])),
                    Tables\Actions\BulkAction::make('rescrape')->label('Queue rescrape')->icon('heroicon-o-arrow-path-rounded-square')
                        ->visible(fn () => AdminModules::allows(AdminModules::QUALITY))
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => self::queueRescrape($records)),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStocks::route('/'),
            'edit' => Pages\EditStock::route('/{record}/edit'),
        ];
    }

    // Skips stocks already pending so the scraper does not fetch them twice.
    public static function queueRescrape($stocks): void
    {
        $pending = StockRescrapeQueue::whereIn('stock_id', $stocks->pluck('id')->all())->where('status', 'pending')->pluck('stock_id')->all();
        $queued = $stocks->reject(fn (Stock $stock) => in_array($stock->id, $pending, true));

        foreach ($queued as $stock) {
            StockRescrapeQueue::create([
                'stock_id' => $stock->id,
                'reason' => 'Admin: manual rescrape',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ]);
        }

        Notification::make()
            ->title($queued->count().' '.str('stock')->plural($queued->count()).' queued for rescrape')
            ->body($pending ? count($pending).' already waiting were skipped.' : null)
            ->success()
            ->send();
    }
}
