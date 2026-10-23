<?php

namespace App\Filament\Resources\SubscriptionPlanResource\RelationManagers;

use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlanFeature;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'planFeatures';

    protected static ?string $title = 'Included features';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('feature_id')->label('Feature')->required()->searchable()->preload()
                ->options(fn () => SubscriptionFeature::orderBy('sort_order')->pluck('name', 'id')->all())
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('plan_id', $this->getOwnerRecord()->id)),
            TextInput::make('usage_limit')->numeric()->minValue(0)
                ->helperText('Leave empty for unlimited. Counted per month for usage-tracked features.'),
            TextInput::make('display_text')->maxLength(255)->columnSpanFull()
                ->helperText('Shown on the pricing page instead of the feature name, e.g. "10 AI insights a month".'),
            TextInput::make('sort_order')->numeric()->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('feature'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->recordTitle(fn (SubscriptionPlanFeature $record) => $record->feature?->name ?? 'feature')
            ->columns([
                TextColumn::make('feature.name')->label('Feature')->weight('medium')
                    ->description(fn (SubscriptionPlanFeature $record) => $record->feature?->feature_key),
                TextColumn::make('usage_limit')->label('Limit')->placeholder('Unlimited')->numeric(),
                TextColumn::make('display_text')->placeholder('—')->wrap(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add feature'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton()->label('Remove'),
            ]);
    }
}
