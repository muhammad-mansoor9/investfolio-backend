<?php

namespace App\Filament\Resources\JobRunResource\Pages;

use App\Filament\Resources\JobRunResource;
use App\Models\JobHistory;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListJobRuns extends ListRecords
{
    protected static string $resource = JobRunResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All runs'),
            'failed' => Tab::make('Failed')
                ->icon('heroicon-m-x-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'failed')),
            'running' => Tab::make('Running')
                ->icon('heroicon-m-play')
                ->badge(fn () => JobHistory::where('status', 'running')->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'running')),
        ];
    }
}
