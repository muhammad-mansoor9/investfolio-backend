<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                UserResource::changePlanAction(Action::class),
                UserResource::signOutAction(Action::class),
                UserResource::toggleActiveAction(Action::class),
            ])->label('Manage')->button()->color('gray'),
        ];
    }
}
