<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\ChatUser;
use App\Models\Message;
use App\Models\Setting;

/**
 * Everything Krotze deletes on a schedule, in one place.
 *
 * It runs from two directions — `php artisan channels:cleanup`, and a lazy
 * hourly pass triggered by API traffic (this host has no cron, so the lazy path
 * is the one that actually fires). They used to carry different subsets of the
 * rules; keeping the logic here is what stops them drifting apart again.
 */
class Retention
{
    /** Identities that never got anywhere are dropped after this long. */
    public const ABANDONED_AFTER_DAYS = 1;

    /** A file-deletion tombstone only has to outlive the pollers that show it. */
    public const TOMBSTONE_AFTER_HOURS = 1;

    /** @return array<string, int> what was removed, for the command to report */
    public static function prune(): array
    {
        return [
            'channels' => self::expiredChannels(),
            'uploads' => self::expiredUploads(),
            'identities' => self::abandonedIdentities(),
            'tombstones' => self::staleTombstones(),
            'cache' => CacheGc::pruneExpired(),
        ];
    }

    /** Channels that have been silent for longer than their owner allowed. */
    private static function expiredChannels(): int
    {
        return Channel::query()
            ->whereNotNull('last_activity_at')
            ->get()
            ->filter(fn ($ch) => $ch->last_activity_at->lt(now()->subDays($ch->retention_days)))
            ->each->destroyCompletely()
            ->count();
    }

    /**
     * Uploads live for as long as the admin allows, then the file, its
     * thumbnail and the message go. Switched off, they only die with their
     * channel.
     */
    private static function expiredUploads(): int
    {
        $days = Setting::uploadRetentionDays();
        if ($days === null) {
            return 0;
        }

        return Message::whereNotNull('file_path')
            ->where('created_at', '<', now()->subDays($days))
            ->get()
            ->each(function (Message $m) {
                $m->deleteStoredFile();
                $m->delete();
            })
            ->count();
    }

    /**
     * Identities that never became anything: no username, no channel, and
     * nothing seen for a day. That covers a visitor who opened the app and
     * closed it again, and — on a server that vets registrations — a pending
     * row with no name on it, which gives an admin nothing to decide about and
     * only clutters the list.
     *
     * Denied identities are kept regardless: the whole point of a denial is
     * that it sticks, and deleting the row would let that person register
     * again.
     *
     * Devices, notifications and push subscriptions cascade with the user.
     */
    private static function abandonedIdentities(): int
    {
        $cutoff = now()->subDays(self::ABANDONED_AFTER_DAYS);

        return ChatUser::whereNull('username')
            ->where('status', '!=', ChatUser::DENIED)
            ->whereDoesntHave('memberships')
            // Both clocks have to be past the cutoff. `last_seen_at` alone
            // would delete a row that somehow never got one the moment it was
            // written, however new it is.
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->get()
            ->each->delete()
            ->count();
    }

    /** Tombstones exist only to flash the deleter's name at other clients. */
    private static function staleTombstones(): int
    {
        return Message::whereNotNull('file_deleted_at')
            ->where('file_deleted_at', '<', now()->subHours(self::TOMBSTONE_AFTER_HOURS))
            ->delete();
    }
}
