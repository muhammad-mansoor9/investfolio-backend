<?php

namespace App\Filament\Resources\SectorResource\RelationManagers;

use App\Filament\Resources\StockResource;
use App\Models\Stock;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class StocksRelationManager extends RelationManager
{
    protected static string $relationship = 'stocks';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('symbol')
            ->defaultSort('symbol')
            ->columns([
                TextColumn::make('symbol')->searchable()->sortable()->weight('bold')
                    ->description(fn (Stock $record) => str($record->description)->limit(50)->toString()),
                TextColumn::make('market_cap')->label('Market cap')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state) => $state ? Number::abbreviate((float) $state, maxPrecision: 1) : '—'),
                IconColumn::make('is_shariah')->label('Shariah')->boolean(),
                ToggleColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordUrl(fn (Stock $record) => StockResource::getUrl('edit', ['record' => $record]));
    }
}
