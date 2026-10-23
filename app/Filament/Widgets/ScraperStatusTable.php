<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\JobRunResource;
use App\Models\JobHistory;
use App\Support\AdminModules;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ScraperStatusTable extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Scraper status';

    public static function canView(): bool
    {
        return AdminModules::allows(AdminModules::QUALITY);
    }

    public function table(Table $table): Table
    {
        return JobRunResource::latestRunColumns($table->query(JobHistory::query()->latestPerScript()))
            ->defaultSort('run_timestamp', 'desc')
            ->paginated(false)
            ->emptyStateHeading('No job runs recorded')
            ->emptyStateIcon('heroicon-o-cpu-chip');
    }
}
