<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Controller
{
    /** @var NPR_Resolver_Service */
    private $resolver;

    /** @var NPR_Rate_Limiter */
    private $rate_limiter;

    public function __construct(NPR_Resolver_Service $resolver, NPR_Rate_Limiter $rate_limiter)
    {
        $this->resolver = $resolver;
        $this->rate_limiter = $rate_limiter;
    }

    public function register_routes()
    {
        register_rest_route(
            NPR_Plugin::REST_NAMESPACE,
            '/resolve/(?P<id>\\d+)',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'handle_resolve'),
                'permission_callback' => array($this, 'permission_callback'),
                'args' => array(
                    'id' => array(
                        'type' => 'integer',
                        'required' => true,
                    ),
                    'include_response' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                    'probe_all' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                    'debug' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                    'context' => array(
                        'type' => 'string',
                        'default' => 'view',
                    ),
                    'refresh_routes' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                    'max_probes' => array(
                        'type' => 'integer',
                        'default' => (int) apply_filters('npr_default_max_probes', NPR_Plugin::DEFAULT_MAX_PROBES),
                    ),
                    'show_templates' => array(
                        'type' => 'boolean',
                        'default' => false,
                    ),
                ),
            )
        );
    }

    public function permission_callback(WP_REST_Request $request)
    {
        $allow_anonymous = (bool) apply_filters('npr_allow_anonymous', false, $request);

        if (!is_user_logged_in() && !$allow_anonymous) {
            return new WP_Error('npr_auth_required', 'Authentication required.', array('status' => 401));
        }

        if (is_user_logged_in() && !current_user_can('edit_posts')) {
            return new WP_Error('npr_forbidden', 'Insufficient permissions.', array('status' => 403));
        }

        if ($this->has_sensitive_flags($request) && !current_user_can('manage_options')) {
            return new WP_Error('npr_admin_required', 'Admin privileges required for sensitive flags.', array('status' => 403));
        }

        $context = (string) $request->get_param('context');
        if ('edit' === $context && !current_user_can('edit_posts')) {
            return new WP_Error('npr_edit_context_forbidden', 'edit context requires edit_posts capability.', array('status' => 403));
        }

        return $this->rate_limiter->check($request);
    }

    public function handle_resolve(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        return $this->resolver->resolve($id, $request);
    }

    private function has_sensitive_flags(WP_REST_Request $request)
    {
        $flags = array('debug', 'probe_all', 'include_response', 'refresh_routes', 'show_templates');

        foreach ($flags as $flag) {
            if ($this->bool_param($request, $flag)) {
                return true;
            }
        }

        return false;
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
