#!/usr/bin/env python3
"""
FPC Live-Test Suite — Prüft alle FPC-Funktionen gegen den Live-Shop
"""

import requests
import time
import json
import re

BASE_URL = "https://mr-hanf.de"
RESULTS = []

def log(test_name, status, details):
    icon = {"PASS": "✅", "FAIL": "❌", "WARN": "⚠️", "INFO": "ℹ️"}.get(status, "❓")
    print(f"  {icon} [{status}] {test_name}: {details}")
    RESULTS.append({"test": test_name, "status": status, "details": details})

def get_page(url, cookies=None, allow_redirects=True):
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept": "text/html,application/xhtml+xml",
        "Accept-Language": "de-DE,de;q=0.9",
    }
    start = time.time()
    r = requests.get(url, headers=headers, cookies=cookies, timeout=20, allow_redirects=allow_redirects)
    ttfb = time.time() - start
    return r, ttfb

# ============================================================
print("=" * 60)
print("FPC LIVE-TEST SUITE")
print(f"Ziel: {BASE_URL}")
print(f"Zeit: {time.strftime('%Y-%m-%d %H:%M:%S')}")
print("=" * 60)

# ============================================================
# TEST 1: Grundlegende Erreichbarkeit
# ============================================================
print("\n--- TEST 1: Grundlegende Erreichbarkeit ---")

r, ttfb = get_page(BASE_URL + "/")
log("Startseite HTTP-Status", "PASS" if r.status_code == 200 else "FAIL", f"HTTP {r.status_code}")
log("Startseite Body-Größe", "PASS" if len(r.content) > 1000 else "FAIL", f"{len(r.content)} Bytes")
log("Startseite TTFB", "PASS" if ttfb < 3.0 else "WARN", f"{ttfb:.3f}s")

# Prüfe ob Seite Inhalt hat (kein White Page)
has_html = "</html>" in r.text.lower()
log("Startseite hat </html>", "PASS" if has_html else "FAIL", f"{'Ja' if has_html else 'NEIN — mögliche weiße Seite!'}")

# ============================================================
# TEST 2: FPC-Header Prüfung
# ============================================================
print("\n--- TEST 2: FPC-Header Prüfung ---")

fpc_cache = r.headers.get("X-FPC-Cache", "")
fpc_version = r.headers.get("X-FPC-Version", "")
fpc_cached_at = r.headers.get("X-FPC-Cached-At", "")

log("X-FPC-Cache Header", "PASS" if fpc_cache == "HIT" else "WARN" if fpc_cache else "INFO", 
    f"'{fpc_cache}'" if fpc_cache else "Nicht vorhanden — FPC nicht aktiv oder Cache-Miss")
log("X-FPC-Version Header", "PASS" if fpc_version else "INFO", 
    f"'{fpc_version}'" if fpc_version else "Nicht vorhanden")
log("X-FPC-Cached-At Header", "PASS" if fpc_cached_at else "INFO", 
    f"'{fpc_cached_at}'" if fpc_cached_at else "Nicht vorhanden")

# Prüfe FPC-VALID Marker im Body
has_marker = "<!-- FPC-VALID -->" in r.text
log("FPC-VALID Marker im Body", "PASS" if has_marker else "INFO",
    "Vorhanden — Seite wurde vom FPC-Cache ausgeliefert" if has_marker else "Nicht vorhanden — Seite wurde live generiert")

# ============================================================
# TEST 3: Cookie-basiertes Verhalten (Gast vs. Eingeloggt)
# ============================================================
print("\n--- TEST 3: Cookie-basiertes Verhalten ---")

# Test ohne Cookies (Gast)
r_guest, ttfb_guest = get_page(BASE_URL + "/")
guest_fpc = r_guest.headers.get("X-FPC-Cache", "NONE")
log("Gast (ohne Cookie) FPC", "PASS" if guest_fpc == "HIT" else "INFO", f"X-FPC-Cache: {guest_fpc}")

# Test mit MODsid Cookie (simuliert eingeloggten User)
r_logged, ttfb_logged = get_page(BASE_URL + "/", cookies={"MODsid": "test_session_12345"})
logged_fpc = r_logged.headers.get("X-FPC-Cache", "NONE")
log("Eingeloggt (MODsid Cookie) FPC", 
    "PASS" if logged_fpc == "NONE" else "FAIL",
    f"X-FPC-Cache: {logged_fpc} — {'Korrekt: Kein Cache für eingeloggte User' if logged_fpc == 'NONE' else 'FEHLER: Eingeloggte User bekommen Cache!'}")

