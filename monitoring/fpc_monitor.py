#!/usr/bin/env python3
"""
Mr. Hanf FPC Monitoring Script v1.0
====================================
Überwacht den FPC-Status des mr-hanf.de Shops.

Prüft:
- HTTP Status Codes (200, 301, 404, 500 etc.)
- FPC Cache HIT/MISS (X-FPC-Cache Header)
- TTFB (Time To First Byte)
- Weiße Seiten (leere oder zu kleine Responses)
- FPC-VALID Health-Marker
- PHP-Fehler im Response
- Closing-Tags (</html>, </body>)

Ergebnisse werden in /home/ubuntu/guthaben/monitoring/fpc_monitor_results/ gespeichert.
State wird in /home/ubuntu/guthaben/monitoring/fpc_monitor_state.json verwaltet.
Kritische Alerts in /home/ubuntu/guthaben/monitoring/fpc_critical_alerts.txt.
"""

import json
import os
import sys
import time
import datetime
import requests
from pathlib import Path

# === Konfiguration ===
BASE_URL = "https://www.mr-hanf.de"
MONITOR_DIR = Path("/home/ubuntu/guthaben/monitoring")
RESULTS_DIR = MONITOR_DIR / "fpc_monitor_results"
STATE_FILE = MONITOR_DIR / "fpc_monitor_state.json"
ALERTS_FILE = MONITOR_DIR / "fpc_critical_alerts.txt"
MAX_RUNS = 50

# Symlinks für Playbook-Kompatibilität
SYMLINK_STATE = Path("/home/ubuntu/fpc_monitor_state.json")
SYMLINK_ALERTS = Path("/home/ubuntu/fpc_critical_alerts.txt")
SYMLINK_RESULTS = Path("/home/ubuntu/fpc_monitor_results")

# URLs zum Testen (Mix aus Startseite, Kategorien, Produktseiten)
TEST_URLS = [
    "/",
    "/Hanfsamen/",
    "/Feminisierte-Hanfsamen/",
    "/Autoflowering-Hanfsamen/",
    "/CBD-Hanfsamen/",
    "/Regulaere-Hanfsamen/",
    "/Hanf-Stecklinge/",
    "/Zubehoer/",
    "/Blog/",
    "/neue-produkte/",
]

# Schwellenwerte
MIN_BODY_SIZE = 500          # Bytes — unter 500 = weiße Seite
TTFB_WARNING_THRESHOLD = 1.0  # Sekunden
TTFB_CRITICAL_THRESHOLD = 3.0 # Sekunden
REQUEST_TIMEOUT = 15          # Sekunden


def create_symlinks():
    """Erstellt Symlinks im Home-Verzeichnis für Playbook-Kompatibilität."""
    for src, dst in [
        (STATE_FILE, SYMLINK_STATE),
        (ALERTS_FILE, SYMLINK_ALERTS),
        (RESULTS_DIR, SYMLINK_RESULTS),
    ]:
        if dst.is_symlink() or dst.exists():
            dst.unlink() if not dst.is_dir() else os.remove(str(dst))
        try:
            dst.symlink_to(src)
        except Exception:
            pass


def load_state():
    """Lädt den aktuellen State oder erstellt einen neuen."""
    if STATE_FILE.exists():
        with open(STATE_FILE, "r") as f:
            return json.load(f)
    return {
        "run_count": 0,
        "first_run": None,
        "last_run": None,
        "total_critical": 0,
        "total_warnings": 0,
        "total_checks": 0,
        "cache_hits": 0,
        "cache_misses": 0,
        "white_pages_detected": 0,
        "http_500_detected": 0,
        "avg_ttfb_history": [],
    }


def save_state(state):
    """Speichert den State."""
    with open(STATE_FILE, "w") as f:
        json.dump(state, f, indent=2, ensure_ascii=False)


