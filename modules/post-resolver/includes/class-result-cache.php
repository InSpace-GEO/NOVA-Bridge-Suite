<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Result_Cache
{
    public function is_cacheable(WP_REST_Request $request)
    {
        $context = (string) $request->get_param('context');
        if ('view' !== $context) {
            return false;
        }

        $flags = array('debug', 'probe_all', 'include_response', 'refresh_routes', 'show_templates');
        foreach ($flags as $flag) {
            if ($this->bool_param($request, $flag)) {
                return false;
            }
        }

        return true;
    }

    public function get($id, WP_REST_Request $request)
    {
        $value = get_transient($this->key($id, $request));
        return is_array($value) ? $value : null;
    }

    public function set($id, WP_REST_Request $request, array $payload, $status)
    {
        $ttl = (int) apply_filters('npr_result_cache_ttl', 600, $request);
        if ($ttl <= 0) {
            return;
        }

        set_transient(
            $this->key($id, $request),
            array(
                'payload' => $payload,
                'status' => (int) $status,
            ),
            $ttl
        );
    }

    private function key($id, WP_REST_Request $request)
    {
        $user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
        $context = (string) $request->get_param('context');
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        $hash_input = implode('|', array((int) $id, $user_id, $context, sha1($ip)));

        return 'npr_result_' . md5($hash_input);
    }

    private function bool_param(WP_REST_Request $request, $name)
    {
        $value = $request->get_param($name);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }
}
