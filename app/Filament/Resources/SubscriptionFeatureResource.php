<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\SubscriptionFeatureResource\Pages;
use App\Models\SubscriptionFeature;
use App\Support\AdminModules;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SubscriptionFeatureResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::BILLING;

    protected static ?string $model = SubscriptionFeature::class;

    protected static ?string $modelLabel = 'feature';

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Features';

    protected static ?int $navigationSort = 2;

    // Must match psx-react lib/billing/feature-icons.tsx; add an icon there before adding it here.
    public const ICONS = [
        'activity', 'bar-chart-3', 'bell', 'book-open', 'bot', 'calendar', 'candlestick-chart', 'check', 'database',
        'download', 'filter', 'globe', 'life-buoy', 'line-chart', 'lock', 'pie-chart', 'plug', 'shield', 'sparkles',
        'star', 'target', 'trending-up', 'users', 'zap',
    ];

    public static function canDelete(Model $record): bool
    {
        return $record->relationLoaded('planFeatures') ? $record->planFeatures->isEmpty() : ! $record->planFeatures()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                TextInput::make('name')->required()->maxLength(150),
                TextInput::make('feature_key')->label('Key')->required()->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->disabled(fn (?SubscriptionFeature $record) => $record !== null)
                    ->helperText('snake_case; the code gates on it, so it is fixed once created.'),
                Textarea::make('description')->rows(2)->columnSpanFull(),
                Select::make('icon')->options(array_combine(self::ICONS, self::ICONS))->default('check')->required()->searchable(),
                TextInput::make('sort_order')->numeric()->default(0)->required(),
                Toggle::make('track_usage')->label('Count monthly usage')
                    ->helperText('Needed for a plan to set a usage limit on this feature.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('planFeatures.plan'))
            ->columns([
                TextColumn::make('name')->weight('medium')->searchable()->description(fn (SubscriptionFeature $record) => $record->description),
                TextColumn::make('feature_key')->label('Key')->fontFamily('mono')->copyable()->searchable(),
                TextColumn::make('icon')->badge()->color('gray'),
                TextColumn::make('plans')->label('In plans')
                    ->state(fn (SubscriptionFeature $record) => $record->planFeatures->map(fn ($row) => $row->plan?->name)->filter()->implode(', '))
                    ->placeholder('None'),
                IconColumn::make('track_usage')->label('Usage tracked')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionFeatures::route('/'),
            'create' => Pages\CreateSubscriptionFeature::route('/create'),
            'edit' => Pages\EditSubscriptionFeature::route('/{record}/edit'),
        ];
    }
}