def check_url(url):
    """Prüft eine einzelne URL und gibt die Ergebnisse zurück."""
    full_url = BASE_URL + url
    result = {
        "url": url,
        "full_url": full_url,
        "http_status": None,
        "ttfb": None,
        "body_size": 0,
        "fpc_cache": "UNKNOWN",
        "has_health_marker": False,
        "has_closing_tag": False,
        "has_php_errors": False,
        "is_white_page": False,
        "issues": [],
        "severity": "OK",  # OK, WARNING, CRITICAL
    }

    try:
        headers = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "de-DE,de;q=0.9,en;q=0.5",
        }

        start_time = time.time()
        response = requests.get(
            full_url,
            headers=headers,
            timeout=REQUEST_TIMEOUT,
            allow_redirects=True,
        )
        ttfb = time.time() - start_time

        result["http_status"] = response.status_code
        result["ttfb"] = round(ttfb, 3)
        result["body_size"] = len(response.content)
        body_text = response.text

        # FPC Cache Header prüfen
        fpc_header = response.headers.get("X-FPC-Cache", "")
        if not fpc_header:
            fpc_header = response.headers.get("X-Cache", "")
        if not fpc_header:
            fpc_header = response.headers.get("X-Proxy-Cache", "")
        if "HIT" in fpc_header.upper():
            result["fpc_cache"] = "HIT"
        elif "MISS" in fpc_header.upper():
            result["fpc_cache"] = "MISS"
        elif fpc_header:
            result["fpc_cache"] = fpc_header
        else:
            # Prüfe ob FPC-VALID Marker im Body ist (= wurde vom Cache ausgeliefert)
            if "<!-- FPC-VALID -->" in body_text:
                result["fpc_cache"] = "HIT (Marker)"
            else:
                result["fpc_cache"] = "NO-HEADER"

        # Health-Marker prüfen
        result["has_health_marker"] = "<!-- FPC-VALID -->" in body_text

        # Closing-Tag prüfen
        result["has_closing_tag"] = "</html>" in body_text.lower() or "</body>" in body_text.lower()

        # PHP-Fehler prüfen
        php_errors = ["Fatal error", "Warning:", "Parse error", "Notice:"]
        for err in php_errors:
            if err in body_text:
                result["has_php_errors"] = True
                result["issues"].append(f"PHP-Fehler gefunden: {err}")
                break

        # === Severity-Bewertung ===

        # CRITICAL: HTTP 500
        if response.status_code >= 500:
            result["severity"] = "CRITICAL"
            result["issues"].append(f"HTTP {response.status_code} Server Error")

        # CRITICAL: Weiße Seite
        if result["body_size"] < MIN_BODY_SIZE:
            result["is_white_page"] = True
            result["severity"] = "CRITICAL"
            result["issues"].append(f"Weiße Seite! Body nur {result['body_size']} Bytes (< {MIN_BODY_SIZE})")

        # CRITICAL: PHP-Fehler
        if result["has_php_errors"]:
            result["severity"] = "CRITICAL"

        # WARNING: Kein Closing-Tag (aber genug Content)
        if not result["has_closing_tag"] and result["body_size"] >= MIN_BODY_SIZE:
            if result["severity"] != "CRITICAL":
                result["severity"] = "WARNING"
            result["issues"].append("Kein </html> oder </body> Tag gefunden")

        # WARNING: Hoher TTFB
        if ttfb > TTFB_CRITICAL_THRESHOLD:
            if result["severity"] != "CRITICAL":
                result["severity"] = "CRITICAL"
            result["issues"].append(f"TTFB kritisch: {ttfb:.3f}s (> {TTFB_CRITICAL_THRESHOLD}s)")
        elif ttfb > TTFB_WARNING_THRESHOLD:
            if result["severity"] == "OK":
                result["severity"] = "WARNING"
            result["issues"].append(f"TTFB hoch: {ttfb:.3f}s (> {TTFB_WARNING_THRESHOLD}s)")

        # WARNING: HTTP 4xx (außer 404 auf erwarteten Seiten)
        if 400 <= response.status_code < 500:
            if result["severity"] == "OK":
                result["severity"] = "WARNING"
            result["issues"].append(f"HTTP {response.status_code} Client Error")

    except requests.exceptions.Timeout:
        result["severity"] = "CRITICAL"
        result["issues"].append(f"Timeout nach {REQUEST_TIMEOUT}s — Seite nicht erreichbar")
    except requests.exceptions.ConnectionError as e:
        result["severity"] = "CRITICAL"
        result["issues"].append(f"Verbindungsfehler: {str(e)[:100]}")
    except Exception as e:
        result["severity"] = "CRITICAL"
        result["issues"].append(f"Unerwarteter Fehler: {str(e)[:100]}")

    return result


