<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Plugin
{
    const VERSION = '1.0.0';
    const REST_NAMESPACE = 'nova-post-resolver/v1';
    const ROUTE_DISCOVERY_CACHE_KEY = 'npr_discovered_route_templates_v1';
    const ROUTE_DISCOVERY_CACHE_TTL = 21600; // 6 hours
    const DEFAULT_MAX_PROBES = 80;
    const HARD_MAX_PROBES = 250;

    /** @var NPR_Controller */
    private $controller;

    /** @var NPR_Plugin */
    private static $instance;

    public static function boot()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        self::$instance->register();
    }

    private function __construct()
    {
        $include_regex = (string) apply_filters('npr_include_route_regex', '#^/nova-[^/]+/v\\d+/#i');
        $exclude_regex = (string) apply_filters('npr_exclude_route_regex', '#^/(wp|oembed|wp-site-health|jwt-auth|simple-jwt-login|wc|yoast|rankmath|aioseo|mailpoet|wordfence|wpgdprc)/#i');

        $http = new NPR_Rest_Internal_Http();
        $route_discovery = new NPR_Route_Discovery($include_regex, $exclude_regex, self::ROUTE_DISCOVERY_CACHE_TTL);
        $result_cache = new NPR_Result_Cache();

        $resolver = new NPR_Resolver_Service($http, $route_discovery, $result_cache);
        $rate_limiter = new NPR_Rate_Limiter();

        $this->controller = new NPR_Controller($resolver, $rate_limiter);
    }

    private function register()
    {
        add_action('rest_api_init', array($this->controller, 'register_routes'));
    }
}