# TTFB-Vergleich
if guest_fpc == "HIT":
    log("TTFB Gast vs. Eingeloggt", 
        "PASS" if ttfb_guest < ttfb_logged else "WARN",
        f"Gast: {ttfb_guest:.3f}s vs. Eingeloggt: {ttfb_logged:.3f}s")

# ============================================================
# TEST 4: Ausgeschlossene Seiten
# ============================================================
print("\n--- TEST 4: Ausgeschlossene Seiten ---")

excluded_urls = [
    ("/vergleich", "Produktvergleich"),
    ("/warenkorb", "Warenkorb (SEO-URL)"),
    ("/anmelden", "Login/Anmelden"),
]

for url, name in excluded_urls:
    try:
        r_ex, _ = get_page(BASE_URL + url)
        ex_fpc = r_ex.headers.get("X-FPC-Cache", "NONE")
        log(f"Ausschluss: {name} ({url})", 
            "PASS" if ex_fpc == "NONE" else "FAIL",
            f"X-FPC-Cache: {ex_fpc} — {'Korrekt: Nicht gecacht' if ex_fpc == 'NONE' else 'FEHLER: Sollte nicht gecacht sein!'}")
    except Exception as e:
        log(f"Ausschluss: {name} ({url})", "WARN", f"Fehler: {str(e)[:80]}")

# ============================================================
# TEST 5: Kategorie-Seiten FPC-Status
# ============================================================
print("\n--- TEST 5: Kategorie-Seiten FPC-Status ---")

category_urls = [
    ("/samen-shop/", "Samen Shop"),
    ("/samen-shop/autoflowering-samen/", "Autoflowering"),
    ("/samen-shop/feminisierte-samen/", "Feminisierte"),
    ("/samen-shop/regulaere-samen/", "Reguläre"),
    ("/cannabispflanzen/", "Cannabispflanzen"),
    ("/growshop/", "Growshop"),
    ("/headshop/", "Headshop"),
    ("/blog/", "Blog"),
]

fpc_hits = 0
fpc_total = 0
ttfb_list = []

for url, name in category_urls:
    try:
        r_cat, ttfb_cat = get_page(BASE_URL + url)
        cat_fpc = r_cat.headers.get("X-FPC-Cache", "NONE")
        cat_marker = "<!-- FPC-VALID -->" in r_cat.text
        body_size = len(r_cat.content)
        has_closing = "</html>" in r_cat.text.lower()
        
        fpc_total += 1
        if cat_fpc == "HIT" or cat_marker:
            fpc_hits += 1
        ttfb_list.append(ttfb_cat)
        
        status = "PASS" if r_cat.status_code == 200 and body_size > 1000 and has_closing else "FAIL"
        cache_info = f"FPC: {cat_fpc}" + (" + Marker" if cat_marker else "")
        log(f"{name} ({url})", status, 
            f"HTTP {r_cat.status_code} | {body_size}B | TTFB {ttfb_cat:.3f}s | {cache_info}")
        time.sleep(0.3)
    except Exception as e:
        log(f"{name} ({url})", "FAIL", f"Fehler: {str(e)[:80]}")

# ============================================================
# TEST 6: Sitemap Verfügbarkeit
# ============================================================
print("\n--- TEST 6: Sitemap Verfügbarkeit ---")

try:
    r_sitemap, _ = get_page(BASE_URL + "/sitemap.xml")
    has_sitemap = r_sitemap.status_code == 200 and len(r_sitemap.content) > 100
    if has_sitemap:
        url_count = r_sitemap.text.count("<loc>")
        is_index = "<sitemapindex" in r_sitemap.text
        log("Sitemap", "PASS", 
            f"HTTP {r_sitemap.status_code} | {len(r_sitemap.content)} Bytes | {'Sitemap-Index' if is_index else 'Einfache Sitemap'} | {url_count} <loc> Einträge")
    else:
        log("Sitemap", "WARN", f"HTTP {r_sitemap.status_code} | {len(r_sitemap.content)} Bytes — Preloader muss DB-Fallback nutzen")
except Exception as e:
    log("Sitemap", "FAIL", f"Fehler: {str(e)[:80]}")

# ============================================================
# TEST 7: Weiße-Seiten-Schutz
# ============================================================
print("\n--- TEST 7: Weiße-Seiten-Schutz ---")

# Prüfe mehrere Seiten auf weiße Seiten
white_page_count = 0
test_urls_wp = ["/", "/samen-shop/", "/growshop/", "/blog/"]

