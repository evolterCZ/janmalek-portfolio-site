<?php
/**
 * Kontaktní endpoint pro statický export na klasickém PHP hostingu (FTP).
 *
 * Zrcadlí app/api/contact/route.ts (Node varianta pro Vercel): stejná
 * validace jako lib/contact.ts, stejné návratové kódy, honeypot.
 * Frontend (ContactForm) sem POSTuje JSON, pokud běží ve statickém režimu
 * (NEXT_PUBLIC_STATIC=1, viz scripts/build-ftp.mjs).
 *
 * Odpovědi: 200 {ok:true} · 400 VALIDATION {errors} · 405 · 502 SEND_FAILED
 * Při 502 nebo 404 (PHP nedostupné) frontend nabídne poctivý mailto fallback.
 */

// ===== Nastavení =====
// Přihlašovací údaje NEPATŘÍ do tohoto souboru — ten se při každém buildu
// přepisuje. Vytvoř vedle něj na serveru „contact-local.php“ (vzor:
// contact-local.example.php); pokud existuje, jeho hodnoty mají přednost
// a build ho nikdy nepřepíše.
@include __DIR__ . '/contact-local.php';

// Kam poptávky chodí.
defined('CONTACT_TO') || define('CONTACT_TO', 'ahoj@janmalek.cz');
// Odesílatel. POZOR (Webglobe/Savana): mail() odešle jen z adresy, která na
// hostingu EXISTUJE jako schránka, alias nebo aktivní doménový koš — jinak
// vrátí false. Reply-To je vždy adresa zákazníka.
defined('CONTACT_FROM') || define('CONTACT_FROM', 'ahoj@janmalek.cz');
defined('CONTACT_FROM_NAME') || define('CONTACT_FROM_NAME', 'Web janmalek.cz');
// Volitelné SMTP (spolehlivější než mail()): vyplň v contact-local.php.
//   Seznam Email Profi: smtp.seznam.cz, 465 ssl
//   Webglobe/Savana:    mail.webglobe.cz, 465 ssl (nebo 587 tls)
defined('CONTACT_SMTP') || define('CONTACT_SMTP', [
    'host' => '',            // prázdné = SMTP vypnuté, použije se mail()
    'port' => 465,
    'secure' => 'ssl',       // 'ssl' (465) nebo 'tls' (587 STARTTLS)
    'user' => 'ahoj@janmalek.cz',
    'pass' => '',
]);
// Volitelný webhook (Make, n8n, Slack…) — když je vyplněný, pošle se JSON
// i sem. Selhání webhooku e-mail nezablokuje.
defined('CONTACT_WEBHOOK_URL') || define('CONTACT_WEBHOOK_URL', '');
// =====================

ini_set('html_errors', '0');
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'code' => 'METHOD_NOT_ALLOWED']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    respond(400, ['ok' => false, 'code' => 'BAD_REQUEST']);
}

$field = static function (string $key) use ($data): string {
    $value = $data[$key] ?? '';
    return is_string($value) ? $value : '';
};

// Honeypot — robotům odpovíme úspěchem, ale nic neodešleme.
if (trim($field('website')) !== '') {
    respond(200, ['ok' => true]);
}

// ===== Validace (1:1 s lib/contact.ts) =====
$errors = [];
$name = trim($field('name'));
$email = trim($field('email'));
$phone = trim($field('phone'));
$idea = trim($field('idea'));
$message = $field('message');

if ($name === '') {
    $errors['name'] = 'Vyplňte prosím jméno.';
} elseif (mb_strlen($name, 'UTF-8') > 200) {
    $errors['name'] = 'Jméno je příliš dlouhé.';
}

if ($email === '') {
    $errors['email'] = 'Vyplňte prosím e-mail.';
} elseif (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $email)) {
    $errors['email'] = 'E-mail nevypadá platně.';
}

