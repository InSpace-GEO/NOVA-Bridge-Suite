<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Route_Template
{
    public static function has_single_id_or_post_id_param($route_regex)
    {
        if (!preg_match_all('#\(\?P<([^>]+)>#', $route_regex, $matches)) {
            return false;
        }

        $params = array_values(array_unique($matches[1]));
        if (count($params) !== 1) {
            return false;
        }

        return ('id' === $params[0] || 'post_id' === $params[0]);
    }

    public static function supports_get($endpoints)
    {
        foreach ((array) $endpoints as $endpoint) {
            $methods = isset($endpoint['methods']) ? $endpoint['methods'] : null;
            if (!$methods) {
                continue;
            }

            if (is_string($methods) && false !== stripos($methods, 'GET')) {
                return true;
            }

            if (is_array($methods)) {
                foreach ($methods as $method) {
                    if (is_string($method) && false !== stripos($method, 'GET')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function instantiate($route_regex, $id)
    {
        $route = preg_replace('#\(\?P<(id|post_id)>[^)]+\)#', (string) (int) $id, $route_regex);
        return is_string($route) ? $route : (string) $route_regex;
    }

    public static function score($route_regex)
    {
        $score = 0;

        if (false !== stripos($route_regex, '/nova-')) {
            $score += 50;
        }

        if (false !== stripos($route_regex, 'nova-breakdance')) {
            $score += 100;
        }

        if (false !== stripos($route_regex, 'nova-wpbakery')) {
            $score += 90;
        }

        if (false !== stripos($route_regex, 'nova-avada')) {
            $score += 80;
        }

        if (false !== stripos($route_regex, 'bridge')) {
            $score += 20;
        }

        return $score;
    }
}
