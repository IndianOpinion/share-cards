<?php

/**
 * Removes the settings, transients, cron event and the plugin's own files on
 * every site. Files the plugin did not name are left in place.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
        switch_to_blog((int) $siteId);
        (new \IndianOpinion\ShareCards\Plugin())->uninstallSite();
        restore_current_blog();
    }
} else {
    (new \IndianOpinion\ShareCards\Plugin())->uninstallSite();
}