if ($phone === '') {
    $errors['phone'] = 'Nechte mi prosím telefon — ozvu se vám.';
} elseif (!preg_match('/^\+?[\d\s()\/.-]{9,20}$/u', $phone)) {
    $errors['phone'] = 'Telefon nevypadá platně.';
}

if ($idea === '') {
    $errors['idea'] = 'Napište prosím alespoň pár vět o vašem nápadu.';
} elseif (mb_strlen($idea, 'UTF-8') < 10) {
    $errors['idea'] = 'Popište nápad prosím trochu podrobněji.';
} elseif (mb_strlen($idea, 'UTF-8') > 5000) {
    $errors['idea'] = 'Popis je příliš dlouhý (max 5 000 znaků).';
}

if ($message !== '' && mb_strlen($message, 'UTF-8') > 5000) {
    $errors['message'] = 'Zpráva je příliš dlouhá (max 5 000 znaků).';
}

if ($errors) {
    respond(400, ['ok' => false, 'code' => 'VALIDATION', 'errors' => $errors]);
}

// ===== Textové shrnutí (1:1 s contactToText) =====
$labels = [
    'name' => 'Jméno',
    'email' => 'E-mail',
    'phone' => 'Telefon',
    'company' => 'Firma / projekt',
    'idea' => 'Popis nápadu',
    'audience' => 'Komu má produkt sloužit',
    'phase' => 'Současná fáze',
    'collaboration' => 'Typ spolupráce',
    'budget' => 'Orientační rozpočet',
    'timeline' => 'Očekávaný termín',
    'message' => 'Zpráva',
];

$lines = [];
foreach ($labels as $key => $label) {
    $value = trim($field($key));
    if ($value !== '') {
        // Ochrana proti injekci hlaviček / rozbití těla: normalizace konců řádků.
        $lines[] = $label . ': ' . str_replace(["\r\n", "\r"], "\n", $value);
    }
}
$text = implode("\n\n", $lines);
$text .= "\n\n—\nOdesláno z formuláře na " . ($_SERVER['HTTP_HOST'] ?? 'janmalek.cz')
    . ' · ' . date('j. n. Y H:i')
    . ' · IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?');

$subject = 'Poptávka z webu — ' . $name;

// ===== Odeslání e-mailem =====
$encodeHeader = static function (string $value): string {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
};
// Jméno zákazníka do hlavičky jen bez řídicích znaků (anti header-injection).
$safeName = preg_replace('/[\r\n\t"<>]+/', ' ', $name);

$headers = [
    'From: ' . $encodeHeader(CONTACT_FROM_NAME) . ' <' . CONTACT_FROM . '>',
    'Reply-To: ' . $encodeHeader($safeName) . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: janmalek.cz contact form',
];

$body = wordwrap($text, 990, "\n", true);
$encodedSubject = $encodeHeader($subject);
$attempts = []; // diagnostika: co se zkusilo a proč to selhalo

/**
 * Minimální SMTP klient (AUTH LOGIN, SSL nebo STARTTLS) — bez závislostí.
 * Vrací true, nebo string s důvodem selhání.
 */
