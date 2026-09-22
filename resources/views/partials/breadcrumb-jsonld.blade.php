{{-- BreadcrumbList structured data: Home → current page. Expects $pageTitle. --}}
@php
$breadcrumbJsonld = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Krotze',
            'item' => \App\Support\SitePages::url(app()->getLocale(), '')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $pageTitle,
            'item' => \App\Support\SitePages::absolute(request()->path())],
    ],
];
@endphp
<script type="application/ld+json">{!! json_encode($breadcrumbJsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
