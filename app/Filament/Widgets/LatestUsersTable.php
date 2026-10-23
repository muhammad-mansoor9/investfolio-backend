<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Support\AdminModules;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestUsersTable extends TableWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 3];

    protected static ?string $heading = 'Latest sign-ups';

    public static function canView(): bool
    {
        return AdminModules::allows(AdminModules::CUSTOMERS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query()->with('subscription.plan')->latest())
            ->columns([
                TextColumn::make('name')->description(fn (User $record) => $record->email),
                TextColumn::make('subscription.plan.name')->label('Plan')->badge()->placeholder('None')
                    ->color(fn (User $record) => $record->subscription?->plan?->is_default === false ? 'primary' : 'gray'),
                TextColumn::make('created_at')->label('Joined')->since()->dateTimeTooltip(),
            ])
            ->recordUrl(fn (User $record) => UserResource::getUrl('edit', ['record' => $record]))
            ->paginated([5]);
    }
}
