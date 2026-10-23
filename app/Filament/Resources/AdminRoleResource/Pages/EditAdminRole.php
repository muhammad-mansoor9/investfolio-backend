<?php

namespace App\Filament\Resources\AdminRoleResource\Pages;

use App\Filament\Resources\AdminRoleResource;
use App\Models\AdminRole;
use App\Support\AdminModules;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAdminRole extends EditRecord
{
    protected static string $resource = AdminRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return AdminRoleResource::fillAccess($data);
    }

    // Prevents an admin from locking themselves out of the panel.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = AdminRoleResource::dehydrateAccess($data);
        $updated = new AdminRole($data);

        if ($this->record->getKey() === auth('admin')->user()?->admin_role_id
            && (! $updated->status || ! $updated->allowsModule(AdminModules::SYSTEM))) {
            Notification::make()
                ->title('This is your own role')
                ->body('Keep it enabled and with System access, or ask another admin to change it.')
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }
}