def generate_report(run_number, results, timestamp):
    """Generiert einen Markdown-Report für diesen Durchlauf."""
    critical_count = sum(1 for r in results if r["severity"] == "CRITICAL")
    warning_count = sum(1 for r in results if r["severity"] == "WARNING")
    ok_count = sum(1 for r in results if r["severity"] == "OK")

    cache_hits = sum(1 for r in results if "HIT" in r["fpc_cache"])
    cache_misses = sum(1 for r in results if r["fpc_cache"] in ("MISS", "NO-HEADER"))
    total_with_cache = cache_hits + cache_misses
    hit_rate = (cache_hits / total_with_cache * 100) if total_with_cache > 0 else 0

    ttfb_values = [r["ttfb"] for r in results if r["ttfb"] is not None]
    avg_ttfb = sum(ttfb_values) / len(ttfb_values) if ttfb_values else 0
    min_ttfb = min(ttfb_values) if ttfb_values else 0
    max_ttfb = max(ttfb_values) if ttfb_values else 0

    white_pages = sum(1 for r in results if r["is_white_page"])
    http_500 = sum(1 for r in results if r["http_status"] and r["http_status"] >= 500)

    report = f"""# FPC Monitoring Report — Durchlauf #{run_number}

**Zeitpunkt:** {timestamp}
**Basis-URL:** {BASE_URL}
**Geprüfte URLs:** {len(results)}

## Zusammenfassung

| Metrik | Wert |
|---|---|
| Kritische Probleme | {critical_count} |
| Warnungen | {warning_count} |
| OK | {ok_count} |
| FPC Cache HIT-Rate | {hit_rate:.1f}% ({cache_hits}/{total_with_cache}) |
| Durchschn. TTFB | {avg_ttfb:.3f}s |
| Min TTFB | {min_ttfb:.3f}s |
| Max TTFB | {max_ttfb:.3f}s |
| Weiße Seiten | {white_pages} |
| HTTP 500 Fehler | {http_500} |

## Detail-Ergebnisse

| URL | Status | TTFB | FPC Cache | Body | Severity | Probleme |
|---|---|---|---|---|---|---|
"""

    for r in results:
        issues_str = "; ".join(r["issues"]) if r["issues"] else "—"
        severity_icon = {"OK": "✅", "WARNING": "⚠️", "CRITICAL": "🔴"}.get(r["severity"], "❓")
        report += f"| `{r['url']}` | {r['http_status'] or 'N/A'} | {r['ttfb'] or 'N/A'}s | {r['fpc_cache']} | {r['body_size']}B | {severity_icon} {r['severity']} | {issues_str} |\n"

    if critical_count > 0:
        report += "\n## Kritische Probleme\n\n"
        for r in results:
            if r["severity"] == "CRITICAL":
                report += f"### 🔴 {r['url']}\n"
                for issue in r["issues"]:
                    report += f"- {issue}\n"
                report += "\n"

    return report, {
        "critical_count": critical_count,
        "warning_count": warning_count,
        "ok_count": ok_count,
        "hit_rate": hit_rate,
        "avg_ttfb": avg_ttfb,
        "white_pages": white_pages,
        "http_500": http_500,
        "cache_hits": cache_hits,
        "cache_misses": cache_misses,
    }


