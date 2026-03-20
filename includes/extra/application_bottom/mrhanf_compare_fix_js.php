<?php
/**
 * Mr. Hanf - Produktvergleich Cookie-Fix (JavaScript-Seite) v1.1
 *
 * Ergaenzung zu mrhanf_compare_fix.php:
 *   1. Loescht pc_compare_ids Cookie clientseitig per JavaScript
 *   2. Ruft Ajax sub_action=clear auf um Server-Session zu leeren
 *   3. Setzt Badge-Zaehler sofort auf 0
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

    function clearCompare() {
        // 1. pc_compare_ids Cookie loeschen (alle Domain-Varianten)
        var domains = ['', '.mr-hanf.de', 'mr-hanf.de', 'www.mr-hanf.de'];
        var paths = ['/'];
        domains.forEach(function(domain) {
            paths.forEach(function(path) {
                var cookie = 'pc_compare_ids=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=' + path;
                if (domain) cookie += ';domain=' + domain;
                cookie += ';SameSite=Lax;Secure';
                document.cookie = cookie;
            });
        });

        // 2. ProductCompare-Objekt zuruecksetzen falls vorhanden
        if (window.ProductCompare) {
            try {
                window.ProductCompare.clearCookie();
            } catch(e) {}
        }

        // 3. Server-Session per Ajax leeren
        //    Dies stellt sicher dass die PHP-Session auch wirklich geleert wird
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/ajax.php?ext=product_compare&sub_action=clear', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send();

        // 4. Badge auf 0 setzen
        var badge = document.getElementById('product-compare-badge');
        if (badge) {
            var countEl = badge.querySelector('.compare-count');
            if (countEl) countEl.textContent = '0';
            badge.classList.remove('active');
        }
    }

    // Sofort ausfuehren
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', clearCompare);
    } else {
        clearCompare();
    }
})();
</script>
