<?php

// Test-environment only (with IDNO_TEST_DRAW_HEADERS=1): a third-party `json` shell that sends a Content-Type of its own, which wins
// over the one the type declares, as it did when the shells sent their own headers.
header('Content-Type: application/x-fixture+json');
echo json_encode(['fixture' => 'shell']);