def update_critical_alerts(run_number, timestamp, results):
    """Aktualisiert die Critical-Alerts-Datei."""
    critical_results = [r for r in results if r["severity"] == "CRITICAL"]

    with open(ALERTS_FILE, "w") as f:
        f.write(f"# FPC Critical Alerts — Durchlauf #{run_number}\n")
        f.write(f"# Zeitpunkt: {timestamp}\n")
        f.write(f"# Anzahl kritische Probleme: {len(critical_results)}\n\n")

        if not critical_results:
            f.write("KEINE KRITISCHEN PROBLEME GEFUNDEN.\n")
        else:
            for r in critical_results:
                f.write(f"CRITICAL: {r['url']} — HTTP {r['http_status']} — {r['body_size']}B\n")
                for issue in r["issues"]:
                    f.write(f"  → {issue}\n")
                f.write("\n")


def generate_final_report(state):
    """Generiert den Gesamtbericht nach 50 Durchläufen."""
    total_checks = state["total_checks"]
    total_hits = state["cache_hits"]
    total_misses = state["cache_misses"]
    total_cache = total_hits + total_misses
    overall_hit_rate = (total_hits / total_cache * 100) if total_cache > 0 else 0

    avg_ttfb_list = state.get("avg_ttfb_history", [])
    overall_avg_ttfb = sum(avg_ttfb_list) / len(avg_ttfb_list) if avg_ttfb_list else 0

    report = f"""# FPC Monitoring — Gesamtbericht

**Monitoring-Zeitraum:** {state['first_run']} bis {state['last_run']}
**Durchläufe:** {state['run_count']} / {MAX_RUNS}
**Gesamte URL-Checks:** {total_checks}

## Gesamtergebnisse

| Metrik | Wert |
|---|---|
| Gesamte kritische Probleme | {state['total_critical']} |
| Gesamte Warnungen | {state['total_warnings']} |
| Gesamt FPC Cache HIT-Rate | {overall_hit_rate:.1f}% |
| Durchschn. TTFB (über alle Durchläufe) | {overall_avg_ttfb:.3f}s |
| Weiße Seiten insgesamt | {state['white_pages_detected']} |
| HTTP 500 Fehler insgesamt | {state['http_500_detected']} |

## Bewertung

"""
    if state["white_pages_detected"] == 0 and state["http_500_detected"] == 0:
        report += "**Ergebnis: STABIL** — Keine weißen Seiten oder HTTP 500 Fehler während des gesamten Monitoring-Zeitraums.\n\n"
    else:
        report += f"**Ergebnis: PROBLEME ERKANNT** — {state['white_pages_detected']} weiße Seiten und {state['http_500_detected']} HTTP 500 Fehler.\n\n"

    if overall_hit_rate >= 80:
        report += f"**FPC Cache:** Sehr gut — {overall_hit_rate:.1f}% HIT-Rate.\n"
    elif overall_hit_rate >= 50:
        report += f"**FPC Cache:** Akzeptabel — {overall_hit_rate:.1f}% HIT-Rate. Optimierung empfohlen.\n"
    else:
        report += f"**FPC Cache:** Schlecht — {overall_hit_rate:.1f}% HIT-Rate. FPC-Konfiguration prüfen!\n"

    if overall_avg_ttfb < 0.5:
        report += f"**TTFB:** Exzellent — {overall_avg_ttfb:.3f}s Durchschnitt.\n"
    elif overall_avg_ttfb < 1.0:
        report += f"**TTFB:** Gut — {overall_avg_ttfb:.3f}s Durchschnitt.\n"
    elif overall_avg_ttfb < 2.0:
        report += f"**TTFB:** Verbesserungswürdig — {overall_avg_ttfb:.3f}s Durchschnitt.\n"
    else:
        report += f"**TTFB:** Kritisch langsam — {overall_avg_ttfb:.3f}s Durchschnitt!\n"

    report += "\n---\n*Monitoring abgeschlossen.*\n"

    final_path = RESULTS_DIR / "FINAL_REPORT.md"
    with open(final_path, "w") as f:
        f.write(report)

    return report, final_path


