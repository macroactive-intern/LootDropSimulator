<?php

// Provide env vars required for static analysis.
// PHPStan/larastan bootstraps the app without loading .env,
// so any required vars must be seeded here.
$_ENV['APP_KEY'] = 'base64:'.base64_encode(str_repeat('a', 32));
putenv('APP_KEY='.$_ENV['APP_KEY']);
