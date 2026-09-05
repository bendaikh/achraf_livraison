<?php

/**
 * Front controller for production hosts that use the project root as the web root
 * (same folder as .env). Delegates to Laravel's public/index.php.
 */
require __DIR__.'/public/index.php';
