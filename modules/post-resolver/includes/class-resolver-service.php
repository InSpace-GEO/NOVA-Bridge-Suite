<?php

if (!defined('ABSPATH')) {
    exit;
}

class NPR_Resolver_Service
{
    /** @var NPR_Rest_Internal_Http */
    private $http;

    /** @var NPR_Route_Discovery */
    private $route_discovery;

    /** @var NPR_Result_Cache */
    private $result_cache;

    public function __construct(NPR_Rest_Internal_Http $http, NPR_Route_Discovery $route_discovery, NPR_Result_Cache $result_cache)
    {
        $this->http = $http;
        $this->route_discovery = $route_discovery;
        $this->result_cache = $result_cache;
    }

    public function resolve($id, WP_REST_Request $request)
    {
        $include_response = $this->bool_param($request, 'include_response');
        $probe_all = $this->bool_param($request, 'probe_all');
        $debug = $this->bool_param($request, 'debug');
        $refresh_routes = $this->bool_param($request, 'refresh_routes');
        $show_templates = $this->bool_param($request, 'show_templates');
        $context = (string) $request->get_param('context');

        $max_probes = (int) $request->get_param('max_probes');
        if ($max_probes < 1) {
            $max_probes = NPR_Plugin::DEFAULT_MAX_PROBES;
        }
        if ($max_probes > NPR_Plugin::HARD_MAX_PROBES) {
            $max_probes = NPR_Plugin::HARD_MAX_PROBES;
        }

        if ($this->result_cache->is_cacheable($request)) {
            $cached = $this->result_cache->get($id, $request);
            if (is_array($cached) && isset($cached['payload'], $cached['status'])) {
                return new WP_REST_Response($cached['payload'], (int) $cached['status']);
            }
        }

        $checked = array();
        $matches = array();
        $status = 404;

        $post = get_post($id);
        if ($post) {
            $type_obj = get_post_type_object($post->post_type);
            if ($type_obj && !empty($type_obj->show_in_rest)) {
                $rest_base = !empty($type_obj->rest_base) ? $type_obj->rest_base : $post->post_type;
                $route = '/wp/v2/' . $rest_base . '/' . $id;
                $fields = $include_response ? '' : 'id,link,status,type,slug';

                $res = $this->http->get($route, $request, $fields);
                if ($debug) {
                    $checked[] = NPR_Probe_Trace::row($route, $res->get_status(), 'fast_path');
                }

                if (NPR_Probe_Trace::is_2xx($res)) {
                    $matches[] = $this->build_match('fast_path', $route, $res->get_data(), $include_response, $post->post_type, $rest_base);
                    $status = 200;

                    if (!$probe_all) {
                        $payload = $this->build_payload($id, $matches, $checked, $debug, false);
                        if ($this->result_cache->is_cacheable($request)) {
                            $this->result_cache->set($id, $request, $payload, $status);
                        }
                        return new WP_REST_Response($payload, $status);
                    }
                }
            }
        }

        $templates = $this->route_discovery->discover($refresh_routes);
        $probed = 0;

        foreach ($templates as $template) {
            if ($probed >= $max_probes) {
                break;
            }

            $route = NPR_Route_Template::instantiate($template['route_regex'], $id);
            if (NPR_Probe_Trace::already_checked($checked, $route)) {
                continue;
            }

            $res = $this->http->get($route, $request);
            $probed++;

            if ($debug) {
                $checked[] = NPR_Probe_Trace::row($route, $res->get_status(), 'bridge_probe');
            }

            if (NPR_Probe_Trace::is_2xx($res)) {
                $matches[] = $this->build_match('bridge_probe', $route, $res->get_data(), $include_response, '', '');
                $status = 200;

                if (!$probe_all) {
                    $payload = $this->build_payload($id, $matches, $checked, $debug, false);
                    if ($debug && $show_templates) {
                        $payload['discovered_templates'] = $this->route_discovery->debug_templates($templates);
                    }
                    if ($this->result_cache->is_cacheable($request)) {
                        $this->result_cache->set($id, $request, $payload, $status);
                    }
                    return new WP_REST_Response($payload, $status);
                }
            }
        }

        $types = get_post_types(array(), 'objects');
        foreach ($types as $type => $obj) {
            if (empty($obj->show_in_rest)) {
                continue;
            }

            $rest_base = !empty($obj->rest_base) ? $obj->rest_base : $type;
            $route = '/wp/v2/' . $rest_base . '/' . $id;

            if (NPR_Probe_Trace::already_checked($checked, $route)) {
                continue;
            }

            $res = $this->http->get($route, $request, 'id,link,status,type,slug');
            if ($debug) {
                $checked[] = NPR_Probe_Trace::row($route, $res->get_status(), 'fallback_probe:' . $type);
            }

            if (NPR_Probe_Trace::is_2xx($res)) {
                $matches[] = $this->build_match('fallback_probe', $route, $res->get_data(), $include_response, $type, $rest_base);
                $status = 200;

                if (!$probe_all) {
                    $payload = $this->build_payload($id, $matches, $checked, $debug, false);
                    if ($this->result_cache->is_cacheable($request)) {
                        $this->result_cache->set($id, $request, $payload, $status);
                    }
                    return new WP_REST_Response($payload, $status);
                }
            }
        }

        $payload = $this->build_payload($id, $matches, $checked, $debug, $probe_all);
        if ($debug && $show_templates) {
            $payload['discovered_templates'] = $this->route_discovery->debug_templates($templates);
        }

        if ($this->result_cache->is_cacheable($request) && 'view' === $context && empty($matches)) {
            $this->result_cache->set($id, $request, $payload, 404);
        }

        return new WP_REST_Response($payload, empty($matches) ? 404 : 200);
    }

    private function build_match($strategy, $route, $data, $include_response, $post_type, $rest_base)
    {
        $match = array(
            'strategy' => $strategy,
            'route' => $route,
            'endpoint' => $this->http->abs($route),
        );

        if (!empty($post_type)) {
            $match['post_type'] = $post_type;
        }
        if (!empty($rest_base)) {
            $match['rest_base'] = $rest_base;
        }

        if (is_array($data)) {
            if (isset($data['status'])) {
                $match['status'] = $data['status'];
            }
            if (isset($data['link'])) {
                $match['link'] = $data['link'];
            }
            if (isset($data['slug'])) {
                $match['slug'] = $data['slug'];
            }
            if (isset($data['type'])) {
                $match['type'] = $data['type'];
            }
            if (isset($data['id'])) {
                $match['resolved_id'] = $data['id'];
            }
        }

        if ($include_response) {
            $match['response'] = $data;
        }

        return $match;
    }

    private function build_payload($id, array $matches, array $checked, $debug, $probe_all)
    {
        $payload = array(
            'id' => (int) $id,
            'found' => !empty($matches),
            'match' => !empty($matches) ? $matches[0] : null,
        );

        if ($probe_all) {
            $payload['matches'] = $matches;
        }

        if ($debug) {
            $payload['checked_count'] = count($checked);
            $payload['checked'] = $checked;
        }

        return $payload;
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
