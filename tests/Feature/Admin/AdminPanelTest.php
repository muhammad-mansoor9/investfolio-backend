<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\DailyIntegrity;
use App\Filament\Pages\FinancialIntegrity;
use App\Filament\Resources\AdminRoleResource;
use App\Filament\Widgets\DataFreshness;
use App\Filament\Widgets\JobRunsChart;
use App\Filament\Widgets\LatestUsersTable;
use App\Filament\Widgets\RecentFailedJobsTable;
use App\Filament\Widgets\ScraperStatusTable;
use App\Filament\Widgets\SignupsChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\WelcomeBanner;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\JobHistory;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AdminModules;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Signs in as an admin that is never saved, so these tests only read from the database.
class AdminPanelTest extends TestCase
{
    private function actingAsAdmin(string $modules = AdminModules::ALL, bool $enabled = true): Admin
    {
        $role = new AdminRole(['name' => 'Test role', 'module_access' => $modules, 'status' => $enabled]);
        $role->id = (string) Str::uuid();

        $admin = new Admin(['name' => 'Test Admin', 'email' => 'admin@example.test']);
        $admin->id = (string) Str::uuid();
        $admin->admin_role_id = $role->id;
        $admin->setRelation('role', $role);

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_guests_are_sent_to_the_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Investfolio');
    }

    public function test_an_admin_with_a_disabled_role_is_refused(): void
    {
        $this->actingAsAdmin(enabled: false);

        $this->get('/admin')->assertForbidden();
    }

    public static function screens(): array
    {
        return array_map(fn ($path) => [$path], [
            'dashboard' => '/admin',
            'daily coverage' => '/admin/daily-integrity',
            'daily coverage, past month' => '/admin/daily-integrity?month=2026-01&issues=1',
            'financial statements' => '/admin/financial-integrity',
            'quarterly results' => '/admin/financial-integrity?source=financial_results&filter=all',
            'job runs' => '/admin/job-runs',
            'rescrape queue' => '/admin/rescrape-queue',
            'stocks' => '/admin/stocks',
            'sectors' => '/admin/sectors',
            'new sector' => '/admin/sectors/create',
            'users' => '/admin/users',
            'plans' => '/admin/subscription-plans',
            'new plan' => '/admin/subscription-plans/create',
            'features' => '/admin/subscription-features',
            'subscriptions' => '/admin/user-subscriptions',
            'paddle events' => '/admin/paddle-webhook-events',
            'admins' => '/admin/admins',
            'new admin' => '/admin/admins/create',
            'roles' => '/admin/admin-roles',
            'new role' => '/admin/admin-roles/create',
        ]);
    }

    #[DataProvider('screens')]
    public function test_every_screen_renders(string $path): void
    {
        $this->actingAsAdmin();

        $this->get($path)->assertOk();
    }

    public function test_record_pages_render(): void
    {
        $this->actingAsAdmin();

        foreach ([
            '/admin/stocks/%s/edit' => Stock::query(),
            '/admin/sectors/%s/edit' => Sector::query(),
            '/admin/users/%s/edit' => User::query(),
            '/admin/subscription-plans/%s/edit' => SubscriptionPlan::query(),
            '/admin/subscription-features/%s/edit' => SubscriptionFeature::query(),
            '/admin/job-runs/%s' => JobHistory::query()->whereNotNull('exception_message'),
        ] as $path => $query) {
            $record = $query->first();
            if ($record) {
                $this->get(sprintf($path, $record->getKey()))->assertOk();
            }
        }
    }

    public function test_dashboard_widgets_render(): void
    {
        $this->actingAsAdmin();

        foreach ([WelcomeBanner::class, StatsOverview::class, DataFreshness::class, JobRunsChart::class, SignupsChart::class,
            ScraperStatusTable::class, RecentFailedJobsTable::class, LatestUsersTable::class] as $widget) {
            Livewire::test($widget, ['filters' => ['period' => 90]])->assertOk();
        }
    }

    public function test_daily_coverage_moves_between_months_but_not_into_the_future(): void
    {
        $this->actingAsAdmin();
        $current = now('Asia/Karachi')->format('Y-m');

        Livewire::test(DailyIntegrity::class)
            ->assertSet('month', $current)
            ->call('shiftMonth', -1)
            ->assertSet('month', now('Asia/Karachi')->startOfMonth()->subMonth()->format('Y-m'))
            ->call('shiftMonth', 5)
            ->assertSet('month', $current)
            ->set('issuesOnly', true)
            ->assertOk();
    }

    public function test_financial_coverage_filters_and_switches_source(): void
    {
        $this->actingAsAdmin();

        $page = Livewire::test(FinancialIntegrity::class)
            ->call('toggleFilter', 'GAPS')
            ->assertSet('filter', 'GAPS')
            ->call('toggleFilter', 'GAPS')
            ->assertSet('filter', 'attention')
            ->set('search', 'zzz-no-such-symbol')
            ->assertSee('No stocks match these filters.')
            ->call('setSource', 'financial_results')
            ->assertSet('source', 'financial_results')
            ->call('setSource', 'not-a-source')
            ->assertSet('source', 'financial_results');

        $this->assertCount(FinancialIntegrity::TIMELINE_QUARTERS, $page->instance()->timeline);
    }

    public function test_roles_limit_what_an_admin_sees(): void
    {
        $this->actingAsAdmin(AdminModules::MARKET);

        $this->get('/admin/stocks')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/daily-integrity')->assertForbidden();
        $this->get('/admin/admins')->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('Daily coverage');
    }

    public function test_role_access_round_trips_through_the_form(): void
    {
        $data = AdminRoleResource::dehydrateAccess(['name' => 'Ops', 'full_access' => false, 'modules' => ['quality', 'market', 'bogus']]);
        $this->assertSame('market,quality', $data['module_access']);
        $this->assertArrayNotHasKey('modules', $data);

        $filled = AdminRoleResource::fillAccess($data);
        $this->assertFalse($filled['full_access']);
        $this->assertSame(['market', 'quality'], $filled['modules']);

        $this->assertSame('*', AdminRoleResource::dehydrateAccess(['full_access' => true, 'modules' => ['market']])['module_access']);
    }

    public function test_the_public_integrity_api_is_gone(): void
    {
        $this->getJson('/api/data-integrity/daily')->assertNotFound();
    }
}
