<?php
/**
 * AALS course registration handler.  PHP 7.0+ compatible.
 *
 *   Receives the POST from register.html, validates + sanitises every field,
 *   and sends two emails via the Resend HTTP API:
 *
 *     1. Registration notification -> TO_EMAIL (Agrecia in production, or
 *        Louis's gmail when TO_EMAIL is set to a test address). Includes
 *        any uploaded BLS prerequisite cert as an attachment.
 *     2. Auto-confirmation -> the student's email, with Reply-To pointing
 *        at Agrecia so any reply lands in her inbox.
 *
 *   Returns JSON: { ok: bool, error?: string }
 *
 *   Why Resend instead of PHP mail():
 *     PHP mail() routes through Exim on this cPanel server. Even with SPF
 *     and DKIM in DNS, Gmail was silently dropping messages from this
 *     fresh-reputation domain. Resend handles transactional mail from
 *     warmed-up dedicated IPs with proper DKIM/DMARC signing — Gmail
 *     trusts it out of the gate.
 *
 *   The Resend API key lives in secrets.php (not in git). If you ever
 *   need to rotate it, generate a new key at resend.com → API Keys and
 *   update secrets.php on the server.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
  error_log("AALS register.php [$severity] $message in $file:$line");
  return false;
});

set_exception_handler(function ($e) {
  error_log("AALS register.php exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json');
  }
  echo json_encode(['ok' => false, 'error' => 'Server error. Please email agrecia@resus.co.za directly.']);
  exit;
});

// -----------------------------------------------------------------------------
// SECRETS (Resend API key from non-git secrets.php)
// -----------------------------------------------------------------------------
$secretsFile = __DIR__ . '/secrets.php';
if (!file_exists($secretsFile)) {
  error_log("AALS register.php: secrets.php missing — registration emails will fail.");
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Server misconfigured. Please email agrecia@resus.co.za directly.']);
  exit;
}
require_once $secretsFile;
if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
  error_log("AALS register.php: RESEND_API_KEY not defined in secrets.php");
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'Server misconfigured. Please email agrecia@resus.co.za directly.']);
  exit;
}

// -----------------------------------------------------------------------------
// CONFIG  (change values here if email addresses ever move)
// -----------------------------------------------------------------------------
const TO_EMAIL    = 'agrecia@resus.co.za';
const FROM_EMAIL  = 'bookings@advancedlifesupport.co.za';
const FROM_NAME   = 'AALS Registration';
const REPLY_HINT  = 'agrecia@resus.co.za';
const SITE_NAME   = 'Academy of Advanced Life Support';

const UPLOAD_MAX  = 10 * 1024 * 1024;                     // 10 MB
const UPLOAD_EXT  = ['pdf', 'png', 'jpg', 'jpeg'];

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

const COURSE_LABELS = [
  'bls'            => 'BLS Provider Course',
  'acls'           => 'Advanced Cardiovascular Life Support (ACLS)',
  'acls-ep'        => 'ACLS for Experienced Providers (ACLS-EP)',
  'acls-refresher' => 'ACLS Refresher Course',
  'pals'           => 'Paediatric Advanced Life Support (PALS)',
  'pals-refresher' => 'PALS Refresher Course',
  'amls'           => 'Advanced Medical Life Support (AMLS)',
  'ecg'            => 'ECG Recognition and Interpretation',
  'nrp'            => 'Advanced Neonatal Life Support (NRP)',
  'itls'           => 'International Trauma Life Support (ITLS)',
];

/**
 * POST one transactional email through Resend.
 * Returns true on 2xx, false otherwise. Failures are logged with the
 * HTTP status, response body, and curl error for cPanel "Errors" view.
 */
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
  $resp  = curl_exec($ch);
  $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr  = curl_error($ch);
  curl_close($ch);

  if ($http < 200 || $http >= 300) {
    error_log("AALS Resend send failed: HTTP $http body=" . substr((string)$resp, 0, 500) . " curl_err=$cerr");
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
$course_slug     = field('course', true);
$course_date     = field('course_date', true);
$first_name      = field('first_name', true);
$last_name       = field('last_name', true);
$id_number       = field('id_number', true);
$profession      = field('profession', true);
$hpcsa           = field('hpcsa_number');
$employer        = field('employer');
$email           = field('email', true);
$phone           = field('phone', true);
$address         = field('address');
$prereq_cert_num = field('prereq_cert_number');
$prereq_cert_exp = field('prereq_cert_expiry');
$dietary         = field('dietary');
$referral        = field('referral');
$notes           = field('notes');
$consent         = field('consent');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  bail(400, 'Please provide a valid email address.');
}
if ($consent === '') {
  bail(400, 'You must accept the terms to register.');
}

$course_label = COURSE_LABELS[$course_slug] ?? $course_slug;
$full_name    = trim("$first_name $last_name");

// -----------------------------------------------------------------------------
// HANDLE OPTIONAL FILE UPLOAD (BLS prerequisite certificate)
//   Read into memory so we can attach to the outgoing email — no server-side
//   storage required (Agrecia has no cPanel access).
// -----------------------------------------------------------------------------
$attach_bytes    = null;
$attach_filename = '';

