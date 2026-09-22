<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizedPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_pages_remain_at_their_original_urls(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Anonymous chat. No account. No traces.')
            ->assertSee('lang="en"', false);
        $this->get('/privacy')->assertOk()->assertSee('Privacy policy');
        $this->get('/terms')->assertOk()->assertSee('Terms of use');
    }

    public function test_every_language_serves_the_landing_page(): void
    {
        $expected = [
            'de' => 'Anonymer Chat', 'es' => 'Chat anónimo', 'fr' => 'Chat anonyme',
            'zh' => '匿名聊天', 'ar' => 'دردشة مجهولة الهوية', 'fa' => 'گفت‌وگوی ناشناس',
        ];
        foreach ($expected as $locale => $needle) {
            $dir = in_array($locale, ['ar', 'fa'], true) ? 'rtl' : 'ltr';
            $this->get('/'.$locale)->assertOk()
                ->assertSee('lang="'.$locale.'" dir="'.$dir.'"', false)
                ->assertSee($needle);
        }
    }

    public function test_every_language_file_mirrors_the_english_structure(): void
    {
        $flatten = function (array $arr, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($arr as $k => $v) {
                is_array($v) && ! array_is_list($v)
                    ? $keys = array_merge($keys, $flatten($v, $prefix.$k.'.'))
                    : $keys[] = $prefix.$k;
            }
            sort($keys);

            return $keys;
        };

        $en = $flatten(require lang_path('en/site.php'));
        foreach (array_keys(config('app.site_locales')) as $locale) {
            $this->assertSame($en, $flatten(require lang_path($locale.'/site.php')),
                "lang/{$locale}/site.php does not mirror lang/en/site.php");
        }
    }

    public function test_language_dropdown_lists_every_language(): void
    {
        $res = $this->get('/faq')->assertOk();
        foreach (config('app.site_locales') as $code => $meta) {
            $res->assertSee($meta['name']);
            $res->assertSee('hreflang="'.$code.'"', false);
        }
    }

    public function test_german_pages_are_served_under_de_prefix(): void
    {
        $this->get('/de')->assertOk()
            ->assertSee('Anonymer Chat. Kein Konto. Keine Spuren.')
            ->assertSee('lang="de"', false);
        $this->get('/de/privacy')->assertOk()->assertSee('Datenschutzerklärung');
        $this->get('/de/terms')->assertOk()->assertSee('Nutzungsbedingungen');
        $this->get('/de/imprint')->assertOk()->assertSee('Impressum');
        $this->get('/de/faq')->assertOk()->assertSee('Häufig gestellte Fragen');
        $this->get('/de/contact')->assertOk()->assertSee('Kontakt');
    }

    public function test_about_page_exists_in_both_languages(): void
    {
        $this->get('/about')->assertOk()
            ->assertSee('About Krotze')
            ->assertSee('right to anonymous communication')
            ->assertSee('Where Krotze fits in')
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('hreflang="de" href="'.url('/de/about').'"', false);
        $this->get('/de/about')->assertOk()
            ->assertSee('Über Krotze')
            ->assertSee('Anrecht auf anonyme Kommunikation')
            ->assertSee('Grundrechtliche Absicherung')
            ->assertSee('Wo Krotze ins Bild passt');
    }

    public function test_pages_carry_hreflang_alternates_and_canonical(): void
    {
        $this->get('/faq')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/faq').'">', false)
            ->assertSee('hreflang="en" href="'.url('/faq').'"', false)
            ->assertSee('hreflang="de" href="'.url('/de/faq').'"', false)
            ->assertSee('hreflang="x-default" href="'.url('/faq').'"', false);

        // The German page points back at the same pair.
        $this->get('/de/faq')->assertOk()
            ->assertSee('hreflang="en" href="'.url('/faq').'"', false)
            ->assertSee('hreflang="de" href="'.url('/de/faq').'"', false);
    }

    public function test_open_graph_tags_are_localized(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('property="og:locale" content="en_US"', false)
            ->assertSee('property="og:locale:alternate" content="de_DE"', false);
        $this->get('/de')->assertOk()
            ->assertSee('property="og:locale" content="de_DE"', false)
            ->assertSee('og:description" content="Krotze — anonyme, private Gruppenchats', false);
    }

    public function test_structured_data_is_present(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('"@type":"WebApplication"', false)
            ->assertSee('"@type":"Organization"', false);

        $faq = $this->get('/de/faq')->assertOk();
        $faq->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('Brauche ich ein Konto?', false);

        $this->get('/privacy')->assertSee('"@type":"BreadcrumbList"', false);
    }

    public function test_language_switcher_links_to_the_counterpart_page(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('href="'.url('/de/privacy').'" hreflang="de"', false);
        $this->get('/de/privacy')->assertOk()
            ->assertSee('href="'.url('/privacy').'" hreflang="en"', false);
    }

    public function test_sitemap_lists_both_languages_with_alternates(): void
    {
        $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<loc>'.url('/privacy').'</loc>', false)
            ->assertSee('<loc>'.url('/de/privacy').'</loc>', false)
            ->assertSee('<loc>'.url('/de/about').'</loc>', false)
            ->assertSee('<loc>'.url('/zh/faq').'</loc>', false)
            ->assertSee('<loc>'.url('/ar').'</loc>', false)
            ->assertSee('<loc>'.url('/fa/terms').'</loc>', false)
            ->assertSee('hreflang="x-default"', false);
    }

    public function test_sitemap_carries_lastmod_dates(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<loc>'.preg_quote(url('/de/faq'), '#').'</loc>\s*<lastmod>\d{4}-\d{2}-\d{2}</lastmod>#', $xml);
        $this->assertSame(substr_count($xml, '<loc>'), substr_count($xml, '<lastmod>'));
    }

    public function test_every_page_has_its_own_localized_description(): void
    {
        $seen = [];
        foreach (['/', '/about', '/faq', '/contact', '/privacy', '/terms', '/imprint'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('#<meta name="description" content="([^"]+)">#', $html, $m);
            $this->assertNotEmpty($m[1] ?? null, "no description on {$path}");
            $this->assertNotContains($m[1], $seen, "description on {$path} duplicates another page");
            $this->assertStringContainsString('<meta property="og:description" content="'.$m[1].'">', $html);
            $this->assertStringContainsString('<meta name="twitter:description" content="'.$m[1].'">', $html);
            $seen[] = $m[1];
        }
        $this->get('/de/faq')->assertOk()
            ->assertSee('name="description" content="'.__('site.faq.description', [], 'de').'"', false);
    }

    public function test_absolute_urls_come_from_app_url_not_the_request_host(): void
    {
        config(['app.url' => 'https://krotze.com']);
        $this->get('http://www.krotze.com/de/faq')->assertOk()
            ->assertSee('<link rel="canonical" href="https://krotze.com/de/faq">', false)
            ->assertSee('hreflang="en" href="https://krotze.com/faq"', false)
            ->assertSee('hreflang="x-default" href="https://krotze.com/faq"', false)
            ->assertSee('property="og:url" content="https://krotze.com/de/faq"', false)
            ->assertSee('property="og:image" content="https://krotze.com/img/og-image.png"', false)
            ->assertSee('"item":"https://krotze.com/de/faq"', false)
            ->assertDontSee('www.krotze.com/de');
        $this->get('http://www.krotze.com/sitemap.xml')->assertOk()
            ->assertSee('<loc>https://krotze.com/</loc>', false)
            ->assertDontSee('www.krotze.com');
    }

    public function test_private_pages_are_noindex_and_carry_no_hreflang(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('hreflang=', false)
            ->assertDontSee('id="lang-menu"', false);
        $this->get('/app')->assertOk()
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertSee('property="og:title"', false);
        $this->get('/faq')->assertOk()->assertDontSee('name="robots"', false);
    }

    public function test_llms_txt_maps_the_public_site(): void
    {
        $res = $this->get('/llms.txt')->assertOk();
        $this->assertStringStartsWith('text/markdown', $res->headers->get('Content-Type'));
        $res->assertSee('# Krotze', false)
            ->assertSee('> '.__('site.meta.description'), false)
            ->assertSee('- [FAQ — Krotze]('.url('/faq').'): '.__('site.faq.description'), false)
            ->assertSee('- [Deutsch]('.url('/de').'): '.__('site.meta.description', [], 'de'), false)
            ->assertSee('('.url('/api/docs').')', false)
            ->assertDontSee('/admin', false);
    }

    public function test_robots_txt_keeps_private_areas_out_but_the_docs_in(): void
    {
        $body = file_get_contents(public_path('robots.txt'));
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /api/', $body);
        $this->assertStringContainsString('Disallow: /u/', $body);
        $this->assertStringContainsString('Allow: /api/docs', $body);
        $this->assertStringContainsString('Sitemap: https://krotze.com/sitemap.xml', $body);
        $this->assertStringNotContainsString("Disallow: /\n", $body);
    }

    public function test_faq_jsonld_is_valid_json(): void
    {
        $html = $this->get('/faq')->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $blob) {
            $this->assertIsArray(json_decode($blob, true), 'Invalid JSON-LD: '.substr($blob, 0, 80));
        }
    }
}
