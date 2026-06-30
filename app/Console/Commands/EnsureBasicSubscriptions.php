<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Package;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;

class EnsureBasicSubscriptions extends Command
{
    protected $signature = 'subscriptions:ensure-basic
                           {--dry-run : Show what would be done without making changes}
                           {--force : Force execution without confirmation}';

    protected $description = 'Ensure all users have exactly one active subscription (Basic by default)';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Checking users for proper subscription setup...');

        $basicPackage = Package::where('price', 0.00)
            ->where('is_premium', false)
            ->where('is_active', true)
            ->first();

        if (!$basicPackage) {
            $this->error('Basic package not found! Please create a package with price = 0.00 and is_premium = false');
            return 1;
        }

        $this->info("Basic package found: {$basicPackage->name} (ID: {$basicPackage->id})");

        $usersWithoutSubscriptions = $this->getUsersWithoutActiveSubscriptions();
        $usersWithMultipleSubscriptions = $this->getUsersWithMultipleActiveSubscriptions();

        $totalIssues = $usersWithoutSubscriptions->count() + $usersWithMultipleSubscriptions->count();

        if ($totalIssues === 0) {
            $this->info('All users have proper subscription setup (exactly one active subscription).');
            return 0;
        }

        $this->info("Found subscription issues:");
        $this->info("- {$usersWithoutSubscriptions->count()} users without active subscriptions");
        $this->info("- {$usersWithMultipleSubscriptions->count()} users with multiple active subscriptions");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->showIssueDetails($usersWithoutSubscriptions, $usersWithMultipleSubscriptions);
            return 0;
        }

        if (!$force && !$this->confirm("Fix subscription issues for {$totalIssues} users?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $fixed = 0;
        $errors = 0;

        DB::beginTransaction();

        try {
            foreach ($usersWithoutSubscriptions as $user) {
                if ($this->createBasicSubscriptionForUser($user, $basicPackage)) {
                    $fixed++;
                    $this->info("✓ Created basic subscription for {$user->email}");
                } else {
                    $errors++;
                    $this->error("✗ Failed to create subscription for {$user->email}");
                }
            }

            foreach ($usersWithMultipleSubscriptions as $user) {
                if ($this->fixMultipleSubscriptions($user, $basicPackage)) {
                    $fixed++;
                    $this->info("✓ Fixed multiple subscriptions for {$user->email}");
                } else {
                    $errors++;
                    $this->error("✗ Failed to fix subscriptions for {$user->email}");
                }
            }

            DB::commit();

            $this->info("\nOperation completed:");
            $this->info("- Fixed: {$fixed} users");
            if ($errors > 0) {
                $this->error("- Errors: {$errors}");
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Transaction failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function getUsersWithoutActiveSubscriptions()
    {
        return User::whereDoesntHave('subscriptions', function($query) {
            $query->where('status', 'active')
                ->where(function($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now())
                        ->orWhereHas('package', function($packageQuery) {
                            $packageQuery->where('price', 0.00);
                        });
                });
        })->get();
    }

    private function getUsersWithMultipleActiveSubscriptions()
    {
        return User::whereHas('subscriptions', function($query) {
            $query->where('status', 'active')
                ->where(function($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now())
                        ->orWhereHas('package', function($packageQuery) {
                            $packageQuery->where('price', 0.00);
                        });
                });
        }, '>', 1)->get();
    }

    private function showIssueDetails($usersWithoutSubscriptions, $usersWithMultipleSubscriptions)
    {
        if ($usersWithoutSubscriptions->count() > 0) {
            $this->info("\nUsers without active subscriptions:");
            $this->table(['ID', 'Name', 'Email', 'Provider'],
                $usersWithoutSubscriptions->map(function($user) {
                    return [$user->id, $user->name, $user->email, $user->provider ?? 'email'];
                })->toArray()
            );
        }

        if ($usersWithMultipleSubscriptions->count() > 0) {
            $this->info("\nUsers with multiple active subscriptions:");
            $this->table(['ID', 'Name', 'Email', 'Active Subscriptions'],
                $usersWithMultipleSubscriptions->map(function($user) {
                    $activeCount = $user->subscriptions()->where('status', 'active')->count();
                    return [$user->id, $user->name, $user->email, $activeCount];
                })->toArray()
            );
        }
    }

    private function createBasicSubscriptionForUser(User $user, Package $basicPackage): bool
    {
        try {
            UserSubscription::create([
                'user_id' => $user->id,
                'package_id' => $basicPackage->id,
                'starts_at' => now(),
                'expires_at' => null,
                'status' => 'active',
                'metadata' => [
                    'auto_created' => true,
                    'created_reason' => 'ensure_basic_subscriptions_command',
                    'created_at' => now()
                ]
            ]);

            return true;
        } catch (\Exception $e) {
            $this->error("Error creating subscription for user {$user->id}: " . $e->getMessage());
            return false;
        }
    }

    private function fixMultipleSubscriptions(User $user, Package $basicPackage): bool
    {
        try {
            $activeSubscriptions = $user->subscriptions()
                ->where('status', 'active')
                ->with('package')
                ->orderBy('created_at', 'desc')
                ->get();

            if ($activeSubscriptions->count() <= 1) {
                return true;
            }

            $premiumSubscription = $activeSubscriptions->where('package.is_premium', true)->first();
            $basicSubscription = $activeSubscriptions->where('package.is_premium', false)->first();

            $subscriptionToKeep = $premiumSubscription ?: $basicSubscription ?: $activeSubscriptions->first();

            foreach ($activeSubscriptions as $subscription) {
                if ($subscription->id !== $subscriptionToKeep->id) {
                    $subscription->update([
                        'status' => 'replaced',
                        'cancelled_at' => now(),
                        'metadata' => array_merge($subscription->metadata ?? [], [
                            'replaced_reason' => 'duplicate_subscription_cleanup',
                            'replaced_at' => now()
                        ])
                    ]);
                }
            }

            if (!$premiumSubscription && (!$basicSubscription || $basicSubscription->id !== $subscriptionToKeep->id)) {
                $subscriptionToKeep->update([
                    'status' => 'replaced',
                    'cancelled_at' => now()
                ]);

                $this->createBasicSubscriptionForUser($user, $basicPackage);
            }

            return true;
        } catch (\Exception $e) {
            $this->error("Error fixing multiple subscriptions for user {$user->id}: " . $e->getMessage());
            return false;
        }
    }
}
