# FPC Debug-Analyse Notizen

## fpc_serve.php (v7.0.3)

### Positive Befunde
1. **5 Validierungsschichten** korrekt implementiert: Dateigröße, TTL, Health-Marker
2. **Directory Traversal** Schutz via realpath()
3. **Session-Check** korrekt: MODsid und PHPSESSID Cookie-Prüfung
4. **Auto-Delete** korrupter Dateien
5. **X-FPC-Cache: HIT** Header wird gesetzt — das ist der Header den wir im Monitoring prüfen
6. **304/ETag entfernt** (v7.0.3) — gute Entscheidung gegen weiße Seiten bei Artfiles-Proxy

### Potenzielle Probleme
1. **Health-Marker nur in letzten 200 Bytes gesucht** — wenn der Marker nicht am Ende steht, wird er nicht gefunden
2. **Closing-Tag Prüfung (Schicht 4) fehlt im Code!** — README erwähnt 5 Schichten, aber `</html>` / `</body>` Check ist NICHT implementiert
3. **PHP-Fehler Prüfung (Schicht 5) fehlt im Code!** — README erwähnt "Keine Fatal error etc.", aber kein Check im Code
4. **Query-String wird komplett ignoriert** — URLs mit ?page=2 etc. bekommen alle dieselbe Cache-Datei
5. **Kein gzip/deflate** — readfile() liefert unkomprimiert aus, Apache muss mod_deflate übernehmen

### Kritisch: Nur 3 von 5 Schichten implementiert!
- Schicht 1: Mindestgröße ✅
- Schicht 2: Health-Marker ✅ (aber nur letzte 200 Bytes)
- Schicht 3: TTL ✅
- Schicht 4: Closing-Tag ❌ FEHLT
- Schicht 5: PHP-Fehler ❌ FEHLT

## fpc_preloader.php (v7.0.1)

### Positive Befunde
1. **Alle 5 Validierungsschichten IM PRELOADER implementiert** (anders als serve!)
   - Mindestgröße: 1000 Bytes ✅
   - Closing-Tag: </html> oder </body> in letzten 500 Bytes ✅
   - PHP-Fehler: Regex für <b>Fatal error</b> etc. ✅ (auch Smarty-Fehler)
   - Health-Marker wird angehängt ✅
2. **Atomic Write** korrekt: .tmp.PID → rename() ✅
3. **Bestehende gültige Cache-Dateien werden NICHT überschrieben** bei ungültigem Content ✅
4. **Session-IDs (MODsid) werden aus HTML entfernt** ✅
5. **Sitemap als primäre URL-Quelle**, DB als Fallback ✅
6. **Content-Type Prüfung** — nur text/html wird gecacht ✅
7. **50ms Pause** zwischen Requests (schont den Server) ✅

### Potenzielle Probleme
1. **SSL_VERIFYPEER = false** — Sicherheitsrisiko (aber bei Shared Hosting oft nötig)
2. **Timeout nur 15s** — bei TTFB von 8s+ könnten langsame Seiten abbrechen
3. **Keine Retry-Logik** — wenn eine Seite einmal fehlschlägt, wird sie übersprungen
4. **language_id = 2 hardcoded** — nur deutsche URLs werden aus DB geladen
5. **Kein Locking** — wenn 2 Cron-Jobs gleichzeitig laufen, könnten Race Conditions entstehen
6. **Session-ID Regex zu einfach** — `MODsid=` wird entfernt, aber was ist mit URLs die MODsid enthalten?

### Diskrepanz serve vs. preloader
- **Preloader hat 5 Schichten**, serve hat nur 3!
- serve prüft NICHT auf Closing-Tags und PHP-Fehler
- Das bedeutet: Wenn jemand manuell eine korrupte Datei ins Cache-Verzeichnis legt, würde serve sie ausliefern
- **Empfehlung:** Schichten 4+5 auch in serve implementieren

## fpc_flush.php (v6.1.1)

### Positive Befunde
1. **3 Modi:** all, expired, single URL ✅
2. **Recursive Directory Iterator** — löscht auch Unterverzeichnisse ✅
3. **TTL aus DB** für expired-Modus ✅
4. **.gitkeep wird nicht gelöscht** ✅

### Potenzielle Probleme
1. **Noch v6.1.1** — nicht auf v7.0 aktualisiert (aber funktional OK)
2. **Kein Locking** — wenn Flush und Preloader gleichzeitig laufen, Race Condition
3. **Leere Verzeichnisse** bleiben nach single-URL-Löschung zurück

## Admin-Modul mrhanf_fpc.php (v7.0.3)

### Positive Befunde
1. **Frontend-Schutz** — _isAdmin() verhindert schwere Operationen im Frontend ✅
2. **process() Methode** vorhanden — verhindert weiße Seite beim Speichern ✅
3. **Cache-Status Dashboard** im Admin mit Dateizähler, Größe, letzter Cron-Lauf ✅
4. **Flush-Button** mit Bestätigungsdialog ✅
5. **Fallback-Installation** via direkter mysqli wenn xtc_db_query fehlschlägt ✅

### Potenzielle Probleme
1. **_tailFile() liest GESAMTE Datei** — bei großem preloader.log problematisch (Memory)
2. **Flush über GET-Parameter** — CSRF-Risiko (kein Token-Check)
3. **_isAdmin() Fallback** prüft REQUEST_URI auf '/admin' — könnte bei benutzerdefinierten Admin-Pfaden fehlschlagen

## htaccess_fpc_rules.txt (v7.0.3)

### Positive Befunde
1. **Alle RewriteCond für BEIDE Rules wiederholt** — kritischer Fix gegen weiße Seiten ✅
2. **Cookie-Check** für MODsid UND PHPSESSID ✅
3. **Statische Assets ausgeschlossen** (.css, .js, .jpg etc.) ✅
4. **Session-abhängige Seiten ausgeschlossen** ✅
5. **Query-String mit action= ausgeschlossen** ✅

### Potenzielle Probleme
1. **Kein warenkorb-Ausschluss** — nur shopping_cart, aber URL könnte /warenkorb sein (SEO-URL)
2. **Cache-Datei Pfad:** `%{DOCUMENT_ROOT}/cache/fpc/%{REQUEST_URI}/index.html` — bei Trailing-Slash-Problemen könnte der Pfad nicht matchen
3. **Kein Ausschluss für POST-Redirects** — nach einem POST-Redirect könnte ein GET mit Session-Daten kommen
