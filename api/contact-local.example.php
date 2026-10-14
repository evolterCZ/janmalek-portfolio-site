<?php
/**
 * VZOR nastavení kontaktního formuláře pro server.
 *
 * JAK POUŽÍT
 * 1. Zkopíruj tento soubor na serveru vedle contact.php a přejmenuj na:
 *      contact-local.php
 * 2. Vyplň heslo ke schránce (a případně upravi hosta).
 * 3. Hotovo — contact.php si ho načte sám.
 *
 * Tento soubor se NIKDY nepřepisuje novým buildem webu, takže heslo
 * přežije každé další nahrání složky web/ na FTP.
 *
 * Heslo je vidět jen na serveru; PHP se nikdy neposílá do prohlížeče.
 */

// Kam poptávky chodí.
define('CONTACT_TO', 'ahoj@janmalek.cz');

// Odesílatel (musí to být adresa té schránky, přes kterou se odesílá).
define('CONTACT_FROM', 'ahoj@janmalek.cz');
define('CONTACT_FROM_NAME', 'Web janmalek.cz');

// SMTP schránky. Nech 'host' prázdný, pokud chceš zkusit jen PHP mail().
//   Seznam Email Profi: smtp.seznam.cz, port 465, secure 'ssl'
//   Savana / Webglobe:  mail.webglobe.cz, port 465, secure 'ssl'
define('CONTACT_SMTP', [
    'host' => 'smtp.seznam.cz',
    'port' => 465,
    'secure' => 'ssl',                 // 'ssl' (465) nebo 'tls' (587)
    'user' => 'ahoj@janmalek.cz',      // celá e-mailová adresa
    'pass' => 'SEM_HESLO_KE_SCHRANCE',
]);

// Volitelný webhook (Make, n8n, Slack…). Prázdné = nepoužívá se.
define('CONTACT_WEBHOOK_URL', '');