def main():
    """Hauptfunktion — führt einen Monitoring-Durchlauf aus."""
    # Verzeichnisse sicherstellen
    RESULTS_DIR.mkdir(parents=True, exist_ok=True)

    # Symlinks erstellen
    create_symlinks()

    # State laden
    state = load_state()

    # Prüfen ob Monitoring abgeschlossen
    if state["run_count"] >= MAX_RUNS:
        print(f"MONITORING ABGESCHLOSSEN: {state['run_count']}/{MAX_RUNS} Durchläufe erreicht.")
        report, path = generate_final_report(state)
        print(f"Gesamtbericht: {path}")
        return

    # Durchlauf-Nummer erhöhen
    state["run_count"] += 1
    run_number = state["run_count"]
    timestamp = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    if state["first_run"] is None:
        state["first_run"] = timestamp
    state["last_run"] = timestamp

    print(f"=== FPC Monitoring Durchlauf #{run_number}/{MAX_RUNS} ===")
    print(f"Zeitpunkt: {timestamp}")
    print(f"Prüfe {len(TEST_URLS)} URLs auf {BASE_URL}...")
    print()

    # URLs prüfen
    results = []
    for url in TEST_URLS:
        print(f"  Prüfe {url} ...", end=" ", flush=True)
        result = check_url(url)
        severity_icon = {"OK": "✅", "WARNING": "⚠️", "CRITICAL": "🔴"}.get(result["severity"], "❓")
        print(f"{severity_icon} {result['severity']} — HTTP {result['http_status']} — TTFB {result['ttfb']}s — Cache: {result['fpc_cache']}")
        results.append(result)
        time.sleep(0.5)  # Kurze Pause zwischen Requests

    # Report generieren
    report, stats = generate_report(run_number, results, timestamp)

    # Report speichern
    report_filename = f"run_{run_number:03d}.md"
    report_path = RESULTS_DIR / report_filename
    with open(report_path, "w") as f:
        f.write(report)
    print(f"\nReport gespeichert: {report_path}")

    # Critical Alerts aktualisieren
    update_critical_alerts(run_number, timestamp, results)
    print(f"Alerts aktualisiert: {ALERTS_FILE}")

    # State aktualisieren
    state["total_critical"] += stats["critical_count"]
    state["total_warnings"] += stats["warning_count"]
    state["total_checks"] += len(results)
    state["cache_hits"] += stats["cache_hits"]
    state["cache_misses"] += stats["cache_misses"]
    state["white_pages_detected"] += stats["white_pages"]
    state["http_500_detected"] += stats["http_500"]
    state["avg_ttfb_history"].append(stats["avg_ttfb"])
    save_state(state)
    print(f"State gespeichert: {STATE_FILE}")

    # Zusammenfassung ausgeben
    print(f"\n=== Zusammenfassung Durchlauf #{run_number} ===")
    print(f"  Kritisch: {stats['critical_count']} | Warnungen: {stats['warning_count']} | OK: {stats['ok_count']}")
    print(f"  FPC HIT-Rate: {stats['hit_rate']:.1f}%")
    print(f"  Durchschn. TTFB: {stats['avg_ttfb']:.3f}s")
    print(f"  Weiße Seiten: {stats['white_pages']} | HTTP 500: {stats['http_500']}")

    if state["run_count"] >= MAX_RUNS:
        print(f"\n*** MONITORING ABGESCHLOSSEN — {MAX_RUNS} Durchläufe erreicht ***")
        generate_final_report(state)


if __name__ == "__main__":
    main()
