<?php

namespace App\Filament\Resources\SubscriptionFeatureResource\Pages;

use App\Filament\Resources\SubscriptionFeatureResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionFeature extends EditRecord
{
    protected static string $resource = SubscriptionFeatureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
