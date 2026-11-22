<?php
    // Route to the appropriate shell based on the current URL path.
    // Core maps both /admin/ and /account/ to 'settings-shell';
    // Idno 2026 uses dedicated shells for each.
    $currentPath = \Idno\Core\Idno::site()->currentPage()->currentUrl();
    if (strpos($currentPath, '/admin') !== false) {
        echo $this->draw('admin-shell');
    } else {
        echo $this->draw('account-shell');
    }
