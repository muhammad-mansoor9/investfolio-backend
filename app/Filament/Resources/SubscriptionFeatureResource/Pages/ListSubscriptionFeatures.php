<?php

namespace App\Filament\Resources\SubscriptionFeatureResource\Pages;

use App\Filament\Resources\SubscriptionFeatureResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionFeatures extends ListRecords
{
    protected static string $resource = SubscriptionFeatureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
