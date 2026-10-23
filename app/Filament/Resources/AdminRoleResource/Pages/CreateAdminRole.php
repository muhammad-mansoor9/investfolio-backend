<?php

namespace App\Filament\Resources\AdminRoleResource\Pages;

use App\Filament\Resources\AdminRoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminRole extends CreateRecord
{
    protected static string $resource = AdminRoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AdminRoleResource::dehydrateAccess($data);
    }
}
