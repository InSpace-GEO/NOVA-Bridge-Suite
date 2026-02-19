<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Rate_Limiter
{
    public function check(WP_REST_Request $request)
    {
        $limit = (int) apply_filters('npr_rate_limit', 60, $request);
        $window = (int) apply_filters('npr_rate_window', 60, $request);

        if ($limit <= 0 || $window <= 0) {
            return true;
        }

        $subject = $this->subject_key($request);
        $key = 'npr_rate_' . md5($subject);
        $now = time();

        $bucket = get_transient($key);
        if (!is_array($bucket) || !isset($bucket['count'], $bucket['reset'])) {
            $bucket = array(
                'count' => 1,
                'reset' => $now + $window,
            );
            set_transient($key, $bucket, $window);
            return true;
        }

        if ($now >= (int) $bucket['reset']) {
            $bucket = array(
                'count' => 1,
                'reset' => $now + $window,
            );
            set_transient($key, $bucket, $window);
            return true;
        }

        if ((int) $bucket['count'] >= $limit) {
            $retry_after = max(1, (int) $bucket['reset'] - $now);
            return new WP_Error(
                'npr_rate_limited',
                'Rate limit exceeded. Please retry later.',
                array(
                    'status' => 429,
                    'retry_after' => $retry_after,
                )
            );
        }

        $bucket['count'] = (int) $bucket['count'] + 1;
        $remaining = max(1, (int) $bucket['reset'] - $now);
        set_transient($key, $bucket, $remaining);

        return true;
    }

    private function subject_key(WP_REST_Request $request)
    {
        if (is_user_logged_in()) {
            return 'user:' . get_current_user_id();
        }

        $forwarded = (string) $request->get_header('x-forwarded-for');
        if (!empty($forwarded)) {
            $parts = explode(',', $forwarded);
            $ip = trim($parts[0]);
        } else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        }

        return 'ip:' . sha1($ip);
    }
}
