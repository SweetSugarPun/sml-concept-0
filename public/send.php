<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

function clean(string $value): string {
    return trim(strip_tags($value));
}

function maskEmail(string $email): string {
    if ($email === '' || !str_contains($email, '@')) {
        return '[redacted]';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localMasked = mb_strlen($local) <= 2
        ? mb_substr($local, 0, 1) . '*'
        : mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2));

    return $localMasked . '@' . $domain;
}

$configPath = dirname(__DIR__) . '/private/mail-config.php';
error_log('CONFIG PATH: ' . $configPath);
error_log('CONFIG EXISTS: ' . (file_exists($configPath) ? 'yes' : 'no'));
error_log('CONFIG READABLE: ' . (is_readable($configPath) ? 'yes' : 'no'));

$config = require $configPath;

if (!file_exists($configPath) || !is_readable($configPath)) {
    error_log('CONFIG LOAD FAILED');
    http_response_code(500);
    exit('Błąd konfiguracji formularza.');
}

error_log('SEND: config loaded');

if (!is_array($config)) {
    error_log('mail-config.php did not return an array');
    http_response_code(500);
    exit('Błąd konfiguracji.');
}

$name = clean($_POST['name'] ?? '');
$phone = clean($_POST['phone'] ?? '');
$email = clean($_POST['email'] ?? '');
$location = clean($_POST['location'] ?? '');
$details = clean($_POST['details'] ?? '');
$website = clean($_POST['website'] ?? '');

$formStartedAt = $_POST['form_started_at'] ?? '';
$minSubmitTimeMs = 3000;

if ($formStartedAt === '' || !ctype_digit((string) $formStartedAt)) {
    http_response_code(400);
    exit('Nieprawidłowe żądanie.');
}

$elapsedMs = (int) round(microtime(true) * 1000) - (int) $formStartedAt;

if ($elapsedMs < 0) {
    http_response_code(400);
    exit('Nieprawidłowe żądanie.');
}

if ($elapsedMs < $minSubmitTimeMs) {
    http_response_code(200);
    exit('OK');
}

if ($website !== '') {
    http_response_code(200);
    exit('OK');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitDir = __DIR__ . '/private/rate-limit';
$maxSubmissions = 12;
$windowSeconds = 3600;

if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0700, true);
}

$ipKey = preg_replace('/[^a-zA-Z0-9\.\:\-_]/', '_', $ip);
$rateLimitFile = $rateLimitDir . '/' . $ipKey . '.json';

$now = time();
$timestamps = [];

if (is_file($rateLimitFile)) {
    $raw = file_get_contents($rateLimitFile);
    $data = json_decode($raw ?: '[]', true);

    if (is_array($data)) {
        $timestamps = array_values(array_filter(
            $data,
            static fn($ts): bool => is_int($ts) && ($now - $ts) < $windowSeconds
        ));
    }
}

if (count($timestamps) >= $maxSubmissions) {
    http_response_code(429);
    exit('Zbyt wiele prób. Spróbuj ponownie później.');
}

$timestamps[] = $now;
$written = @file_put_contents($rateLimitFile, json_encode($timestamps), LOCK_EX);

if ($written === false) {
    error_log('Rate limit write error for IP: ' . $ip);
}

