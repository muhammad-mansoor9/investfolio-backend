<?php

namespace App\Filament\Resources\StockResource\Pages;

use App\Filament\Resources\StockResource;
use App\Support\AdminModules;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditStock extends EditRecord
{
    protected static string $resource = StockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rescrape')
                ->label('Queue rescrape')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->visible(fn () => AdminModules::allows(AdminModules::QUALITY))
                ->action(fn () => StockResource::queueRescrape(collect([$this->record]))),
        ];
    }
}
