<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\UserSubscriptionResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Support\AdminModules;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Read-only: SubscriptionSyncService owns these rows; direct grants are made from the user's page.
class UserSubscriptionResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::BILLING;

    protected static ?string $model = UserSubscription::class;

    protected static ?string $modelLabel = 'subscription';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static ?int $navigationSort = 3;

    public const STATUS_COLORS = [
        'active' => 'success',
        'trialing' => 'info',
        'past_due' => 'danger',
        'paused' => 'warning',
        'canceled' => 'gray',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'plan', 'price']))
            ->columns([
                TextColumn::make('user.name')->label('User')->weight('medium')
                    ->description(fn (UserSubscription $record) => $record->user?->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('plan.name')->label('Plan')->badge()
                    ->color(fn (UserSubscription $record) => $record->plan?->is_default ? 'gray' : 'primary'),
                TextColumn::make('price')->label('Billing')
                    ->state(fn (UserSubscription $record) => $record->price
                        ? trim($record->price->currency).' '.number_format($record->price->price, 2).' / '.str_replace('_', '-', $record->price->billing_cycle)
                        : null)
                    ->placeholder('Not billed'),
                TextColumn::make('status')->badge()->sortable()
                    ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('current_period_end')->label('Period ends')->date('j M Y')->sortable()->placeholder('—'),
                IconColumn::make('cancel_at_period_end')->label('Cancelling')->boolean()
                    ->trueIcon('heroicon-o-x-circle')->trueColor('warning')->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('paddle_subscription_id')->label('Paddle')->fontFamily('mono')->copyable()
                    ->placeholder('Granted / free')->toggleable(),
                TextColumn::make('updated_at')->label('Updated')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                SelectFilter::make('plan_id')->label('Plan')
                    ->options(fn () => SubscriptionPlan::orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->options(array_combine(array_keys(self::STATUS_COLORS), array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), array_keys(self::STATUS_COLORS)))),
                TernaryFilter::make('paddle_subscription_id')->label('Billed by Paddle')->nullable(),
                TernaryFilter::make('cancel_at_period_end')->label('Cancelling at period end'),
            ])
            ->recordUrl(fn (UserSubscription $record) => AdminModules::allows(AdminModules::CUSTOMERS)
                ? UserResource::getUrl('edit', ['record' => $record->user_id])
                : null);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserSubscriptions::route('/'),
        ];
    }
}
