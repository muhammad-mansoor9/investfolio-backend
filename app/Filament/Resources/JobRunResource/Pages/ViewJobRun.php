<?php

namespace App\Filament\Resources\JobRunResource\Pages;

use App\Filament\Resources\JobRunResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewJobRun extends ViewRecord
{
    protected static string $resource = JobRunResource::class;

    public function getTitle(): string
    {
        return $this->record->script_name;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
