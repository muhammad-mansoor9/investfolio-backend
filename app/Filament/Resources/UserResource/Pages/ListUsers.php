<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All users'),
            'paying' => Tab::make('Paying')
                ->icon('heroicon-m-star')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('subscription.plan', fn ($plan) => $plan->where('is_default', false))),
            'suspended' => Tab::make('Suspended')
                ->icon('heroicon-m-no-symbol')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }
}
