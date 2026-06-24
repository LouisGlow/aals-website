<?php
/**
 * One-off diagnostic for cPanel/PHP mail() delivery.
 * Visit https://advancedlifesupport.co.za/mail-test.php in a browser and
 * the output here will tell us exactly which combinations actually leave
 * the server.  Delete this file once we've diagnosed.
 */
header('Content-Type: text/plain; charset=utf-8');

$tests = [
  [
    'label'  => 'A. bookings@ -> agrecia@resus.co.za (with -f)',
    'to'     => 'agrecia@resus.co.za',
    'from'   => 'bookings@advancedlifesupport.co.za',
    'extra'  => '-fbookings@advancedlifesupport.co.za',
  ],
  [
    'label'  => 'B. bookings@ -> agrecia@resus.co.za (NO -f flag)',
    'to'     => 'agrecia@resus.co.za',
    'from'   => 'bookings@advancedlifesupport.co.za',
    'extra'  => '',
  ],
  [
    'label'  => 'C. contact@ -> agrecia@resus.co.za (with -f)',
    'to'     => 'agrecia@resus.co.za',
    'from'   => 'contact@advancedlifesupport.co.za',
    'extra'  => '-fcontact@advancedlifesupport.co.za',
  ],
  [
    'label'  => 'D. bookings@ -> bookings@ (loopback, same server)',
    'to'     => 'bookings@advancedlifesupport.co.za',
    'from'   => 'bookings@advancedlifesupport.co.za',
    'extra'  => '-fbookings@advancedlifesupport.co.za',
  ],
  [
    'label'  => 'E. no headers, no -f (PHP defaults)',
    'to'     => 'agrecia@resus.co.za',
    'from'   => null,
    'extra'  => null,
  ],
];

echo "PHP version          : " . PHP_VERSION . "\n";
echo "SAPI                 : " . PHP_SAPI . "\n";
echo "sendmail_path        : " . (ini_get('sendmail_path') ?: '(empty - using default)') . "\n";
echo "Server time          : " . date('Y-m-d H:i:s T') . "\n";
echo str_repeat('-', 60) . "\n\n";

$stamp = date('His');

foreach ($tests as $t) {
  $subject = "AALS mail-test {$t['label'][0]} [{$stamp}]";
  $body    = "If you can read this, test {$t['label']} delivered.\n\n"
           . "Run at: " . date('c') . "\n";
  $headers = $t['from']
           ? "From: {$t['from']}\r\nReply-To: {$t['from']}\r\nContent-Type: text/plain; charset=UTF-8"
           : '';

  $started = microtime(true);
  if ($t['extra'] === null) {
    $ok = @mail($t['to'], $subject, $body, $headers);
  } else {
    $ok = @mail($t['to'], $subject, $body, $headers, $t['extra']);
  }
  $ms = (int)((microtime(true) - $started) * 1000);

  $err = error_get_last();

  echo $t['label'] . "\n";
  echo "  to       : {$t['to']}\n";
  if ($t['from'] !== null)  echo "  from     : {$t['from']}\n";
  if ($t['extra'])          echo "  -f       : {$t['extra']}\n";
  echo "  subject  : $subject\n";
  echo "  mail()   : " . ($ok ? 'TRUE  (accepted into local queue)' : 'FALSE (refused by sendmail/Exim)') . "\n";
  echo "  elapsed  : {$ms} ms\n";
  if ($err && stripos($err['message'], 'mail') !== false) {
    echo "  last err : " . trim($err['message']) . "\n";
  }
  echo "\n";
}

echo str_repeat('-', 60) . "\n";
echo "Now: in cPanel -> Email -> Track Delivery, search for subjects starting\n";
echo "'AALS mail-test' and tick BOTH Show Successes and Show Failures.\n";
echo "Each row will show the actual Exim verdict and 'Result' column.\n";
