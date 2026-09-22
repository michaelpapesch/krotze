<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class CacheGc
{
    /**
     * Drop expired rows from the database cache store.
     *
     * That store only evicts an entry when the same key is read again — which
     * never happens for the single-use nonces every signed request parks there
     * (see ChatAuth), so without this the table would grow without bound.
     */
    public static function pruneExpired(): int
    {
        if (config('cache.default') !== 'database') {
            return 0;
        }

        return DB::connection(config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.table', 'cache'))
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();
    }
}
