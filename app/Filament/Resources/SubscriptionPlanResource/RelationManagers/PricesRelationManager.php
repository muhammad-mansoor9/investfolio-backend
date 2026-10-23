<?php

namespace App\Filament\Resources\SubscriptionPlanResource\RelationManagers;

use App\Models\SubscriptionPlanPrice;
use App\Models\UserSubscription;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    // Values of the subscription_plan_price_billing_cycle enum.
    private const CYCLES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-annual', 'annual' => 'Annual'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('billing_cycle')->options(self::CYCLES)->required(),
            TextInput::make('price')->numeric()->minValue(0)->required()
                ->helperText('Must equal the amount of the Paddle price, which is what customers are charged.'),
            TextInput::make('currency')->default('USD')->length(3)->required()
                ->formatStateUsing(fn (?string $state) => $state ? trim($state) : 'USD')
                ->dehydrateStateUsing(fn (string $state) => strtoupper($state)),
            TextInput::make('paddle_price_id')->label('Paddle price ID')->placeholder('pri_...')->maxLength(255)
                ->helperText('Without one the price is shown but cannot be bought.'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (SubscriptionPlanPrice $record) => self::CYCLES[$record->billing_cycle] ?? $record->billing_cycle)
            ->columns([
                TextColumn::make('billing_cycle')->label('Cycle')->formatStateUsing(fn (string $state) => self::CYCLES[$state] ?? $state),
                TextColumn::make('price')->formatStateUsing(fn (SubscriptionPlanPrice $record) => trim($record->currency).' '.number_format($record->price, 2)),
                TextColumn::make('paddle_price_id')->label('Paddle price')->copyable()->placeholder('Not linked')->fontFamily('mono'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                // Subscribed prices are kept for billing history; deactivate them instead.
                Tables\Actions\DeleteAction::make()->iconButton()
                    ->hidden(fn (SubscriptionPlanPrice $record) => UserSubscription::where('plan_price_id', $record->id)->exists()),
            ]);
    }
}
