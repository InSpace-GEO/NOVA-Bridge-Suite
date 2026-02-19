<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Route_Discovery
{
    /** @var string */
    private $include_regex;

    /** @var string */
    private $exclude_regex;

    /** @var int */
    private $cache_ttl;

    public function __construct($include_regex, $exclude_regex, $cache_ttl)
    {
        $this->include_regex = (string) $include_regex;
        $this->exclude_regex = (string) $exclude_regex;
        $this->cache_ttl = (int) $cache_ttl;
    }

    public function discover($refresh)
    {
        if (!$refresh) {
            $cached = get_transient(NPR_Plugin::ROUTE_DISCOVERY_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $server = rest_get_server();
        if (!$server) {
            return array();
        }

        $routes = $server->get_routes();
        $templates = array();

        foreach ($routes as $route_regex => $endpoints) {
            if (preg_match($this->exclude_regex, $route_regex)) {
                continue;
            }

            if (preg_match($this->include_regex, $route_regex) !== 1) {
                continue;
            }

            if (!NPR_Route_Template::has_single_id_or_post_id_param($route_regex)) {
                continue;
            }

            if (!NPR_Route_Template::supports_get($endpoints)) {
                continue;
            }

            $templates[] = array(
                'route_regex' => $route_regex,
                'score' => NPR_Route_Template::score($route_regex),
            );
        }

        usort($templates, array($this, 'sort_by_score_desc'));
        set_transient(NPR_Plugin::ROUTE_DISCOVERY_CACHE_KEY, $templates, $this->cache_ttl);

        return $templates;
    }

    public function debug_templates(array $templates)
    {
        return array(
            'count' => count($templates),
            'top' => array_slice($templates, 0, 30),
        );
    }

    public function sort_by_score_desc($a, $b)
    {
        $a_score = isset($a['score']) ? (int) $a['score'] : 0;
        $b_score = isset($b['score']) ? (int) $b['score'] : 0;

        if ($a_score === $b_score) {
            return 0;
        }

        return ($a_score > $b_score) ? -1 : 1;
    }
}
