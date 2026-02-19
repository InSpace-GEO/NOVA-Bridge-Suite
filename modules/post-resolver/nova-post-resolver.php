<?php
/**
 * Plugin Name: Nova Post Resolver
 * Description: Resolve a WordPress post ID to its REST endpoint with secure probing and caching.
 * Version: 1.0.0
 * Author: Nova
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-controller.php';
require_once __DIR__ . '/includes/class-resolver-service.php';
require_once __DIR__ . '/includes/class-route-discovery.php';
require_once __DIR__ . '/includes/class-route-template.php';
require_once __DIR__ . '/includes/class-rest-internal-http.php';
require_once __DIR__ . '/includes/class-probe-trace.php';
require_once __DIR__ . '/includes/class-rate-limiter.php';
require_once __DIR__ . '/includes/class-result-cache.php';

NPR_Plugin::boot();