$smtpSend = static function (array $cfg, string $subjectHeader, string $body, array $headers) {
    $secure = $cfg['secure'] ?? 'ssl';
    $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int) $cfg['port'];
    $sock = @stream_socket_client($target, $errno, $errstr, 12);
    if (!$sock) {
        return "connect: $errstr ($errno)";
    }
    stream_set_timeout($sock, 12);
    $read = static function () use ($sock): array {
        $code = 0;
        $lines = [];
        while (($line = fgets($sock, 1024)) !== false) {
            $lines[] = rtrim($line);
            $code = (int) substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [$code, implode(' | ', $lines)];
    };
    $cmd = static function (string $line, array $okCodes) use ($sock, $read) {
        fwrite($sock, $line . "\r\n");
        [$code, $msg] = $read();
        return in_array($code, $okCodes, true) ? true : "$line → $msg";
    };
    [$code, $msg] = $read();
    if ($code !== 220) {
        return "greeting: $msg";
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'janmalek.cz';
    if (($r = $cmd("EHLO $host", [250])) !== true) {
        return $r;
    }
    if ($secure === 'tls') {
        if (($r = $cmd('STARTTLS', [220])) !== true) {
            return $r;
        }
        if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return 'STARTTLS handshake failed';
        }
        if (($r = $cmd("EHLO $host", [250])) !== true) {
            return $r;
        }
    }
    if (($r = $cmd('AUTH LOGIN', [334])) !== true) {
        return $r;
    }
    if (($r = $cmd(base64_encode($cfg['user']), [334])) !== true) {
        return 'AUTH user → ' . substr((string) $r, strpos((string) $r, '→') + 2);
    }
    if (($r = $cmd(base64_encode($cfg['pass']), [235])) !== true) {
        return 'AUTH pass → ' . substr((string) $r, strpos((string) $r, '→') + 2);
    }
    if (($r = $cmd('MAIL FROM:<' . CONTACT_FROM . '>', [250])) !== true) {
        return $r;
    }
    if (($r = $cmd('RCPT TO:<' . CONTACT_TO . '>', [250, 251])) !== true) {
        return $r;
    }
    if (($r = $cmd('DATA', [354])) !== true) {
        return $r;
    }
    $data = 'Date: ' . date('r') . "\r\n"
        . 'To: <' . CONTACT_TO . ">\r\n"
        . 'Subject: ' . $subjectHeader . "\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n"
        . preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body));
    if (($r = $cmd($data . "\r\n.", [250])) !== true) {
        return 'DATA → ' . substr((string) $r, -200);
    }
    fwrite($sock, "QUIT\r\n");
    fclose($sock);
    return true;
};

$sent = false;

// 1) SMTP, pokud je nakonfigurované (nejspolehlivější).
if (CONTACT_SMTP['host'] !== '' && CONTACT_SMTP['pass'] !== '') {
    $r = $smtpSend(CONTACT_SMTP, $encodedSubject, $body, $headers);
    if ($r === true) {
        $sent = true;
    } else {
        $attempts[] = 'smtp: ' . $r;
    }
}

// 2) mail() s envelope sender (-f), 3) mail() bez něj — některé hostingy
//    additional_parameters odmítají.
if (!$sent) {
    foreach (['-f' . CONTACT_FROM, null] as $extra) {
        error_clear_last();
        $ok = $extra === null
            ? @mail(CONTACT_TO, $encodedSubject, $body, implode("\r\n", $headers))
            : @mail(CONTACT_TO, $encodedSubject, $body, implode("\r\n", $headers), $extra);
        if ($ok) {
            $sent = true;
            break;
        }
        $err = error_get_last();
        $attempts[] = 'mail(' . ($extra === null ? 'bez -f' : 's -f') . '): '
            . ($err['message'] ?? 'vrátilo false bez chybové hlášky');
    }
}

// ===== Volitelný webhook (nezávisle na e-mailu) =====
if (CONTACT_WEBHOOK_URL !== '' && function_exists('curl_init')) {
    $ch = curl_init(CONTACT_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(
            ['subject' => $subject, 'text' => $text, 'data' => $data],
            JSON_UNESCAPED_UNICODE
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $webhookOk = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) < 400;
    curl_close($ch);
    if (!$sent && $webhookOk) {
        $sent = true; // webhook jako záložní kanál, když mail() selže
    }
}

if (!$sent) {
    error_log('janmalek.cz contact: odeslání selhalo pro ' . $email . ' — ' . implode(' ; ', $attempts));
    // `detail` = důvod selhání pro ladění (bez citlivých údajů; heslo se nikdy neloguje).
    respond(502, ['ok' => false, 'code' => 'SEND_FAILED', 'detail' => $attempts]);
}

respond(200, ['ok' => true]);
