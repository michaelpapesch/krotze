<?php

namespace App\Console\Commands;

use App\Support\WebPush;
use Illuminate\Console\Command;

class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid {--force : Overwrite an existing keypair in .env}';

    protected $description = 'Generate the VAPID keypair used to sign Web Push notifications';

    public function handle(): int
    {
        if (WebPush::configured() && ! $this->option('force')) {
            $this->warn('This server already has a VAPID keypair.');
            $this->line('Replacing it silently unsubscribes every device that is already');
            $this->line('registered — their subscriptions were issued against the old key');
            $this->line('and the push services will refuse the new one.');
            $this->newLine();
            $this->line('Re-run with --force if that is what you want.');

            return self::FAILURE;
        }

        try {
            $keys = WebPush::generateVapidKeys();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->newLine();
            $this->line('Hosts without an openssl.cnf cannot generate EC keys. The app ships');
            $this->line('one at resources/openssl.cnf; check that it exists and is readable,');
            $this->line('or point OPENSSL_CONF_PATH at another config file.');

            return self::FAILURE;
        }

        if ($this->writeEnv($keys)) {
            $this->info('VAPID keypair written to .env.');
            $this->line('Run `php artisan config:clear` if your config is cached.');
        } else {
            $this->info('VAPID keypair generated. Add these to your .env:');
            $this->newLine();
            $this->line('VAPID_PUBLIC_KEY='.$keys['public']);
            $this->line('VAPID_PRIVATE_KEY='.$keys['private']);
        }

        $this->newLine();
        $this->line('The private key never leaves the server. The public key is handed to');
        $this->line('clients through GET /api/push/key and is not a secret.');

        return self::SUCCESS;
    }

    /** Rewrite the two keys in .env, leaving every other line untouched. */
    private function writeEnv(array $keys): bool
    {
        $path = base_path('.env');
        if (! is_writable($path)) {
            return false;
        }

        $env = file_get_contents($path);
        foreach (['VAPID_PUBLIC_KEY' => $keys['public'], 'VAPID_PRIVATE_KEY' => $keys['private']] as $name => $value) {
            $line = $name.'='.$value;
            $env = preg_match('/^'.$name.'=.*$/m', $env)
                ? preg_replace('/^'.$name.'=.*$/m', $line, $env)
                : rtrim($env, "\n")."\n".$line."\n";
        }

        return file_put_contents($path, $env) !== false;
    }
}
