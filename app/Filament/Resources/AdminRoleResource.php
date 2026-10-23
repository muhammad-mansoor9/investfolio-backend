<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\BelongsToAdminModule;
use App\Filament\Resources\AdminRoleResource\Pages;
use App\Models\AdminRole;
use App\Support\AdminModules;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AdminRoleResource extends Resource
{
    use BelongsToAdminModule;

    protected static string $adminModule = AdminModules::SYSTEM;

    protected static ?string $model = AdminRole::class;

    protected static ?string $modelLabel = 'role';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Roles';

    protected static ?int $navigationSort = 2;

    public static function canDelete(Model $record): bool
    {
        return ! $record->admins()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(150)->unique(ignoreRecord: true),
                    Toggle::make('status')->label('Enabled')->default(true)->inline(false)
                        ->helperText('Admins with a disabled role cannot sign in.'),
                    Toggle::make('full_access')->label('Full access')->live()->columnSpanFull()
                        ->helperText('Every area, including ones added later.'),
                    CheckboxList::make('modules')->label('Areas')->options(AdminModules::LABELS)
                        ->hidden(fn (Get $get) => $get('full_access'))
                        ->required(fn (Get $get) => ! $get('full_access'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('admins'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('module_access')->label('Areas')->badge()
                    ->state(fn (AdminRole $record) => $record->hasFullAccess()
                        ? ['Full access']
                        : array_map(fn ($module) => ucfirst($module), $record->modules()))
                    ->color(fn (string $state) => $state === 'Full access' ? 'primary' : 'gray'),
                TextColumn::make('admins_count')->label('Admins')->numeric()->alignEnd(),
                IconColumn::make('status')->label('Enabled')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function fillAccess(array $data): array
    {
        $role = new AdminRole(['module_access' => $data['module_access'] ?? '']);

        return [...$data, 'full_access' => $role->hasFullAccess(), 'modules' => $role->hasFullAccess() ? [] : $role->modules()];
    }

    public static function dehydrateAccess(array $data): array
    {
        $access = ($data['full_access'] ?? false)
            ? AdminModules::ALL
            : implode(',', array_values(array_intersect(array_keys(AdminModules::LABELS), $data['modules'] ?? [])));

        unset($data['full_access'], $data['modules']);

        return [...$data, 'module_access' => $access];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminRoles::route('/'),
            'create' => Pages\CreateAdminRole::route('/create'),
            'edit' => Pages\EditAdminRole::route('/{record}/edit'),
        ];
    }
}
