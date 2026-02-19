<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Probe_Trace
{
    public static function row($route, $status, $label)
    {
        return array(
            'route' => (string) $route,
            'status' => (int) $status,
            'label' => (string) $label,
        );
    }

    public static function is_2xx(WP_REST_Response $response)
    {
        $status = (int) $response->get_status();
        return ($status >= 200 && $status < 300);
    }

    public static function already_checked(array $checked, $route)
    {
        foreach ($checked as $item) {
            if (!empty($item['route']) && $item['route'] === $route) {
                return true;
            }
        }

        return false;
    }
}
