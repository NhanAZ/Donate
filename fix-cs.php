<?php

/**
 * Simple script to run PHP CS Fixer locally
 */

// Check if php-cs-fixer exists
$vendorPath = __DIR__ . '/vendor/bin/php-cs-fixer';
if (!file_exists($vendorPath)) {
    echo "PHP CS Fixer not found. Make sure you've run: composer install\n";
    exit(1);
}

// Build the command
$command = escapeshellcmd($vendorPath) . ' fix --config=php-cs-fixer.php --allow-risky=yes';

// Add verbose option if requested
if (in_array('--verbose', $argv) || in_array('-v', $argv)) {
    $command .= ' --verbose';
}

// Add dry-run option if requested
if (in_array('--dry-run', $argv) || in_array('-d', $argv)) {
    $command .= ' --dry-run';
}

echo "Running PHP CS Fixer...\n";
echo "$command\n\n";

// Execute the command
passthru($command, $returnCode);
exit($returnCode); 