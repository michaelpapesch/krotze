<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Version (semver)
    |--------------------------------------------------------------------------
    |
    | Bump on every deploy. Shown in the app sidebar and returned by
    | /api/state so running clients can detect an update and offer a reload.
    |
    */

    'version' => '2.15.1',

    // Address shown for abuse reports (create the mailbox on the mail host!)
    'abuse_email' => env('ABUSE_EMAIL', 'abuse@krotze.com'),

    /*
    |--------------------------------------------------------------------------
    | Public-site languages
    |--------------------------------------------------------------------------
    |
    | Every language the static site (landing + pages) is translated into.
    | English lives at the bare URLs and is the x-default; the others are
    | prefixed (/de/…, /ar/…). Each language needs a lang/<code>/site.php
    | mirroring lang/en/site.php. Routes, sitemap, hreflang and the language
    | dropdown are all generated from this list.
    |
    */

    'site_locales' => [
        'en' => ['name' => 'English', 'dir' => 'ltr', 'og' => 'en_US'],
        'de' => ['name' => 'Deutsch', 'dir' => 'ltr', 'og' => 'de_DE'],
        'es' => ['name' => 'Español', 'dir' => 'ltr', 'og' => 'es_ES'],
        'fr' => ['name' => 'Français', 'dir' => 'ltr', 'og' => 'fr_FR'],
        'zh' => ['name' => '中文', 'dir' => 'ltr', 'og' => 'zh_CN'],
        'ar' => ['name' => 'العربية', 'dir' => 'rtl', 'og' => 'ar_AR'],
        'fa' => ['name' => 'فارسی', 'dir' => 'rtl', 'og' => 'fa_IR'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