for url in test_urls_wp:
    try:
        r_wp, _ = get_page(BASE_URL + url)
        is_white = len(r_wp.content) < 500 or "</html>" not in r_wp.text.lower()
        if is_white:
            white_page_count += 1
        log(f"White-Page-Check {url}", 
            "PASS" if not is_white else "FAIL",
            f"{len(r_wp.content)} Bytes | {'OK' if not is_white else 'WEISSE SEITE!'}")
    except Exception as e:
        log(f"White-Page-Check {url}", "FAIL", f"Fehler: {str(e)[:80]}")

# ============================================================
# TEST 8: Cache-Control Header
# ============================================================
print("\n--- TEST 8: Cache-Control Header ---")

r_cc, _ = get_page(BASE_URL + "/")
cc = r_cc.headers.get("Cache-Control", "")
pragma = r_cc.headers.get("Pragma", "")
expires = r_cc.headers.get("Expires", "")

log("Cache-Control Header", "INFO", f"'{cc}'")
log("Pragma Header", "INFO", f"'{pragma}'")
log("Expires Header", "INFO", f"'{expires}'")

# Wenn FPC aktiv: sollte no-store sein (v7.0.3)
if "X-FPC-Cache" in r_cc.headers:
    log("FPC Cache-Control = no-store", 
        "PASS" if "no-store" in cc else "WARN",
        f"{'Korrekt: Browser cached FPC-Antwort nicht' if 'no-store' in cc else 'WARNUNG: Browser könnte FPC-Antwort cachen!'}")

# ============================================================
# TEST 9: PHP-Fehler im Response
# ============================================================
print("\n--- TEST 9: PHP-Fehler im Response ---")

php_errors_found = []
for url in ["/", "/samen-shop/", "/blog/"]:
    try:
        r_php, _ = get_page(BASE_URL + url)
        for err_pattern in ["Fatal error", "Parse error", "Warning:", "Notice:", "Smarty error"]:
            if err_pattern in r_php.text:
                php_errors_found.append(f"{url}: {err_pattern}")
    except:
        pass

log("PHP-Fehler in Responses", 
    "PASS" if not php_errors_found else "FAIL",
    "Keine PHP-Fehler gefunden" if not php_errors_found else f"FEHLER: {'; '.join(php_errors_found)}")

# ============================================================
# TEST 10: Session-ID Leak
# ============================================================
print("\n--- TEST 10: Session-ID Leak ---")

r_sid, _ = get_page(BASE_URL + "/")
sid_leak = re.search(r'MODsid=[a-zA-Z0-9]{20,}', r_sid.text)
log("Session-ID Leak im HTML", 
    "PASS" if not sid_leak else "FAIL",
    "Keine Session-IDs im HTML" if not sid_leak else f"LEAK: {sid_leak.group()[:50]}")

# ============================================================
# ZUSAMMENFASSUNG
# ============================================================
print("\n" + "=" * 60)
print("ZUSAMMENFASSUNG")
print("=" * 60)

pass_count = sum(1 for r in RESULTS if r["status"] == "PASS")
fail_count = sum(1 for r in RESULTS if r["status"] == "FAIL")
warn_count = sum(1 for r in RESULTS if r["status"] == "WARN")
info_count = sum(1 for r in RESULTS if r["status"] == "INFO")

print(f"  ✅ PASS: {pass_count}")
print(f"  ❌ FAIL: {fail_count}")
print(f"  ⚠️ WARN: {warn_count}")
print(f"  ℹ️ INFO: {info_count}")

if fpc_total > 0:
    hit_rate = fpc_hits / fpc_total * 100
    avg_ttfb = sum(ttfb_list) / len(ttfb_list) if ttfb_list else 0
    print(f"\n  FPC Cache HIT-Rate: {hit_rate:.1f}% ({fpc_hits}/{fpc_total})")
    print(f"  Durchschn. TTFB: {avg_ttfb:.3f}s")

print(f"  Weiße Seiten: {white_page_count}")

# JSON-Ergebnis speichern
output = {
    "timestamp": time.strftime("%Y-%m-%d %H:%M:%S"),
    "base_url": BASE_URL,
    "summary": {
        "pass": pass_count,
        "fail": fail_count,
        "warn": warn_count,
        "info": info_count,
        "fpc_hit_rate": f"{hit_rate:.1f}%" if fpc_total > 0 else "N/A",
        "avg_ttfb": f"{avg_ttfb:.3f}s" if ttfb_list else "N/A",
        "white_pages": white_page_count,
    },
    "results": RESULTS,
}

with open("/home/ubuntu/guthaben/monitoring/fpc_live_test_results.json", "w") as f:
    json.dump(output, f, indent=2, ensure_ascii=False)

print(f"\nErgebnisse gespeichert: /home/ubuntu/guthaben/monitoring/fpc_live_test_results.json")
