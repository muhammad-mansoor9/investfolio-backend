<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\JobRunResource;
use App\Models\JobHistory;
use App\Support\AdminModules;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentFailedJobsTable extends TableWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 3];

    protected static ?string $heading = 'Recent failures';

    public static function canView(): bool
    {
        return AdminModules::allows(AdminModules::QUALITY);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(JobHistory::query()->where('status', 'failed')->latest('run_timestamp'))
            ->columns([
                TextColumn::make('script_name')->label('Script')
                    ->description(fn (JobHistory $record) => str($record->exception_message)->limit(70)->toString()),
                TextColumn::make('run_timestamp')->label('When')->since()->dateTimeTooltip(),
            ])
            ->recordUrl(fn (JobHistory $record) => JobRunResource::getUrl('view', ['record' => $record]))
            ->paginated([5])
            ->emptyStateHeading('No failed runs')
            ->emptyStateIcon('heroicon-o-shield-check');
    }
}
