<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\AdminResource\Pages;
use App\Models\Admin;
use App\Support\AdminModules;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AdminResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::SYSTEM;

    protected static ?string $model = Admin::class;

    protected static ?string $modelLabel = 'admin';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Admins';

    protected static ?int $navigationSort = 1;

    public static function canDelete(Model $record): bool
    {
        return $record->getKey() !== auth('admin')->id();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Profile')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(150),
                    TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                    TextInput::make('phone')->tel()->maxLength(150),
                    Select::make('admin_role_id')->label('Role')->relationship('role', 'name')->required()->preload()
                        ->disabled(fn (?Admin $record) => $record?->getKey() === auth('admin')->id())
                        ->helperText(fn (?Admin $record) => $record?->getKey() === auth('admin')->id() ? 'You cannot change your own role.' : null),
                ]),
            Section::make('Password')
                ->description(fn (?Admin $record) => $record ? 'Leave empty to keep the current password.' : null)
                ->columns(2)
                ->schema([
                    TextInput::make('password')->password()->revealable()
                        ->required(fn (?Admin $record) => $record === null)
                        ->minLength(10)
                        ->confirmed()
                        ->dehydrated(fn (?string $state) => filled($state)),
                    TextInput::make('password_confirmation')->label('Confirm password')->password()->revealable()
                        ->requiredWith('password')
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('role'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium')
                    ->description(fn (Admin $record) => $record->email),
                TextColumn::make('email')->searchable()->hidden(),
                TextColumn::make('role.name')->label('Role')->badge()
                    ->color(fn (Admin $record) => $record->role?->status ? 'primary' : 'gray')
                    ->description(fn (Admin $record) => $record->role?->status === false ? 'Role disabled' : null),
                TextColumn::make('phone')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Added')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                SelectFilter::make('admin_role_id')->label('Role')->relationship('role', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdmins::route('/'),
            'create' => Pages\CreateAdmin::route('/create'),
            'edit' => Pages\EditAdmin::route('/{record}/edit'),
        ];
    }
}
