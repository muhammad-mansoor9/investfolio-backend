<?php

namespace App\Filament\Resources\PaddleWebhookEventResource\Pages;

use App\Filament\Resources\PaddleWebhookEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaddleWebhookEvent extends ViewRecord
{
    protected static string $resource = PaddleWebhookEventResource::class;

    public function getTitle(): string
    {
        return $this->record->event_type;
    }
}