if ($name === '' || $phone === '' || $location === '' || $details === '') {
    http_response_code(400);
    exit('Proszę uzupełnić wymagane pola.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit('Nieprawidłowy adres e-mail.');
}

$maxLengths = [
    'name' => 80,
    'phone' => 30,
    'email' => 254,
    'location' => 120,
    'details' => 3000,
];

if (mb_strlen($name, 'UTF-8') > $maxLengths['name']) {
    header('Location: /?error=name_too_long#kontakt', true, 302);
    exit;
}

if (mb_strlen($phone, 'UTF-8') > $maxLengths['phone']) {
    header('Location: /?error=phone_too_long#kontakt', true, 302);
    exit;
}

if ($email !== '' && mb_strlen($email, 'UTF-8') > $maxLengths['email']) {
    header('Location: /?error=email_too_long#kontakt', true, 302);
    exit;
}

if (mb_strlen($location, 'UTF-8') > $maxLengths['location']) {
    header('Location: /?error=location_too_long#kontakt', true, 302);
    exit;
}

if (mb_strlen($details, 'UTF-8') > $maxLengths['details']) {
    header('Location: /?error=details_too_long#kontakt', true, 302);
    exit;
}

$mail = new PHPMailer(true);

$debugMode = false;
$mail->SMTPDebug = $debugMode ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
$mail->Debugoutput = static function (string $str, int $level): void {
    error_log('SMTP DEBUG [' . $level . ']: ' . trim($str));
};
$mail->Timeout = 20;

try {
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = (string) $config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $config['smtp_username'];
    $mail->Password = (string) $config['smtp_password'];
    $mail->Port = (int) $config['smtp_port'];
    $mail->SMTPSecure = ((string) $config['smtp_secure'] === 'ssl')
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    $mail->setFrom((string) $config['from_email'], (string) $config['from_name']);
    $mail->addAddress((string) $config['to_email']);

    if ($email !== '') {
        $mail->addReplyTo($email, $name !== '' ? $name : 'Nadawca formularza');
    } else {
        $mail->addReplyTo((string) $config['from_email'], (string) $config['from_name']);
    }

    $subjectName = mb_substr($name, 0, 40, 'UTF-8');
    $subjectLocation = mb_substr($location, 0, 50, 'UTF-8');

    $mail->Subject = 'Skup | ' . $subjectName . ' | ' . $subjectLocation;

    $body = [];
    $body[] = 'Nowe zgłoszenie z formularza kontaktowego';
    $body[] = '';
    $body[] = 'Imię: ' . $name;
    $body[] = 'Telefon: ' . $phone;
    $body[] = 'E-mail: ' . ($email !== '' ? $email : 'nie podano');
    $body[] = 'Lokalizacja: ' . $location;
    $body[] = '';
    $body[] = 'Opis:';
    $body[] = $details;
    $body[] = '';
    $body[] = 'IP: ' . $ip;
    $body[] = 'Data: ' . date('Y-m-d H:i:s');

    $mail->isHTML(true);

    error_log('SEND: before mail->send()');

    $avatarPath = __DIR__ . '/email-avatar.png';
    $avatarCid = 'mail-avatar';

    if (is_file($avatarPath)) {
        $mail->addEmbeddedImage($avatarPath, $avatarCid, 'email-avatar.png');
    }

    $subjectName = htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8');
    $subjectLocation = htmlspecialchars($subjectLocation, ENT_QUOTES, 'UTF-8');

    $nameHtml = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $phoneHtml = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');

    $phoneHref = 'tel:' . preg_replace('/[^0-9+]/', '', $phone);
    $phoneCallLinkHtml = '<a href="' . htmlspecialchars($phoneHref, ENT_QUOTES, 'UTF-8') . '" style="color:#b8891c; text-decoration:none; font-weight:700;">Zadzwoń</a>';

    $emailHtml = htmlspecialchars($email !== '' ? $email : 'nie podano', ENT_QUOTES, 'UTF-8');
    $locationHtml = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
    $detailsHtml = nl2br(htmlspecialchars($details, ENT_QUOTES, 'UTF-8'));
    $ipHtml = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $dateHtml = htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8');

    $mail->isHTML(true);

    $mail->Body = <<<HTML
    <!doctype html>
    <html lang="pl">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Nowe zgłoszenie</title>
    </head>
    <body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f4f6; margin:0; padding:24px 12px;">
        <tr>
          <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px; background-color:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #e5e7eb;">
              <tr>
                <td style="padding:24px 24px 16px; background-color:#0f172a;">
                  <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                      <td style="width:56px; vertical-align:middle;">
                          <img src="cid:mail-avatar" alt="Skup Mieszkań Lublin" title="Skup Mieszkań Lublin" width="56" height="56" style="display:block; width:56px; height:56px; border-radius:999px; object-fit:cover; border:2px solid #d3a842;">
                      </td>
                      <td style="padding-left:16px; vertical-align:middle;">
                        <div style="font-size:12px; line-height:1.4; color:#cbd5e1; text-transform:uppercase; letter-spacing:0.08em;">Nowe zgłoszenie</div>
                        <div style="font-size:22px; line-height:1.3; font-weight:700; color:#ffffff;">Formularz kontaktowy</div>
                        <div style="font-size:13px; line-height:1.5; color:#94a3b8;">Skup Mieszkań Lublin</div>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>

              <tr>
                <td style="padding:24px;">
                  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:20px;">
                    <tr>
                      <td style="padding:16px; background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                        <div style="font-size:14px; font-weight:700; color:#0f172a; margin-bottom:12px;">Dane kontaktowe</div>

                        <div style="font-size:14px; color:#334155; margin-bottom:8px;"><strong>Imię:</strong> {$nameHtml}</div>
                        <div style="font-size:14px; color:#334155; margin-bottom:8px;"><strong>Telefon:</strong> {$phoneHtml}&nbsp;&nbsp;{$phoneCallLinkHtml}</div>
                        <div style="font-size:14px; color:#334155; margin-bottom:8px;"><strong>E-mail:</strong> {$emailHtml}</div>
                        <div style="font-size:14px; color:#334155;"><strong>Lokalizacja:</strong> {$locationHtml}</div>
                      </td>
                    </tr>
                  </table>

                  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:20px;">
                    <tr>
                      <td style="padding:16px; background-color:#fffdf7; border:1px solid #f0e1ae; border-radius:12px;">
                        <div style="font-size:14px; font-weight:700; color:#0f172a; margin-bottom:12px;">Opis zgłoszenia</div>
                        <div style="font-size:14px; line-height:1.7; color:#334155;">{$detailsHtml}</div>
                      </td>
                    </tr>
                  </table>

                  <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                      <td style="padding-top:4px; font-size:12px; line-height:1.7; color:#64748b;">
                        <div><strong>IP:</strong> {$ipHtml}</div>
                        <div><strong>Data:</strong> {$dateHtml}</div>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </body>
    </html>
    HTML;

    $body = [];
    $body[] = 'Nowe zgłoszenie z formularza kontaktowego';
    $body[] = '';
    $body[] = 'Imię: ' . $name;
    $body[] = 'Telefon: ' . $phone;
    $body[] = 'E-mail: ' . ($email !== '' ? $email : 'nie podano');
    $body[] = 'Lokalizacja: ' . $location;
    $body[] = '';
    $body[] = 'Opis:';
    $body[] = $details;
    $body[] = '';
    $body[] = 'IP: ' . $ip;
    $body[] = 'Data: ' . date('Y-m-d H:i:s');

    $mail->AltBody = implode("\r\n", $body);

    $mail->send();
    error_log('SEND: after mail->send()');

    header('Location: /?sent=1#kontakt');
    exit;
} catch (Exception $e) {
    error_log(
        'Form mail error | host=' . ($config['smtp_host'] ?? '[unknown]') .
        ' | port=' . ($config['smtp_port'] ?? '[unknown]') .
        ' | secure=' . ($config['smtp_secure'] ?? '[unknown]') .
        ' | user=' . maskEmail((string) ($config['smtp_username'] ?? '')) .
        ' | to=' . maskEmail((string) ($config['to_email'] ?? '')) .
        ' | error=' . $mail->ErrorInfo
    );

    header('Location: /?error=1#kontakt');
    exit;
}