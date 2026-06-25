<?php
/**
 * AALS contact form handler.  PHP 7.0+ compatible.
 *
 *   Receives the POST from contact.html, validates + sanitises every field,
 *   and sends two emails via the Resend HTTP API (same path as register.php):
 *
 *     1. Contact enquiry  -> agrecia@resus.co.za. Reply-To set to the
 *        visitor's email so a Reply lands with the sender.
 *     2. Auto-acknowledgement -> the visitor, so they know the message
 *        was received and roughly when to expect a reply.
 *
 *   Returns JSON: { ok: bool, error?: string }
 *
 *   Shares secrets.php with register.php for the Resend API key.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
  error_log("AALS contact.php [$severity] $message in $file:$line");
  return false;
});

set_exception_handler(function ($e) {
  error_log("AALS contact.php exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json');
  }
  echo json_encode(['ok' => false, 'error' => 'Server error. Please email agrecia@resus.co.za directly.']);
  exit;
});

// -----------------------------------------------------------------------------
// SECRETS (shared with register.php)
// -----------------------------------------------------------------------------
$secretsFile = __DIR__ . '/secrets.php';
if (!file_exists($secretsFile)) {
  error_log("AALS contact.php: secrets.php missing — contact emails will fail.");
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Server misconfigured. Please email agrecia@resus.co.za directly.']);
  exit;
}
require_once $secretsFile;
if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
  error_log("AALS contact.php: RESEND_API_KEY not defined in secrets.php");
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Server misconfigured. Please email agrecia@resus.co.za directly.']);
  exit;
}

// -----------------------------------------------------------------------------
// CONFIG
// -----------------------------------------------------------------------------
const TO_EMAIL    = 'agrecia@resus.co.za';
const FROM_EMAIL  = 'bookings@advancedlifesupport.co.za';
const FROM_NAME   = 'AALS Contact Form';
const REPLY_HINT  = 'agrecia@resus.co.za';
const SITE_NAME   = 'Academy of Advanced Life Support';

// -----------------------------------------------------------------------------
// HELPERS
// -----------------------------------------------------------------------------
function bail(int $status, string $message): void {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => $message]);
  exit;
}

function clean($v) {
  if (is_array($v)) return array_map('clean', $v);
  return trim((string)$v);
}

function field(string $name, bool $required = false): string {
  $v = isset($_POST[$name]) ? clean($_POST[$name]) : '';
  if ($required && $v === '') bail(400, "Missing required field: {$name}");
  return $v;
}

function sendViaResend(array $payload): bool {
  $ch = curl_init('https://api.resend.com/emails');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer ' . RESEND_API_KEY,
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);
  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);

  if ($http < 200 || $http >= 300) {
    error_log("AALS Resend (contact) send failed: HTTP $http body=" . substr((string)$resp, 0, 500) . " curl_err=$cerr");
    return false;
  }
  return true;
}

// -----------------------------------------------------------------------------
// METHOD CHECK
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  bail(405, 'Method not allowed.');
}

// -----------------------------------------------------------------------------
// COLLECT + VALIDATE FIELDS
// -----------------------------------------------------------------------------
$first_name = field('first_name', true);
$last_name  = field('last_name');
$email      = field('email', true);
$phone      = field('phone');
$subject_t  = field('subject');
$message    = field('message', true);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  bail(400, 'Please provide a valid email address.');
}

$full_name = trim("$first_name $last_name") ?: $first_name;
$topic     = $subject_t !== '' ? $subject_t : 'General enquiry';

// -----------------------------------------------------------------------------
// COMPOSE EMAIL BODY (text)
// -----------------------------------------------------------------------------
$now_sa = (new DateTime('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d H:i T');

$body  = "New contact form message received via " . SITE_NAME . "\n";
$body .= "Submitted: $now_sa\n";
$body .= str_repeat('-', 60) . "\n";
$body .= "\nFROM\n";
$body .= "Name        : $full_name\n";
$body .= "Email       : $email\n";
if ($phone) $body .= "Phone       : $phone\n";
$body .= "\nTOPIC\n";
$body .= "Subject     : $topic\n";
$body .= "\nMESSAGE\n";
$body .= str_replace("\n", "\n  ", "  " . $message) . "\n";
$body .= "\n" . str_repeat('-', 60) . "\n";
$body .= "Reply directly to this email — Reply-To is set to the sender ($email).\n";

$subject = "AALS Contact — $topic — $full_name";

// -----------------------------------------------------------------------------
// SEND ENQUIRY TO AGRECIA
// -----------------------------------------------------------------------------
$payload = [
  'from'      => FROM_NAME . ' <' . FROM_EMAIL . '>',
  'to'        => [TO_EMAIL],
  'reply_to'  => $email,
  'subject'   => $subject,
  'text'      => $body,
];

$sent_to_office = sendViaResend($payload);

// -----------------------------------------------------------------------------
// AUTO-ACKNOWLEDGEMENT TO VISITOR
// -----------------------------------------------------------------------------
$ack_body  = "Hi $first_name,\n\n";
$ack_body .= "Thank you for contacting the Academy of Advanced Life Support.\n\n";
$ack_body .= "We've received your message and will reply within one working day.\n\n";
$ack_body .= "Your enquiry:\n";
$ack_body .= "  Topic : $topic\n\n";
$ack_body .= "If you need to reach us in the meantime:\n";
$ack_body .= "  Email : agrecia@resus.co.za\n";
$ack_body .= "  Phone : +27 (0)11 478 1874\n\n";
$ack_body .= "Kind regards,\n";
$ack_body .= "Academy of Advanced Life Support\n";

$ack_payload = [
  'from'      => FROM_NAME . ' <' . FROM_EMAIL . '>',
  'to'        => [$email],
  'reply_to'  => REPLY_HINT,
  'subject'   => "We've received your message — Academy of Advanced Life Support",
  'text'      => $ack_body,
];

sendViaResend($ack_payload);

// -----------------------------------------------------------------------------
// RESPONSE
// -----------------------------------------------------------------------------
header('Content-Type: application/json');
echo json_encode([
  'ok'    => $sent_to_office,
  'error' => $sent_to_office
             ? null
             : 'Could not send message. Please email agrecia@resus.co.za directly.',
]);
