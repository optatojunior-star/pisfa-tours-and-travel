{{--
    Everything a browser needs to treat the site as installable.

    Included in the <head> of every layout. The registration script is inline
    and tiny on purpose: it must not wait on a bundle, and a failure to register
    a service worker is never allowed to break the page — a site that works is
    worth more than one that works offline.
--}}
<link rel="manifest" href="{{ route('pwa.manifest', absolute: false) }}">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="PISFA">
<meta name="mobile-web-app-capable" content="yes">

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register(@json(route('pwa.service-worker', absolute: false)), { scope: '/' })
                .catch(function () {
                    // Registration fails on an insecure origin, in a private
                    // window, or where the user has blocked it. None of those
                    // is worth an error the visitor has to read.
                });
        });
    }
</script>
