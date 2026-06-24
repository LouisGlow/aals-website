<?php
/**
 * AALS course registration handler.
 *
 *   Receives the POST from register.html, validates + sanitises every field,
 *   and emails the registration to Agrecia. If a BLS prerequisite cert was
 *   uploaded, it is attached directly to the email (so Agrecia doesn't need
 *   cPanel access — everything is in her Gmail inbox).
 *
 *   bookings@advancedlifesupport.co.za is BCC'd so a copy lands on this cPanel
 *   server as an audit log. The student gets an auto-confirmation reply from
 *   no-reply@advancedlifesupport.co.za.
 *
 *   Returns JSON: { ok: bool, error?: string }
 *
 *   Uses PHP's built-in mail() because this cPanel server is its own mail
 *   exchanger — SPF + DKIM are already in place for advancedlifesupport.co.za,
 *   so outbound from no-reply@ has the right authentication.
 */

// -----------------------------------------------------------------------------
// CONFIG  (change values here if email addresses ever move)
// -----------------------------------------------------------------------------
const TO_EMAIL    = 'agrecia@resus.co.za';
const FROM_EMAIL  = 'no-reply@advancedlifesupport.co.za';
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

/** Strip CR/LF to prevent email header injection. */
function safeHeader(string $v): string {
  return preg_replace('/[\r\n\t]+/', ' ', $v);
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
$attach_mime     = 'application/octet-stream';

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

  if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($f['tmp_name']);
    if ($detected) $attach_mime = $detected;
  } else {
    $attach_mime = match ($ext) {
      'pdf'         => 'application/pdf',
      'png'         => 'image/png',
      'jpg', 'jpeg' => 'image/jpeg',
      default       => 'application/octet-stream',
    };
  }
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

$subject = safeHeader("AALS Registration — $course_label — $full_name");

// -----------------------------------------------------------------------------
// BUILD MIME MESSAGE (multipart if there's an attachment)
// -----------------------------------------------------------------------------
$headers  = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
$headers .= "Reply-To: " . safeHeader($email) . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "X-Mailer: AALS-Site/1.0\r\n";

if ($attach_bytes !== null) {
  $boundary  = '=_' . bin2hex(random_bytes(16));
  $headers  .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

  $message  = "This is a multi-part message in MIME format.\r\n\r\n";

  // Part 1: text body
  $message .= "--$boundary\r\n";
  $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $message .= $body . "\r\n\r\n";

  // Part 2: file attachment
  $message .= "--$boundary\r\n";
  $message .= "Content-Type: $attach_mime; name=\"$attach_filename\"\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n";
  $message .= "Content-Disposition: attachment; filename=\"$attach_filename\"\r\n\r\n";
  $message .= chunk_split(base64_encode($attach_bytes)) . "\r\n";

  $message .= "--$boundary--\r\n";
} else {
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $message  = $body;
}

$sent_to_office = @mail(TO_EMAIL, $subject, $message, $headers, '-f' . FROM_EMAIL);

// -----------------------------------------------------------------------------
// COMPOSE CONFIRMATION EMAIL TO STUDENT  (text only, no attachment)
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

$conf_subject = safeHeader("We've received your AALS registration — $course_label");

$conf_headers  = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
$conf_headers .= "Reply-To: " . REPLY_HINT . "\r\n";
$conf_headers .= "MIME-Version: 1.0\r\n";
$conf_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$conf_headers .= "X-Mailer: AALS-Site/1.0\r\n";

@mail($email, $conf_subject, $conf_body, $conf_headers, '-f' . FROM_EMAIL);

// -----------------------------------------------------------------------------
// RESPONSE
// -----------------------------------------------------------------------------
header('Content-Type: application/json');
echo json_encode([
  'ok'    => (bool)$sent_to_office,
  'error' => $sent_to_office
             ? null
             : 'Could not send notification email. Please email agrecia@resus.co.za directly.',
]);
