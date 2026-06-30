<?php

declare(strict_types=1);

namespace App\Models;

use Investfolio\InvestfolioShared\Interfaces\MarketData\MarketDataInterface;
use Investfolio\InvestfolioShared\Notifications\InvitedOnboardingNotification;
use Investfolio\InvestfolioShared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Watchlist extends PgsqlModel
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'title',
        'notes',
    ];

    public static ?string $owner_id = null;

    protected static function boot()
    {
        parent::boot();

        static::saved(function ($watchlist) {
            self::ensureWatchlistHasOwner($watchlist);
        });
    }

    protected $hidden = [];

    protected $with = ['users', 'items'];

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot(['owner', 'full_access', 'invite_accepted_at']);
    }

    public function items()
    {
        return $this->hasMany(WatchlistItem::class, 'watchlist_id')
            ->withMarketData();
    }

    /**
     * Related chats for watchlist
     *
     * @return void
     */
    public function chats()
    {
        return $this->morphMany(AiChat::class, 'chatable')->where('user_id', auth()->user()->id);
    }

    public function scopeMyWatchlists()
    {
        return $this->whereHas('users', function ($query) {
            $query->where('user_id', auth()->user()->id);
        });
    }

    public function scopeFullAccess($query, $user_id = null)
    {
        return $query->whereHas('users', function ($query) use ($user_id) {
            $query->where('user_id', $user_id ?? auth()->user()->id)
                ->where(function ($query) {
                    $query->where('full_access', true)
                        ->orWhere('owner', true);
                });
        });
    }

    public function setOwnerIdAttribute($value)
    {
        // enable queued jobs to create watchlists with owners
        if (! auth()->user()?->id && ! $this->owner_id) {
            static::$owner_id = $value;
        }
    }

    public function getOwnerIdAttribute()
    {
        return $this->owner?->id;
    }

    public function getOwnerAttribute()
    {
        if (! $this->relationLoaded('user')) {
            $this->load('users');
        }

        return $this->users->where('pivot.owner', true)->first();
    }

    public static function ensureWatchlistHasOwner(self $watchlist)
    {
        // make sure we don't remove owner access
        if (! $watchlist->owner_id) {
            $owner[static::$owner_id ?? auth()->user()->id] = ['owner' => true];

            // save
            $watchlist->users()->sync($owner);
            static::$owner_id = null;
        }
    }

    public function getFormattedItems()
    {
        $formattedItems = '';
        foreach ($this->items as $item) {
            $formattedItems .= ' * Watchlist item: '.$item->market_data->name.' ('.$item->symbol.')'
                .'; current market value '.$item->market_data->market_value
                ."\n\n";
        }

        return $formattedItems;
    }

    /**
     * Share a watchlist with a user
     */
    public function share(string $email, bool $fullAccess = false): void
    {
        $user = User::firstOrCreate([
            'email' => $email,
        ], [
            'name' => Str::title(Str::before($email, '@')),
        ]);

        $permissions[$user->id] = [
            'full_access' => $fullAccess,
        ];

        $sync = $this->users()->syncWithoutDetaching($permissions);

        if (! empty($sync['attached'])) {
            foreach ($sync['attached'] as $newUserId) {
                User::find($newUserId)->notify(new InvitedOnboardingNotification($this, auth()->user()));
            }
        }
    }

    /**
     * Un-share a watchlist
     */
    public function unShare(string $userId): void
    {
        $this->users()->detach($userId);
    }
}
