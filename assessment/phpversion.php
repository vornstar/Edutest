<?php
// Temporary diagnostic file - delete after checking. Reports the PHP
// version actually running this site, to rule in/out a version mismatch
// as the cause of the assessment platform's 500 errors (it requires PHP 8.0+).
header('Content-Type: text/plain');
echo "PHP version: " . PHP_VERSION . "\n";
echo "str_starts_with exists: " . (function_exists('str_starts_with') ? 'yes' : 'NO - this is the problem') . "\n";