if (isset($_FILES['prereq_cert_upload']) && $_FILES['prereq_cert_upload']['error'] === UPLOAD_ERR_OK) {
  $f = $_FILES['prereq_cert_upload'];

  if ($f['size'] > UPLOAD_MAX) {
    bail(413, 'Uploaded file is too large. Max 10 MB.');
  }
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, UPLOAD_EXT, true)) {
    bail(415, 'File type not allowed. PDF, JPG or PNG only.');
  }

  $attach_bytes = file_get_contents($f['tmp_name']);
  if ($attach_bytes === false) {
    bail(500, 'Could not read the uploaded file. Please try again.');
  }

  // Give the attachment a clean, predictable filename for Agrecia's inbox.
  $safe_last = preg_replace('/[^a-zA-Z0-9-]/', '', $last_name) ?: 'student';
  $attach_filename = 'BLScert-' . $safe_last . '-' . date('Ymd') . '.' . $ext;
} elseif (isset($_FILES['prereq_cert_upload']) && $_FILES['prereq_cert_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
  bail(400, 'There was a problem with the uploaded file. Please try again or email it directly.');
}

// -----------------------------------------------------------------------------
// COMPOSE EMAIL BODY (text)
// -----------------------------------------------------------------------------
$now_sa = (new DateTime('now', new DateTimeZone('Africa/Johannesburg')))->format('Y-m-d H:i T');

$body  = "New course registration received via " . SITE_NAME . "\n";
$body .= "Submitted: $now_sa\n";
$body .= str_repeat('-', 60) . "\n";
$body .= "\nCOURSE SELECTION\n";
$body .= "Course      : $course_label  ($course_slug)\n";
$body .= "Date        : $course_date\n";
$body .= "\nSTUDENT DETAILS\n";
$body .= "Name        : $full_name\n";
$body .= "ID/Passport : $id_number\n";
$body .= "Profession  : $profession\n";
if ($hpcsa)    $body .= "HPCSA       : $hpcsa\n";
if ($employer) $body .= "Employer    : $employer\n";
$body .= "\nCONTACT\n";
$body .= "Email       : $email\n";
$body .= "Phone       : $phone\n";
if ($address)  $body .= "Address     :\n  " . str_replace("\n", "\n  ", $address) . "\n";

if ($prereq_cert_num || $prereq_cert_exp || $attach_bytes !== null) {
  $body .= "\nPREREQUISITE CERTIFICATE\n";
  if ($prereq_cert_num) $body .= "Cert number : $prereq_cert_num\n";
  if ($prereq_cert_exp) $body .= "Cert expiry : $prereq_cert_exp\n";
  if ($attach_bytes !== null) {
    $body .= "Cert upload : ATTACHED to this email ($attach_filename)\n";
  }
}

if ($dietary || $referral || $notes) {
  $body .= "\nADDITIONAL\n";
  if ($dietary)  $body .= "Dietary     : $dietary\n";
  if ($referral) $body .= "Heard via   : $referral\n";
  if ($notes)    $body .= "Notes       :\n  " . str_replace("\n", "\n  ", $notes) . "\n";
}

$body .= "\n" . str_repeat('-', 60) . "\n";
$body .= "Reply directly to this email — Reply-To is set to the student ($email).\n";

$subject = "AALS Registration — $course_label — $full_name";

// -----------------------------------------------------------------------------
// SEND REGISTRATION EMAIL VIA RESEND
// -----------------------------------------------------------------------------
$payload = [
  'from'      => FROM_NAME . ' <' . FROM_EMAIL . '>',
  'to'        => [TO_EMAIL],
  'reply_to'  => $email,
  'subject'   => $subject,
  'text'      => $body,
];

if ($attach_bytes !== null) {
  $payload['attachments'] = [[
    'filename' => $attach_filename,
    'content'  => base64_encode($attach_bytes),
  ]];
}

$sent_to_office = sendViaResend($payload);

// -----------------------------------------------------------------------------
// COMPOSE + SEND CONFIRMATION EMAIL TO STUDENT
// -----------------------------------------------------------------------------
$conf_body  = "Hi $first_name,\n\n";
$conf_body .= "Thank you for your registration with the Academy of Advanced Life Support.\n\n";
$conf_body .= "We've received your details for:\n";
$conf_body .= "  Course : $course_label\n";
$conf_body .= "  Date   : $course_date\n\n";
$conf_body .= "Agrecia will be in touch by email within one working day to confirm date availability and send payment instructions. Your booking is only confirmed once payment is received. As soon as we receive payment, your pre-course manual will be sent for pre-reading.\n\n";
$conf_body .= "If you don't hear back within 24 hours, please reply to this email or contact us directly:\n";
$conf_body .= "  Email : agrecia@resus.co.za\n";
$conf_body .= "  Phone : +27 (0)11 478 1874\n\n";
$conf_body .= "Kind regards,\n";
$conf_body .= "Academy of Advanced Life Support\n";

$conf_payload = [
  'from'      => FROM_NAME . ' <' . FROM_EMAIL . '>',
  'to'        => [$email],
  'reply_to'  => REPLY_HINT,
  'subject'   => "We've received your AALS registration — $course_label",
  'text'      => $conf_body,
];

sendViaResend($conf_payload);

// -----------------------------------------------------------------------------
// RESPONSE
// -----------------------------------------------------------------------------
header('Content-Type: application/json');
echo json_encode([
  'ok'    => $sent_to_office,
  'error' => $sent_to_office
             ? null
             : 'Could not send notification email. Please email agrecia@resus.co.za directly.',
]);
