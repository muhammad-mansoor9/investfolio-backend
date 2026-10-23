<?php

namespace App\Filament\Resources\RescrapeQueueResource\Pages;

use App\Filament\Resources\RescrapeQueueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRescrapeRequests extends ListRecords
{
    protected static string $resource = RescrapeQueueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Queue a stock')
                ->mutateFormDataUsing(fn (array $data) => [...$data, 'status' => 'pending', 'attempts' => 0]),
        ];
    }
}
