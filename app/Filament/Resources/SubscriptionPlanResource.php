<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Filament\Resources\SubscriptionPlanResource\RelationManagers\FeaturesRelationManager;
use App\Filament\Resources\SubscriptionPlanResource\RelationManagers\PricesRelationManager;
use App\Models\SubscriptionPlan;
use App\Support\AdminModules;
use Filament\Forms\Components\Section;
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
use Illuminate\Support\Str;

class SubscriptionPlanResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::BILLING;

    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $modelLabel = 'plan';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Plans';

    protected static ?int $navigationSort = 1;

    // Free and canceled users fall back to the default plan, so it and any plan with subscribers must stay.
    public static function canDelete(Model $record): bool
    {
        return ! $record->is_default && ! $record->subscriptions()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Section::make('Plan')
                    ->columnSpan(['lg' => 2])
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(150)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set, ?SubscriptionPlan $record) => $record ? null : $set('slug', Str::slug($state))),
                        TextInput::make('slug')->required()->maxLength(100)->unique(ignoreRecord: true)
                            ->disabled(fn (?SubscriptionPlan $record) => $record !== null)
                            ->helperText('Used by the frontend and Paddle sync; fixed once created.'),
                        Textarea::make('description')->rows(3)->columnSpanFull(),
                        TextInput::make('trial_days')->numeric()->minValue(0)->default(0)->required(),
                        TextInput::make('sort_order')->numeric()->default(0)->required(),
                    ]),
                Section::make('Visibility')
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Toggle::make('is_active')->label('Available for purchase')->default(true),
                        Toggle::make('is_featured')->label('Highlighted on the pricing page'),
                        Toggle::make('is_default')->label('Default (free) plan')->disabled()
                            ->helperText('New and canceled users land on the default plan. Change it in the database only.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['subscriptions', 'prices', 'planFeatures']))
            ->columns([
                TextColumn::make('name')->weight('medium')->searchable()->description(fn (SubscriptionPlan $record) => $record->slug),
                TextColumn::make('kind')->label('Type')->badge()
                    ->state(fn (SubscriptionPlan $record) => $record->is_default ? 'Default' : 'Paid')
                    ->color(fn (string $state) => $state === 'Default' ? 'gray' : 'primary'),
                TextColumn::make('subscriptions_count')->label('Subscribers')->numeric()->alignEnd(),
                TextColumn::make('prices_count')->label('Prices')->numeric()->alignEnd(),
                TextColumn::make('plan_features_count')->label('Features')->numeric()->alignEnd(),
                IconColumn::make('is_featured')->label('Featured')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PricesRelationManager::class,
            FeaturesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
