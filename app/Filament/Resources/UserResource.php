<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\UserResource\Pages;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AdminModules;
use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UserResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::CUSTOMERS;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    // Matches AuthController::WEB_TOKEN; MCP tokens (mcp:*) must never be revoked from here.
    private const WEB_TOKEN = 'psx-web';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        return ['Email' => $record->email];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Section::make('Account')
                    ->columnSpan(['lg' => 2])
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(150),
                        TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                        DateTimePicker::make('email_verified_at')->label('Email verified at')->seconds(false)
                            ->helperText('Clear it to make the user verify their email again.'),
                        Toggle::make('is_active')->label('Active')->inline(false)
                            ->helperText('Inactive users are refused by every signed-in endpoint.'),
                    ]),
                Section::make('Subscription')
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Placeholder::make('plan')->content(fn (?User $record) => $record?->subscription?->plan?->name ?? 'None yet'),
                        Placeholder::make('status')->content(fn (?User $record) => ucfirst(str_replace('_', ' ', $record?->subscription?->status ?? '—'))
                            .($record?->subscription?->cancel_at_period_end ? ' (cancels at period end)' : '')),
                        Placeholder::make('billing')->content(fn (?User $record) => $record?->subscription?->price?->label() ?? 'Not billed'),
                        Placeholder::make('renews')->label('Current period ends')
                            ->content(fn (?User $record) => $record?->subscription?->current_period_end?->format('j M Y') ?? '—'),
                        Placeholder::make('paddle')->label('Paddle subscription')
                            ->content(fn (?User $record) => $record?->subscription?->paddle_subscription_id ?? '—'),
                    ]),
                Section::make('Activity')
                    ->columnSpanFull()
                    ->columns(['md' => 3, 'xl' => 6])
                    ->schema([
                        Placeholder::make('provider')->label('Signs in with')->content(fn (?User $record) => ucfirst($record?->provider ?? 'email')),
                        Placeholder::make('joined')->content(fn (?User $record) => $record?->created_at?->format('j M Y') ?? '—'),
                        Placeholder::make('last_active')->label('Last active')
                            ->content(fn (?User $record) => ($last = $record?->tokens()->max('last_used_at')) ? Carbon::parse($last)->diffForHumans() : 'Never'),
                        Placeholder::make('web_sessions')->label('Web sessions')
                            ->content(fn (?User $record) => $record?->tokens()->where('name', self::WEB_TOKEN)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count() ?? 0),
                        Placeholder::make('mcp_connections')->label('AI (MCP) connections')
                            ->content(fn (?User $record) => $record?->tokens()->where('name', 'like', 'mcp:%')->count() ?? 0),
                        Placeholder::make('journal_entries')->label('Journal entries')
                            ->content(fn (?User $record) => $record ? DB::table('journal_entries')->where('user_id', $record->id)->count() : 0),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('subscription.plan'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium')
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('email')->searchable()->hidden(),
                TextColumn::make('provider')->label('Sign-in')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => ucfirst($state ?? 'email')),
                TextColumn::make('subscription.plan.name')->label('Plan')->badge()->placeholder('None')
                    ->color(fn (User $record) => $record->subscription?->plan?->is_default === false ? 'primary' : 'gray')
                    ->description(fn (User $record) => match (true) {
                        $record->subscription === null => null,
                        $record->subscription->cancel_at_period_end => 'Cancels '.$record->subscription->current_period_end?->format('j M'),
                        $record->subscription->status !== 'active' => ucfirst(str_replace('_', ' ', $record->subscription->status)),
                        default => null,
                    }),
                IconColumn::make('email_verified_at')->label('Verified')->boolean()
                    ->state(fn (User $record) => $record->email_verified_at !== null),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                TextColumn::make('created_at')->label('Joined')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('email_verified_at')->label('Email verified')->nullable(),
                SelectFilter::make('provider')->label('Sign-in')->options(['email' => 'Email', 'google' => 'Google']),
                SelectFilter::make('plan')
                    ->options(fn () => SubscriptionPlan::orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($q, $planId) => $q->whereHas('subscription', fn ($s) => $s->where('plan_id', $planId))
                    )),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    self::changePlanAction(),
                    self::signOutAction(),
                    self::toggleActiveAction(),
                ]),
            ]);
    }

    // Paddle subscribers change plan through billing, not by direct grant.
    public static function changePlanAction(string $action = Action::class): Action|\Filament\Actions\Action
    {
        return $action::make('changePlan')
            ->label('Change plan')
            ->icon('heroicon-o-star')
            ->visible(fn (User $record) => $record->subscription?->paddle_subscription_id === null)
            ->fillForm(fn (User $record) => ['plan_id' => $record->subscription?->plan_id])
            ->form([
                Select::make('plan_id')->label('Plan')->required()
                    ->options(fn () => SubscriptionPlan::active()->orderBy('sort_order')->pluck('name', 'id')->all()),
            ])
            ->modalDescription('Grants the plan directly, with no Paddle charge. It stays until changed here again.')
            ->action(function (User $record, array $data) {
                $record->ensureActiveSubscription()->update([
                    'plan_id' => $data['plan_id'],
                    'plan_price_id' => null,
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                    'canceled_at' => null,
                    'current_period_end' => null,
                ]);
                Notification::make()->title("{$record->name} is now on ".SubscriptionPlan::find($data['plan_id'])->name)->success()->send();
            });
    }

    public static function signOutAction(string $action = Action::class): Action|\Filament\Actions\Action
    {
        return $action::make('signOut')
            ->label('Sign out of the web app')
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->requiresConfirmation()
            ->modalDescription('Revokes every web session. AI (MCP) connections keep working.')
            ->action(function (User $record) {
                $revoked = $record->tokens()->where('name', self::WEB_TOKEN)->delete();
                Notification::make()->title("{$revoked} web ".str('session')->plural($revoked).' revoked')->success()->send();
            });
    }

    public static function toggleActiveAction(string $action = Action::class): Action|\Filament\Actions\Action
    {
        return $action::make('toggleActive')
            ->label(fn (User $record) => $record->is_active ? 'Suspend' : 'Reactivate')
            ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record) => $record->is_active
                ? 'The user is signed out of the web app and refused until reactivated.'
                : 'The user can sign in again.')
            ->action(function (User $record) {
                $record->update(['is_active' => ! $record->is_active]);
                if (! $record->is_active) {
                    $record->tokens()->where('name', self::WEB_TOKEN)->delete();
                }
                Notification::make()->title($record->is_active ? 'User reactivated' : 'User suspended')->success()->send();
            })
            // Otherwise a later Save on the edit page writes the stale value back.
            ->after(fn ($livewire) => method_exists($livewire, 'refreshFormData') ? $livewire->refreshFormData(['is_active']) : null);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
