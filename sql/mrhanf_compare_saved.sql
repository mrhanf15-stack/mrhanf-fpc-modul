-- Mr. Hanf Produktvergleich: Gespeicherte Vergleichslisten pro Kunde
--
-- OPTIONAL: Diese Tabelle ermoeglicht es, die Vergleichsliste eines
-- angemeldeten Kunden dauerhaft zu speichern. Wenn ein Kunde sich
-- anmeldet, wird seine gespeicherte Vergleichsliste wiederhergestellt.
--
-- Installation: Dieses SQL in phpMyAdmin oder per SSH ausfuehren.
-- Die Tabelle wird NUR benoetigt wenn die "Vergleich nach Login
-- wiederherstellen"-Funktion genutzt werden soll.
--
-- Ohne diese Tabelle funktioniert der Cookie-Fix trotzdem:
-- Der Cookie wird beim Abmelden geloescht. Beim Anmelden startet
-- der Kunde mit einer leeren Vergleichsliste.

CREATE TABLE IF NOT EXISTS `mrhanf_compare_saved` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `customers_id`  INT(11)      NOT NULL,
    `products_ids`  VARCHAR(500) NOT NULL DEFAULT '',
    `date_updated`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `customers_id` (`customers_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
