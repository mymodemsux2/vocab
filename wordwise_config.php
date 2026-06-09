<?php
/**
 * WordWise – Configuration
 * Keep this file OUTSIDE your web root, or protect it with .htaccess.
 * Never commit this file to version control.
 */

// -- Anthropic API -------------------------------------------------------------
define('ANTHROPIC_API_KEY', 'MY-KEY'); // ? paste your key here'); // ? paste your key here

// -- Rate limits ---------------------------------------------------------------
define('DAILY_API_PER_STUDENT', 99999);  // max AI question generations per student per day
define('DAILY_API_GLOBAL', 99999);
define('DAILY_ALT_PER_STUDENT', 15); // max 'is another answer OK' checks per student per day // max across all students combined per day