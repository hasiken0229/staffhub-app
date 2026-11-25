<?php
// This is only the dedicated PHP development-server router, never a public route.
if (PHP_SAPI !== 'cli-server' || getenv('STAFFHUB_E2E') !== 'true') { http_response_code(503); exit('Verification environment required'); }
// Windows PHP's default variables_order omits E. Import the runner environment before Dotenv.
foreach (getenv() as $key => $value) { $_ENV[$key] = $value; $_SERVER[$key] = $value; }
require dirname(__DIR__, 2).'/public/index.php';
