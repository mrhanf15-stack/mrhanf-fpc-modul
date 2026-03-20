<?php
/**
 * Mr. Hanf - Produktvergleich Cookie-Fix (JavaScript-Seite)
 *
 * Ergaenzung zu mrhanf_compare_fix.php:
 * Loescht den pc_compare_ids Cookie auch clientseitig per JavaScript,
 * wenn der Benutzer die Logoff-Seite aufruft. Dies ist notwendig, weil
 * der Cookie kein HttpOnly-Flag hat (JavaScript muss ihn lesen koennen).
 *
 * Installationspfad: includes/extra/application_bottom/mrhanf_compare_fix_js.php
 */

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

if (!isset($PHP_SELF)) {
    return;
}

$current_page = basename($PHP_SELF, '.php');

// Nur auf der Logoff-Seite ausgeben
if ($current_page !== 'logoff') {
    return;
}
?>
<script>
(function() {
    'use strict';
    // pc_compare_ids Cookie loeschen (clientseitig)
    var domains = ['', '.mr-hanf.de', 'mr-hanf.de', 'www.mr-hanf.de'];
    var paths = ['/', ''];
    domains.forEach(function(domain) {
        paths.forEach(function(path) {
            var cookie = 'pc_compare_ids=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=' + (path || '/');
            if (domain) cookie += ';domain=' + domain;
            cookie += ';SameSite=Lax';
            document.cookie = cookie;
        });
    });

    // ProductCompare-Objekt zuruecksetzen falls vorhanden
    if (window.ProductCompare) {
        try {
            window.ProductCompare.clearCookie();
        } catch(e) {}
    }

    // Badge auf 0 setzen
    var badge = document.getElementById('product-compare-badge');
    if (badge) {
        var countEl = badge.querySelector('.compare-count');
        if (countEl) countEl.textContent = '0';
        badge.classList.remove('active');
    }
})();
</script>
