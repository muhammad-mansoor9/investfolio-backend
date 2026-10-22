<?php

namespace App\Services;

use App\Models\McpOauthRefreshToken;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and rotates the OAuth tokens MCP clients use. A connection is a "family" of refresh tokens:
 * every refresh returns a new pair and retires the old one, so a connection lasts until the user
 * revokes it or leaves it unused for REFRESH_IDLE_DAYS.
 */
class McpTokenService
{
    public const TOKEN_PREFIX = 'mcp:';

    public const ACCESS_TOKEN_DAYS = 30;

    private const REFRESH_IDLE_DAYS = 90;

    // Two requests can race to refresh with the same token; within this window the loser just gets
    // invalid_grant instead of revoking the connection as a suspected token leak.
    private const REUSE_GRACE_SECONDS = 60;

    // A rotated access token stays valid briefly so requests already in flight don't fail.
    private const ROTATED_ACCESS_TOKEN_MINUTES = 5;

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function issue(User $user, ?string $clientId, string $clientName): array
    {
        return DB::transaction(function () use ($user, $clientId, $clientName) {
            $this->pruneExpired($user);

            return $this->mint($user, (string) Str::uuid(), $clientId, $clientName);
        });
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null null = invalid_grant
     */
    public function refresh(string $refreshToken, ?string $clientId): ?array
    {
        return DB::transaction(function () use ($refreshToken, $clientId) {
            $row = McpOauthRefreshToken::where('token_hash', hash('sha256', $refreshToken))->lockForUpdate()->first();
            if (!$row || ($clientId !== null && $row->client_id !== null && $row->client_id !== $clientId)) {
                return null;
            }

            if ($row->rotated_at !== null) {
                if ($row->rotated_at->lt(now()->subSeconds(self::REUSE_GRACE_SECONDS))) {
                    $this->revokeFamily($row->family_id);
                }

                return null;
            }

            $user = $row->user;
            if ($row->expires_at->isPast() || !$user || !$user->is_active) {
                return null;
            }

            $this->pruneExpired($user);
            $row->forceFill(['rotated_at' => now()])->save();
            if ($row->access_token_id) {
                PersonalAccessToken::whereKey($row->access_token_id)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinutes(self::ROTATED_ACCESS_TOKEN_MINUTES)))
                    ->update(['expires_at' => now()->addMinutes(self::ROTATED_ACCESS_TOKEN_MINUTES)]);
            }

            return $this->mint($user, $row->family_id, $row->client_id, $row->client_name);
        });
    }

    public function revokeFamily(string $familyId): void
    {
        $accessTokenIds = McpOauthRefreshToken::where('family_id', $familyId)->whereNotNull('access_token_id')->pluck('access_token_id');
        PersonalAccessToken::whereIn('id', $accessTokenIds)->delete();
        McpOauthRefreshToken::where('family_id', $familyId)->delete();
    }

    public function familyForAccessToken(string $accessTokenId): ?string
    {
        return McpOauthRefreshToken::where('access_token_id', $accessTokenId)->value('family_id');
    }

    private function mint(User $user, string $familyId, ?string $clientId, string $clientName): array
    {
        $accessToken = $user->createToken(
            self::TOKEN_PREFIX . $clientName,
            ['mcp'],
            now()->addDays(self::ACCESS_TOKEN_DAYS),
        );

        $refreshToken = Str::random(64);
        McpOauthRefreshToken::create([
            'family_id' => $familyId,
            'user_id' => $user->id,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'access_token_id' => $accessToken->accessToken->id,
            'token_hash' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(self::REFRESH_IDLE_DAYS),
        ]);

        return [
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::ACCESS_TOKEN_DAYS * 86400,
        ];
    }

    private function pruneExpired(User $user): void
    {
        McpOauthRefreshToken::where('user_id', $user->id)->where('expires_at', '<', now())->delete();
        $user->tokens()
            ->where('name', 'like', self::TOKEN_PREFIX . '%')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
