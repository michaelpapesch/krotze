<?php

namespace App\Console\Commands;

use App\Support\Live\Server;
use Illuminate\Console\Command;

class ServeLive extends Command
{
    protected $signature = 'live:serve';

    protected $description = 'Run the WebSocket relay for live voice, camera and screen streams';

    public function handle(): int
    {
        if (! config('live.url')) {
            $this->warn('LIVE_URL is not set: clients are not told about this server. Set it to the public ws(s):// address.');
        }

        $server = new Server(fn (string $line) => $this->line('['.date('H:i:s').'] '.$line));

        // A taken port means another instance is serving already — the normal
        // case when the scheduler tries to start this once a minute.
        if (! $server->listen()) {
            $this->line('Port '.config('live.port').' is in use; assuming the live server is already running.');

            return self::SUCCESS;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $server->stop());
            pcntl_signal(SIGINT, fn () => $server->stop());
        }

        $this->info('Live server listening on '.config('live.host').':'.config('live.port')
            .(config('live.tls_cert') ? ' (TLS)' : ''));
        $server->run();

        return self::SUCCESS;
    }
}
