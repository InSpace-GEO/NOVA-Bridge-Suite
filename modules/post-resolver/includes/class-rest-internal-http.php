<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Rest_Internal_Http
{
    public function get($route, WP_REST_Request $original_request, $fields = '')
    {
        $request = new WP_REST_Request('GET', (string) $route);

        $context = $original_request->get_param('context');
        if (!empty($context)) {
            $request->set_param('context', $context);
        }

        if (!empty($fields)) {
            $request->set_param('_fields', $fields);
        }

        $response = rest_do_request($request);
        if ($response instanceof WP_REST_Response) {
            return $response;
        }

        return rest_ensure_response($response);
    }

    public function abs($route)
    {
        return rest_url(ltrim((string) $route, '/'));
    }
}
