<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class JournalService
{
    public const MAX_TAGS = 20;

    public const MAX_TAG_LENGTH = 40;

    public const MAX_LIMIT = 500;

    // created_at is stored in UTC; date filters are calendar days as the PSX trader sees them.
    private const TIMEZONE = 'Asia/Karachi';

    /**
     * @return array{total: int, entries: Collection}
     */
    public function search(User $user, array $filters): array
    {
        $filters = Validator::make($filters, [
            'search' => 'nullable|string|max:200',
            'tags' => 'nullable|array|max:' . self::MAX_TAGS,
            'tags.*' => 'string|max:' . self::MAX_TAG_LENGTH,
            'tag_match' => 'nullable|in:any,all',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'days' => 'nullable|integer|min:1|max:3650',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
            'order' => 'nullable|in:asc,desc',
        ])->validate();

        $query = $this->filtered($user, $filters);
        $total = (clone $query)->count();

        $entries = $query
            ->orderBy('created_at', $filters['order'] ?? 'desc')
            ->limit($filters['limit'] ?? self::MAX_LIMIT)
            ->get();

        return ['total' => $total, 'entries' => $entries];
    }

    public function create(User $user, array $data): JournalEntry
    {
        $data = $this->validateEntry($data, false);

        return JournalEntry::create([
            'user_id' => $user->id,
            'content' => $data['content'],
            'tags' => $this->normalizeTags($data['tags'] ?? []),
        ]);
    }

    public function update(User $user, string $id, array $data): ?JournalEntry
    {
        $entry = $this->find($user, $id);
        if (!$entry) {
            return null;
        }

        $data = $this->validateEntry($data, true);
        if (array_key_exists('content', $data)) {
            $entry->content = $data['content'];
        }
        if (array_key_exists('tags', $data)) {
            $entry->tags = $this->normalizeTags($data['tags'] ?? []);
        }
        $entry->save();

        return $entry;
    }

    public function delete(User $user, string $id): bool
    {
        $entry = $this->find($user, $id);

        return $entry ? (bool) $entry->delete() : false;
    }

    public function find(User $user, string $id): ?JournalEntry
    {
        if (!Str::isUuid($id)) {
            return null;
        }

        return JournalEntry::where('user_id', $user->id)->where('id', $id)->first();
    }

    /**
     * Distinct tags the user has used, most used first.
     */
    public function tags(User $user): Collection
    {
        return collect(DB::select(
            'SELECT tag, COUNT(*) AS entry_count
             FROM journal_entries, jsonb_array_elements_text(tags) AS tag
             WHERE user_id = ?
             GROUP BY tag
             ORDER BY entry_count DESC, tag',
            [$user->id]
        ))->map(fn ($row) => ['tag' => $row->tag, 'entry_count' => (int) $row->entry_count]);
    }

    /**
     * Stock and index symbols offered as tag suggestions.
     */
    public function tagSuggestions(): Collection
    {
        $stocks = DB::table('stocks')
            ->where('is_active', true)
            ->orderBy('symbol')
            ->get(['symbol', 'description as name'])
            ->map(fn ($row) => ['symbol' => $row->symbol, 'name' => $row->name, 'type' => 'stock']);

        $indices = DB::table('indices')
            ->orderBy('symbol')
            ->get(['symbol', 'description as name'])
            ->map(fn ($row) => ['symbol' => $row->symbol, 'name' => $row->name, 'type' => 'index']);

        return $indices->concat($stocks)->values();
    }

    private function filtered(User $user, array $filters): Builder
    {
        $query = JournalEntry::where('user_id', $user->id);

        if (!empty($filters['search'])) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
            $query->where('content', 'ILIKE', '%' . $escaped . '%');
        }

        $tags = $this->normalizeTags($filters['tags'] ?? []);
        if ($tags) {
            if (($filters['tag_match'] ?? 'any') === 'all') {
                foreach ($tags as $tag) {
                    $query->whereJsonContains('tags', $tag);
                }
            } else {
                $query->where(function (Builder $q) use ($tags) {
                    foreach ($tags as $tag) {
                        $q->orWhereJsonContains('tags', $tag);
                    }
                });
            }
        }

        if (!empty($filters['start_date']) || !empty($filters['end_date'])) {
            if (!empty($filters['start_date'])) {
                $query->where('created_at', '>=', Carbon::parse($filters['start_date'], self::TIMEZONE)->startOfDay()->utc());
            }
            if (!empty($filters['end_date'])) {
                $query->where('created_at', '<', Carbon::parse($filters['end_date'], self::TIMEZONE)->addDay()->startOfDay()->utc());
            }
        } elseif (!empty($filters['days'])) {
            $query->where('created_at', '>=', Carbon::now()->subDays((int) $filters['days']));
        }

        return $query;
    }

    private function validateEntry(array $data, bool $partial): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return Validator::make($data, [
            'content' => "{$presence}|string|min:1|max:20000",
            'tags' => 'nullable|array|max:' . self::MAX_TAGS,
            'tags.*' => 'string|max:' . self::MAX_TAG_LENGTH,
        ])->validate();
    }

    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->filter(fn ($tag) => is_string($tag))
            ->map(fn ($tag) => strtoupper(preg_replace('/\s+/', ' ', trim($tag))))
            ->filter()
            ->unique()
            ->take(self::MAX_TAGS)
            ->values()
            ->all();
    }
}
