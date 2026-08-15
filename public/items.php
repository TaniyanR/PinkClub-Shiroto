<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// The lightweight top page already contains the complete paginated catalogue.
// Keep the former URL as a permanent redirect for bookmarks and search engines.
header('Location: ' . public_url(''), true, 301);
exit;
