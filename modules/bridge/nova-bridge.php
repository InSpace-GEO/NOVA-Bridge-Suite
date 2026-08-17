<?php
/**
 * NOVA Bridge Suite module: Core bridge.
 */

if (!defined('ABSPATH')) exit;

/**
 * Force-keep HTML (h1–h6, p, a, lists, etc.) in product_cat descriptions via REST
 * Works for /wp/v2/product_cat/... and wc/v3/products/categories
 * Bypasses all sanitizers by writing to the DB after core/other plugins run.
 */

// 1) Stash the raw "description" from the REST request body
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if (!defined('REST_REQUEST') || !REST_REQUEST) return $result;

    $route = $request->get_route();
    if (strpos($route, '/wp/v2/product_cat') !== 0 && strpos($route, '/wc/') !== 0) return $result;

    $raw = json_decode($request->get_body(), true);
    $GLOBALS['nova_bridge_suite_raw_term_desc'] = (is_array($raw) && array_key_exists('description', $raw))
        ? (string) $raw['description']
        : null;

    return $result;
}, 9, 3);

// helper: allow post-level HTML
if (!function_exists('nova_bridge_suite_clean_term_html')) {
    function nova_bridge_suite_clean_term_html($html) {
        return wp_kses((string)$html, wp_kses_allowed_html('post'));
    }
}

// 2) After REST insert/update: write HTML straight into term_taxonomy.description
add_action('rest_insert_product_cat', function ($term, $request, $creating) {
    if (!defined('REST_REQUEST') || !REST_REQUEST) return;
    $raw = isset($GLOBALS['nova_bridge_suite_raw_term_desc']) ? $GLOBALS['nova_bridge_suite_raw_term_desc'] : null;
    if ($raw === null) return;

    global $wpdb;
    $clean = nova_bridge_suite_clean_term_html($raw);

    // Find the exact term_taxonomy row
    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $tt_id = $wpdb->get_var($wpdb->prepare(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
        (int)$term->term_id, 'product_cat'
    ));
    if (!$tt_id) return;

    // Write directly to DB to bypass sanitize_term()
    $wpdb->update($wpdb->term_taxonomy, ['description' => $clean], ['term_taxonomy_id' => $tt_id]);
    // phpcs:enable WordPress.DB.DirectDatabaseQuery

    // Clear caches so subsequent REST response reads the fresh value
    clean_term_cache((int)$term->term_id, 'product_cat');
}, 10, 3);

/**
 * Ensure WooCommerce product_cat shows in REST
 */
add_filter('woocommerce_taxonomy_args_product_cat', function($args){
  $args['show_in_rest'] = true;
  return $args;
});

/** ---------- Helpers (shared) ---------- **/
if (!function_exists('cf_tmrb_sanitize_deep')) {
  function cf_tmrb_sanitize_deep($v) {
    if (is_string($v)) return wp_kses_post($v);
    if (is_array($v)) { foreach ($v as $k => $vv) $v[$k] = cf_tmrb_sanitize_deep($vv); }
    return $v;
  }
  function cf_tmrb_flatten($arr, $prefix = '') {
    $out = [];
    foreach ((array)$arr as $k => $v) {
      $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
      if (is_array($v)) $out += cf_tmrb_flatten($v, $key);
      else $out[$key] = $v;
    }
    return $out;
  }
  function cf_tmrb_set_by_path(&$arr, $path, $value) {
    $segments = is_array($path) ? $path : explode('.', (string)$path);
    $segments = array_map(fn($p) => trim($p, " \t\n\r\0\x0B\"'"), $segments);
    $ref =& $arr; $last = array_pop($segments);
    foreach ($segments as $seg) {
      if ($seg === '') continue;
      if (!isset($ref[$seg]) || !is_array($ref[$seg])) $ref[$seg] = [];
      $ref =& $ref[$seg];
    }
    $ref[$last] = $value;
  }
  function cf_tmrb_find_leaf_paths($arr, $leaf, $prefix='') {
    $paths = [];
    foreach ((array)$arr as $k => $v) {
      $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
      if (is_array($v)) {
        if (array_key_exists($leaf, $v)) $paths[] = $path . '.' . $leaf;
        $paths = array_merge($paths, cf_tmrb_find_leaf_paths($v, $leaf, $path));
      }
    }
    return array_values(array_unique($paths));
  }
  function cf_tmrb_normalize_key($k) {
    // Convert bracket to dot: cat_meta[cat_footer] -> cat_meta.cat_footer
    if (strpos($k, '[') !== false) {
      $k = preg_replace('/\[(.*?)\]/', '.$1', $k);
      $k = preg_replace('/\.+/', '.', $k);
      $k = trim($k, '.');
    }
    return $k;
  }
  function cf_tmrb_is_list_array($arr) {
    if (!is_array($arr)) return false;
    return function_exists('array_is_list')
      ? array_is_list($arr)
      : array_keys($arr) === range(0, count($arr) - 1);
  }
  function cf_tmrb_merge_meta_value($existing, $incoming) {
    if (!is_array($existing) || !is_array($incoming)) return $incoming;
    if (cf_tmrb_is_list_array($existing) || cf_tmrb_is_list_array($incoming)) return $incoming;
    foreach ($incoming as $k => $v) {
      if (array_key_exists($k, $existing)) $existing[$k] = cf_tmrb_merge_meta_value($existing[$k], $v);
      else $existing[$k] = $v;
    }
    return $existing;
  }
}

if (!function_exists('cf_tmrb_acf_field_groups_for_context')) {
  function cf_tmrb_acf_add_top_level_field(&$fields, $field) {
    if (is_array($field) && !empty($field['key'])) {
      $fields[$field['key']] = $field;
    }
  }

  function cf_tmrb_acf_clone_field_is_seamless($field) {
    if (!is_array($field) || empty($field['type']) || $field['type'] !== 'clone') return false;

    $prefix_name = isset($field['prefix_name']) ? $field['prefix_name'] : 0;
    return empty($prefix_name);
  }

  function cf_tmrb_acf_cloned_fields($field) {
    if (!cf_tmrb_acf_clone_field_is_seamless($field) || empty($field['clone']) || !is_array($field['clone'])) {
      return [];
    }

    $fields = [];
    foreach ($field['clone'] as $selector) {
      if (!is_string($selector) || $selector === '') continue;

      $cloned_field = null;
      if (function_exists('acf_get_field')) {
        $cloned_field = acf_get_field($selector);
      }

      if (is_array($cloned_field) && !empty($cloned_field['key'])) {
        $fields[$cloned_field['key']] = $cloned_field;
        continue;
      }

      $cloned_field = cf_tmrb_acf_db_field_by_key($selector);
      if (is_array($cloned_field) && !empty($cloned_field['key'])) {
        $fields[$cloned_field['key']] = $cloned_field;
      }
    }

    return array_values($fields);
  }

  function cf_tmrb_acf_parse_db_field($post) {
    if (!($post instanceof WP_Post)) return null;
    if ($post->post_type !== 'acf-field') return null;

    $settings = maybe_unserialize($post->post_content);
    if (!is_array($settings)) $settings = [];

    $field = $settings;
    $field['ID'] = (int) $post->ID;
    $field['key'] = (string) $post->post_name;
    $field['label'] = (string) $post->post_title;
    $field['name'] = (string) $post->post_excerpt;
    $field['parent'] = (int) $post->post_parent;
    $field['parent_layout'] = isset($settings['parent_layout']) ? (string) $settings['parent_layout'] : '';

    return $field['key'] !== '' && $field['name'] !== '' ? $field : null;
  }

  function cf_tmrb_acf_db_field_by_key($field_key) {
    $field_key = (string) $field_key;
    if ($field_key === '' || strpos($field_key, 'field_') !== 0) return null;

    static $cache = [];
    if (array_key_exists($field_key, $cache)) return $cache[$field_key];

    $posts = get_posts([
      'post_type'        => 'acf-field',
      'post_status'      => ['publish', 'acf-disabled'],
      'name'             => $field_key,
      'posts_per_page'   => 1,
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ]);

    $cache[$field_key] = !empty($posts) ? cf_tmrb_acf_parse_db_field($posts[0]) : null;
    return $cache[$field_key];
  }

  function cf_tmrb_acf_db_field_by_name($field_name, $type = '') {
    $field_name = (string) $field_name;
    if ($field_name === '') return null;

    $type = (string) $type;
    static $cache = [];
    $cache_key = $field_name . ':' . $type;
    if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

    $field_posts = get_posts([
      'post_type'        => 'acf-field',
      'post_status'      => ['publish', 'acf-disabled'],
      'posts_per_page'   => -1,
      'orderby'          => 'menu_order',
      'order'            => 'ASC',
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ]);

    $matches = [];
    foreach ($field_posts as $field_post) {
      $field = cf_tmrb_acf_parse_db_field($field_post);
      if (!is_array($field) || empty($field['key']) || empty($field['name'])) continue;
      if ((string) $field['name'] !== $field_name) continue;
      if ($type !== '' && (string) ($field['type'] ?? '') !== $type) continue;

      $matches[$field['key']] = $field;
    }

    $cache[$cache_key] = count($matches) === 1 ? reset($matches) : null;
    return $cache[$cache_key];
  }

  function cf_tmrb_acf_db_group_matches_context($group_post, $context = []) {
    if (!($group_post instanceof WP_Post)) return false;
    if ($group_post->post_type !== 'acf-field-group') return false;

    $settings = maybe_unserialize($group_post->post_content);
    if (!is_array($settings) || empty($settings['location']) || !is_array($settings['location'])) return false;

    $post_type = isset($context['post_type']) ? sanitize_key((string) $context['post_type']) : '';
    if ($post_type === '' && !empty($context['post_id'])) {
      $detected = get_post_type($context['post_id']);
      $post_type = is_string($detected) ? sanitize_key($detected) : '';
    }

    foreach ($settings['location'] as $rule_group) {
      if (!is_array($rule_group)) continue;

      $has_supported_rule = false;
      $matches = true;

      foreach ($rule_group as $rule) {
        if (!is_array($rule) || empty($rule['param'])) continue;

        if ((string) $rule['param'] !== 'post_type') {
          continue;
        }

        $has_supported_rule = true;
        $expected = sanitize_key((string) ($rule['value'] ?? ''));
        $operator = (string) ($rule['operator'] ?? '==');
        $rule_matches = $post_type !== '' && $expected !== '' && $post_type === $expected;

        if ($operator === '!=') {
          $rule_matches = !$rule_matches;
        }

        if (!$rule_matches) {
          $matches = false;
          break;
        }
      }

      if ($has_supported_rule && $matches) return true;
    }

    return false;
  }

  function cf_tmrb_acf_db_top_level_fields($acf_post_id, $context = []) {
    $context = is_array($context) ? $context : [];
    if (!array_key_exists('post_id', $context)) $context['post_id'] = $acf_post_id;

    static $cache = [];
    $cache_key = md5(serialize([$acf_post_id, $context]));
    if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

    $groups = get_posts([
      'post_type'        => 'acf-field-group',
      'post_status'      => ['publish', 'acf-disabled'],
      'posts_per_page'   => -1,
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ]);

    $group_ids = [];
    foreach ($groups as $group) {
      if (cf_tmrb_acf_db_group_matches_context($group, $context)) {
        $group_ids[] = (int) $group->ID;
      }
    }

    if (empty($group_ids)) {
      $cache[$cache_key] = [];
      return [];
    }

    $field_posts = get_posts([
      'post_type'        => 'acf-field',
      'post_status'      => ['publish', 'acf-disabled'],
      'post_parent__in'  => $group_ids,
      'posts_per_page'   => -1,
      'orderby'          => 'menu_order',
      'order'            => 'ASC',
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ]);

    $fields = [];
    foreach ($field_posts as $field_post) {
      $field = cf_tmrb_acf_parse_db_field($field_post);
      if (!is_array($field) || empty($field['key'])) continue;

      cf_tmrb_acf_add_top_level_field($fields, $field);

      foreach (cf_tmrb_acf_cloned_fields($field) as $cloned_field) {
        cf_tmrb_acf_add_top_level_field($fields, $cloned_field);
      }
    }

    $cache[$cache_key] = array_values($fields);
    return $cache[$cache_key];
  }

  function cf_tmrb_acf_db_child_fields($parent_id, $parent_layout = '') {
    $parent_id = (int) $parent_id;
    if ($parent_id <= 0) return [];

    $parent_layout = (string) $parent_layout;
    static $cache = [];
    $cache_key = $parent_id . ':' . $parent_layout;
    if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

    $field_posts = get_posts([
      'post_type'        => 'acf-field',
      'post_status'      => ['publish', 'acf-disabled'],
      'post_parent'      => $parent_id,
      'posts_per_page'   => -1,
      'orderby'          => 'menu_order',
      'order'            => 'ASC',
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ]);

    $fields = [];
    foreach ($field_posts as $field_post) {
      $field = cf_tmrb_acf_parse_db_field($field_post);
      if (!is_array($field) || empty($field['key']) || empty($field['name'])) continue;
      if ($parent_layout !== '' && (string) ($field['parent_layout'] ?? '') !== $parent_layout) continue;

      $fields[] = $field;
    }

    $cache[$cache_key] = $fields;
    return $fields;
  }

  function cf_tmrb_acf_field_by_name($fields, $name) {
    if (!is_array($fields)) return null;

    $name = (string) $name;
    foreach ($fields as $field) {
      if (!is_array($field)) continue;
      if ((string) ($field['name'] ?? '') === $name || (string) ($field['key'] ?? '') === $name) {
        return $field;
      }
    }

    return null;
  }

  function cf_tmrb_acf_field_groups_for_context($acf_post_id, $context = []) {
    if (!function_exists('acf_get_field_groups')) return [];
    $context = is_array($context) ? $context : [];
    if (!array_key_exists('post_id', $context)) $context['post_id'] = $acf_post_id;

    static $cache = [];
    $cache_key = md5(serialize([$acf_post_id, $context]));
    if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

    $groups = acf_get_field_groups($context);
    if ((!is_array($groups) || empty($groups)) && $acf_post_id) {
      $groups = acf_get_field_groups(['post_id' => $acf_post_id]);
    }

    $cache[$cache_key] = is_array($groups) ? $groups : [];
    return $cache[$cache_key];
  }

  function cf_tmrb_acf_find_top_level_field($selector, $acf_post_id, $context = []) {
    $selector = (string) $selector;
    if ($selector === '') return null;

    $matches = [];
    foreach (cf_tmrb_acf_top_level_fields($acf_post_id, $context) as $field) {
      if (!is_array($field) || empty($field['key'])) continue;

      $key_matches  = isset($field['key']) && $field['key'] === $selector;
      $name_matches = isset($field['name']) && $field['name'] === $selector;
      if ($key_matches || $name_matches) {
        $matches[$field['key']] = $field;
      }
    }

    if (count($matches) !== 1) return null;
    $matches = array_values($matches);
    return $matches[0];
  }

  function cf_tmrb_acf_field_for_selector($selector, $acf_post_id, $context = []) {
    $selector = (string) $selector;
    if ($selector === '') return null;

    static $cache = [];
    $cache_key = md5(serialize([$selector, $acf_post_id, $context]));
    if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

    if (function_exists('get_field_object')) {
      $field = get_field_object($selector, $acf_post_id, false, false);
      if (is_array($field) && !empty($field['key'])) {
        $cache[$cache_key] = $field;
        return $field;
      }
    }

    if (strpos($selector, 'field_') === 0 && function_exists('acf_get_field')) {
      $field = acf_get_field($selector);
      if (is_array($field) && !empty($field['key'])) {
        $cache[$cache_key] = $field;
        return $field;
      }
    }

    $cache[$cache_key] = cf_tmrb_acf_find_top_level_field($selector, $acf_post_id, $context);
    return $cache[$cache_key];
  }

  function cf_tmrb_acf_value_looks_like_raw_storage($field, $value) {
    if (!is_array($field) || empty($field['type'])) return false;

    if ($field['type'] === 'flexible_content') {
      if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        return true;
      }

      if (is_array($value) && cf_tmrb_is_list_array($value)) {
        $first = reset($value);
        return is_string($first);
      }
    }

    if ($field['type'] === 'repeater' && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
      return true;
    }

    return false;
  }

  function cf_tmrb_acf_list_rows($value) {
    return is_array($value) && cf_tmrb_is_list_array($value) ? $value : null;
  }

  function cf_tmrb_acf_value_looks_like_flexible_rows($value) {
    $rows = cf_tmrb_acf_list_rows($value);
    if (!is_array($rows) || empty($rows)) return false;

    $first = reset($rows);
    return is_array($first) && array_key_exists('acf_fc_layout', $first);
  }

  function cf_tmrb_acf_layout_key_by_name($field, $layout_name) {
    if (!is_array($field) || empty($field['layouts']) || !is_array($field['layouts'])) return '';
    $layout_name = (string) $layout_name;
    if ($layout_name === '') return '';

    foreach ($field['layouts'] as $layout_key => $layout) {
      if (!is_array($layout)) continue;
      if ((string) ($layout['name'] ?? '') === $layout_name || (string) ($layout['key'] ?? '') === $layout_name) {
        return (string) ($layout['key'] ?? $layout_key);
      }
    }

    return '';
  }

  function cf_tmrb_acf_child_fields_for_field($field, $parent_layout = '') {
    if (!is_array($field)) return [];

    $parent_layout = (string) $parent_layout;
    if ($parent_layout !== '' && !empty($field['layouts']) && is_array($field['layouts'])) {
      foreach ($field['layouts'] as $layout_key => $layout) {
        if (!is_array($layout)) continue;

        $layout_selectors = [
          (string) $layout_key,
          (string) ($layout['key'] ?? ''),
          (string) ($layout['name'] ?? ''),
        ];

        if (!in_array($parent_layout, array_filter($layout_selectors), true)) continue;

        if (!empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
          return $layout['sub_fields'];
        }
      }
    }

    if ($parent_layout === '' && !empty($field['sub_fields']) && is_array($field['sub_fields'])) {
      return $field['sub_fields'];
    }

    if (!empty($field['ID'])) {
      return cf_tmrb_acf_db_child_fields((int) $field['ID'], $parent_layout);
    }

    return [];
  }

  function cf_tmrb_acf_raw_store_simple_value($post_id, $meta_key, $field, $value) {
    $meta_key = cf_tmrb_normalize_key($meta_key);
    if ($meta_key === '') return;

    update_post_meta((int) $post_id, $meta_key, cf_tmrb_sanitize_deep($value));

    if (is_array($field) && !empty($field['key'])) {
      update_post_meta((int) $post_id, '_' . $meta_key, (string) $field['key']);
    }
  }

  function cf_tmrb_acf_raw_store_repeater_rows($post_id, $meta_key, $field, $rows) {
    if (!is_array($field)) {
      cf_tmrb_acf_raw_store_simple_value($post_id, $meta_key, $field, $rows);
      return;
    }

    $rows = cf_tmrb_acf_list_rows($rows);
    if (!is_array($rows)) {
      cf_tmrb_acf_raw_store_simple_value($post_id, $meta_key, $field, $rows);
      return;
    }

    update_post_meta((int) $post_id, $meta_key, count($rows));
    if (!empty($field['key'])) {
      update_post_meta((int) $post_id, '_' . $meta_key, (string) $field['key']);
    }

    $sub_fields = cf_tmrb_acf_child_fields_for_field($field);
    foreach ($rows as $index => $row) {
      if (!is_array($row)) continue;

      foreach ($row as $child_name => $child_value) {
        $child_field = cf_tmrb_acf_field_by_name($sub_fields, $child_name);
        $child_storage = $meta_key . '_' . (int) $index . '_' . cf_tmrb_normalize_key($child_name);
        cf_tmrb_acf_raw_store_field_value($post_id, $child_storage, $child_field, $child_value);
      }
    }
  }

  function cf_tmrb_acf_raw_store_field_value($post_id, $meta_key, $field, $value) {
    if (is_array($field)) {
      $type = (string) ($field['type'] ?? '');

      if ($type === 'repeater') {
        cf_tmrb_acf_raw_store_repeater_rows($post_id, $meta_key, $field, $value);
        return;
      }

      if ($type === 'group' && is_array($value)) {
        update_post_meta((int) $post_id, '_' . $meta_key, (string) ($field['key'] ?? ''));
        $sub_fields = cf_tmrb_acf_child_fields_for_field($field);
        foreach ($value as $child_name => $child_value) {
          $child_field = cf_tmrb_acf_field_by_name($sub_fields, $child_name);
          $child_storage = $meta_key . '_' . cf_tmrb_normalize_key($child_name);
          cf_tmrb_acf_raw_store_field_value($post_id, $child_storage, $child_field, $child_value);
        }
        return;
      }
    }

    cf_tmrb_acf_raw_store_simple_value($post_id, $meta_key, $field, $value);
  }

  function cf_tmrb_acf_raw_store_flexible_content($post_id, $field, $rows) {
    if (!is_array($field) || (string) ($field['type'] ?? '') !== 'flexible_content') return false;

    $rows = cf_tmrb_acf_list_rows($rows);
    if (!is_array($rows)) return false;

    $field_name = cf_tmrb_normalize_key((string) ($field['name'] ?? ''));
    if ($field_name === '') return false;

    $layouts = [];
    foreach ($rows as $index => $row) {
      if (!is_array($row)) return false;

      $layout_name = isset($row['acf_fc_layout']) ? (string) $row['acf_fc_layout'] : '';
      if ($layout_name === '') return false;

      $layouts[(int) $index] = $layout_name;
      $layout_key = cf_tmrb_acf_layout_key_by_name($field, $layout_name);
      $sub_fields = $layout_key !== '' ? cf_tmrb_acf_child_fields_for_field($field, $layout_key) : [];

      foreach ($row as $child_name => $child_value) {
        if ($child_name === 'acf_fc_layout') continue;

        $child_name = cf_tmrb_normalize_key($child_name);
        if ($child_name === '') continue;

        $child_field = cf_tmrb_acf_field_by_name($sub_fields, $child_name);
        $child_storage = $field_name . '_' . (int) $index . '_' . $child_name;
        cf_tmrb_acf_raw_store_field_value($post_id, $child_storage, $child_field, $child_value);
      }
    }

    update_post_meta((int) $post_id, $field_name, $layouts);
    if (!empty($field['key'])) {
      update_post_meta((int) $post_id, '_' . $field_name, (string) $field['key']);
    }

    return true;
  }

  function cf_tmrb_acf_raw_store_value_if_needed($post_id, $field, $value) {
    if (!is_array($field) || empty($field['type'])) return false;

    if ((string) $field['type'] === 'flexible_content') {
      if (!cf_tmrb_acf_value_looks_like_flexible_rows($value)) return false;

      return cf_tmrb_acf_raw_store_flexible_content($post_id, $field, $value);
    }

    return false;
  }

  function cf_tmrb_acf_raw_storage_field_for_selector($selector, $acf_post_id, $context = []) {
    $selector = cf_tmrb_normalize_key($selector);
    if ($selector === '') return null;

    $field = cf_tmrb_acf_find_top_level_field($selector, $acf_post_id, $context);
    if (is_array($field) && !empty($field['key'])) return $field;

    $field = cf_tmrb_acf_db_field_by_name($selector, 'flexible_content');
    if (is_array($field) && !empty($field['key'])) return $field;

    return cf_tmrb_acf_field_for_selector($selector, $acf_post_id, $context);
  }

  function cf_tmrb_acf_admin_save_value($field, $value) {
    if (!is_array($field)) return $value;

    $type = (string) ($field['type'] ?? '');

    if ($type === 'flexible_content') {
      $rows = cf_tmrb_acf_list_rows($value);
      if (!is_array($rows)) return $value;

      $out = [];
      foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;

        $layout_name = isset($row['acf_fc_layout']) ? (string) $row['acf_fc_layout'] : '';
        $layout_key = cf_tmrb_acf_layout_key_by_name($field, $layout_name);
        if ($layout_key === '') continue;

        $next_row = ['acf_fc_layout' => $layout_name];
        $sub_fields = cf_tmrb_acf_child_fields_for_field($field, $layout_key);

        foreach ($row as $child_name => $child_value) {
          if ($child_name === 'acf_fc_layout') continue;

          $child_field = cf_tmrb_acf_field_by_name($sub_fields, $child_name);
          if (!is_array($child_field) || empty($child_field['key'])) continue;

          $next_row[(string) $child_field['key']] = cf_tmrb_acf_admin_save_value($child_field, $child_value);
        }

        $out[(int) $index] = $next_row;
      }

      return $out;
    }

    if ($type === 'repeater') {
      $rows = cf_tmrb_acf_list_rows($value);
      if (!is_array($rows)) return $value;

      $out = [];
      $sub_fields = cf_tmrb_acf_child_fields_for_field($field);
      foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;

        $next_row = [];
        foreach ($row as $child_name => $child_value) {
          $child_field = cf_tmrb_acf_field_by_name($sub_fields, $child_name);
          if (!is_array($child_field) || empty($child_field['key'])) continue;

          $next_row[(string) $child_field['key']] = cf_tmrb_acf_admin_save_value($child_field, $child_value);
        }

        $out[(int) $index] = $next_row;
      }

      return $out;
    }

    if ($type === 'group' && is_array($value)) {
      $out = [];
      $sub_fields = cf_tmrb_acf_child_fields_for_field($field);
      foreach ($value as $child_name => $child_value) {
        $child_field = cf_tmrb_acf_field_by_name($sub_fields, $child_name);
        if (!is_array($child_field) || empty($child_field['key'])) continue;

        $out[(string) $child_field['key']] = cf_tmrb_acf_admin_save_value($child_field, $child_value);
      }

      return $out;
    }

    return $value;
  }

  function cf_tmrb_save_acf_payload_like_admin($post_id, $payload, $context = [], &$saved_selectors = null) {
    if (!function_exists('acf_save_post') || !is_array($payload) || empty($payload)) return false;

    $post_id = (int) $post_id;
    if ($post_id <= 0) return false;

    static $saving = [];
    if (isset($saving[$post_id])) return false;

    $context = is_array($context) ? $context : [];
    $admin_payload = [];
    $prepared_selectors = [];

    foreach ($payload as $selector => $field_value) {
      $normalized_selector = cf_tmrb_normalize_key($selector);
      $field = cf_tmrb_acf_field_for_selector($normalized_selector, $post_id, $context);
      if (!is_array($field) || empty($field['key'])) continue;

      $admin_payload[(string) $field['key']] = cf_tmrb_acf_admin_save_value($field, cf_tmrb_sanitize_deep($field_value));
      $prepared_selectors[$normalized_selector] = true;
    }

    if (empty($admin_payload)) return false;

    $had_acf_post = array_key_exists('acf', $_POST);
    $previous_acf_post = $had_acf_post ? $_POST['acf'] : null;

    $saving[$post_id] = true;
    $_POST['acf'] = $admin_payload;
    try {
      acf_save_post($post_id);
    } finally {
      if ($had_acf_post) {
        $_POST['acf'] = $previous_acf_post;
      } else {
        unset($_POST['acf']);
      }
      unset($saving[$post_id]);
    }

    if (is_array($saved_selectors)) {
      $saved_selectors = array_keys($prepared_selectors);
    }

    return true;
  }

  function cf_tmrb_raw_store_acf_flexible_payload($post_id, $payload, $context = []) {
    if (!is_array($payload) || empty($payload)) return [];

    $post_id = (int) $post_id;
    $context = is_array($context) ? $context : [];
    $stored = [];

    foreach ($payload as $selector => $field_value) {
      $normalized_selector = cf_tmrb_normalize_key($selector);
      $field = cf_tmrb_acf_raw_storage_field_for_selector($normalized_selector, $post_id, $context);
      if (!is_array($field) || empty($field['key'])) continue;

      if (cf_tmrb_acf_raw_store_value_if_needed($post_id, $field, $field_value)) {
        $stored[$normalized_selector] = true;
      }
    }

    return array_keys($stored);
  }

  function cf_tmrb_acf_flat_count_value($value) {
    if (is_int($value)) return max(0, $value);
    if (is_string($value) && ctype_digit($value)) return (int) $value;
    if (is_array($value)) return count($value);

    return 0;
  }

  function cf_tmrb_acf_flat_rows_for_count($value) {
    if (is_array($value)) return cf_tmrb_acf_list_rows($value);

    $count = cf_tmrb_acf_flat_count_value($value);
    if ($count <= 0) return [];

    return array_fill(0, $count, []);
  }

  function cf_tmrb_acf_value_from_flat_meta($field, $base_key, $flat) {
    if (!is_array($field) || !is_array($flat)) return null;

    $base_key = cf_tmrb_normalize_key($base_key);
    if ($base_key === '') return null;

    $type = (string) ($field['type'] ?? '');

    if ($type === 'flexible_content') {
      if (!array_key_exists($base_key, $flat)) return null;

      $layouts = $flat[$base_key];
      if (is_string($layouts)) {
        $maybe_layouts = maybe_unserialize($layouts);
        if (is_array($maybe_layouts)) $layouts = $maybe_layouts;
      }
      if (!is_array($layouts)) return null;

      $rows = [];
      foreach (array_values($layouts) as $index => $layout_name) {
        if (!is_scalar($layout_name)) continue;

        $layout_name = (string) $layout_name;
        $layout_key = cf_tmrb_acf_layout_key_by_name($field, $layout_name);
        $sub_fields = $layout_key !== '' ? cf_tmrb_acf_child_fields_for_field($field, $layout_key) : [];

        $row = ['acf_fc_layout' => $layout_name];
        foreach ($sub_fields as $sub_field) {
          if (!is_array($sub_field) || empty($sub_field['name'])) continue;

          $child_name = cf_tmrb_normalize_key((string) $sub_field['name']);
          if ($child_name === '') continue;

          $child_key = $base_key . '_' . (int) $index . '_' . $child_name;
          $child_value = cf_tmrb_acf_value_from_flat_meta($sub_field, $child_key, $flat);
          if ($child_value !== null) {
            $row[$child_name] = $child_value;
          }
        }

        $rows[] = $row;
      }

      return $rows;
    }

    if ($type === 'repeater') {
      if (!array_key_exists($base_key, $flat)) return null;

      $seed_rows = cf_tmrb_acf_flat_rows_for_count($flat[$base_key]);
      $sub_fields = cf_tmrb_acf_child_fields_for_field($field);
      $rows = [];

      foreach (array_values($seed_rows) as $index => $seed_row) {
        $row = is_array($seed_row) ? $seed_row : [];
        foreach ($sub_fields as $sub_field) {
          if (!is_array($sub_field) || empty($sub_field['name'])) continue;

          $child_name = cf_tmrb_normalize_key((string) $sub_field['name']);
          if ($child_name === '') continue;

          $child_key = $base_key . '_' . (int) $index . '_' . $child_name;
          $child_value = cf_tmrb_acf_value_from_flat_meta($sub_field, $child_key, $flat);
          if ($child_value !== null) {
            $row[$child_name] = $child_value;
          }
        }

        $rows[] = $row;
      }

      return $rows;
    }

    if ($type === 'group') {
      $sub_fields = cf_tmrb_acf_child_fields_for_field($field);
      $group = [];

      foreach ($sub_fields as $sub_field) {
        if (!is_array($sub_field) || empty($sub_field['name'])) continue;

        $child_name = cf_tmrb_normalize_key((string) $sub_field['name']);
        if ($child_name === '') continue;

        $child_key = $base_key . '_' . $child_name;
        $child_value = cf_tmrb_acf_value_from_flat_meta($sub_field, $child_key, $flat);
        if ($child_value !== null) {
          $group[$child_name] = $child_value;
        }
      }

      if (!empty($group)) return $group;
    }

    return array_key_exists($base_key, $flat) ? $flat[$base_key] : null;
  }

  function cf_tmrb_acf_flat_meta_consumed_keys_for_field($field, $base_key, $flat) {
    if (!is_array($field) || !is_array($flat)) return [];

    $base_key = cf_tmrb_normalize_key($base_key);
    if ($base_key === '' || !array_key_exists($base_key, $flat)) return [];

    $consumed = [$base_key => true];
    $type = (string) ($field['type'] ?? '');

    if ($type === 'flexible_content') {
      $layouts = $flat[$base_key];
      if (is_string($layouts)) {
        $maybe_layouts = maybe_unserialize($layouts);
        if (is_array($maybe_layouts)) $layouts = $maybe_layouts;
      }
      if (!is_array($layouts)) return $consumed;

      foreach (array_values($layouts) as $index => $layout_name) {
        if (!is_scalar($layout_name)) continue;

        $layout_key = cf_tmrb_acf_layout_key_by_name($field, (string) $layout_name);
        $sub_fields = $layout_key !== '' ? cf_tmrb_acf_child_fields_for_field($field, $layout_key) : [];
        foreach ($sub_fields as $sub_field) {
          if (!is_array($sub_field) || empty($sub_field['name'])) continue;

          $child_key = $base_key . '_' . (int) $index . '_' . cf_tmrb_normalize_key((string) $sub_field['name']);
          foreach (cf_tmrb_acf_flat_meta_consumed_keys_for_field($sub_field, $child_key, $flat) as $key => $_value) {
            $consumed[$key] = true;
          }
        }
      }

      return $consumed;
    }

    if ($type === 'repeater') {
      $seed_rows = cf_tmrb_acf_flat_rows_for_count($flat[$base_key]);
      $sub_fields = cf_tmrb_acf_child_fields_for_field($field);
      foreach (array_values($seed_rows) as $index => $_row) {
        foreach ($sub_fields as $sub_field) {
          if (!is_array($sub_field) || empty($sub_field['name'])) continue;

          $child_key = $base_key . '_' . (int) $index . '_' . cf_tmrb_normalize_key((string) $sub_field['name']);
          foreach (cf_tmrb_acf_flat_meta_consumed_keys_for_field($sub_field, $child_key, $flat) as $key => $_value) {
            $consumed[$key] = true;
          }
        }
      }

      return $consumed;
    }

    if ($type === 'group') {
      foreach (cf_tmrb_acf_child_fields_for_field($field) as $sub_field) {
        if (!is_array($sub_field) || empty($sub_field['name'])) continue;

        $child_key = $base_key . '_' . cf_tmrb_normalize_key((string) $sub_field['name']);
        foreach (cf_tmrb_acf_flat_meta_consumed_keys_for_field($sub_field, $child_key, $flat) as $key => $_value) {
          $consumed[$key] = true;
        }
      }
    }

    return $consumed;
  }

  function cf_tmrb_acf_payload_from_flat_meta($flat, $post_id, $context = [], &$consumed_keys = null) {
    if (!is_array($flat) || empty($flat)) return [];

    $payload = [];
    foreach (cf_tmrb_acf_top_level_fields($post_id, $context) as $field) {
      if (!is_array($field) || empty($field['name'])) continue;

      $field_name = cf_tmrb_normalize_key((string) $field['name']);
      if ($field_name === '' || !array_key_exists($field_name, $flat)) continue;

      $field_value = cf_tmrb_acf_value_from_flat_meta($field, $field_name, $flat);
      if ($field_value !== null) {
        $payload[$field_name] = $field_value;
        if ($consumed_keys !== null) {
          if (!is_array($consumed_keys)) $consumed_keys = [];
          foreach (cf_tmrb_acf_flat_meta_consumed_keys_for_field($field, $field_name, $flat) as $key => $_value) {
            $consumed_keys[$key] = true;
          }
        }
      }
    }

    return $payload;
  }

  function cf_tmrb_update_acf_field_if_available($selector, $value, $acf_post_id, $context = [], &$acf_save_values = null, &$acf_touched = null) {
    if (!function_exists('update_field')) return false;

    $field = cf_tmrb_acf_field_for_selector($selector, $acf_post_id, $context);
    if (!is_array($field) || empty($field['key'])) {
      if (cf_tmrb_acf_value_looks_like_flexible_rows($value)) {
        $raw_storage_field = cf_tmrb_acf_raw_storage_field_for_selector($selector, $acf_post_id, $context);
        if (is_array($raw_storage_field) && !empty($raw_storage_field['key']) && cf_tmrb_acf_raw_store_value_if_needed($acf_post_id, $raw_storage_field, $value)) {
          if ($acf_touched !== null) $acf_touched = true;
          return $raw_storage_field;
        }
      }

      return false;
    }
    if ($acf_touched !== null) $acf_touched = true;

    $raw_storage_field = $field;
    if ((string) ($field['type'] ?? '') === 'flexible_content' && empty($field['ID'])) {
      $resolved_field = cf_tmrb_acf_raw_storage_field_for_selector($selector, $acf_post_id, $context);
      if (is_array($resolved_field) && !empty($resolved_field['key'])) {
        $raw_storage_field = $resolved_field;
      }
    }

    if (cf_tmrb_acf_raw_store_value_if_needed($acf_post_id, $raw_storage_field, $value)) return $raw_storage_field;
    if (cf_tmrb_acf_value_looks_like_raw_storage($field, $value)) return false;

    // Use the ACF field key so new API-created values get their hidden reference meta.
    update_field($field['key'], $value, $acf_post_id);
    return $field;
  }

  function cf_tmrb_acf_top_level_fields($acf_post_id, $context = []) {
    static $cache = [];
    $cache_key = md5(serialize([$acf_post_id, $context]));
    if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

    $fields = [];
    if (function_exists('acf_get_fields')) {
      foreach (cf_tmrb_acf_field_groups_for_context($acf_post_id, $context) as $group) {
        $group_fields = acf_get_fields($group);
        if (!is_array($group_fields)) continue;

        foreach ($group_fields as $field) {
          cf_tmrb_acf_add_top_level_field($fields, $field);

          foreach (cf_tmrb_acf_cloned_fields($field) as $cloned_field) {
            cf_tmrb_acf_add_top_level_field($fields, $cloned_field);
          }
        }
      }
    }

    foreach (cf_tmrb_acf_db_top_level_fields($acf_post_id, $context) as $field) {
      cf_tmrb_acf_add_top_level_field($fields, $field);

      foreach (cf_tmrb_acf_cloned_fields($field) as $cloned_field) {
        cf_tmrb_acf_add_top_level_field($fields, $cloned_field);
      }
    }

    $cache[$cache_key] = array_values($fields);
    return $cache[$cache_key];
  }

  function cf_tmrb_acf_match_field_from_path($path, $fields) {
    $parts = explode('_', (string) $path);
    if (empty($parts) || !is_array($fields)) return null;

    for ($length = count($parts); $length >= 1; $length--) {
      $candidate = implode('_', array_slice($parts, 0, $length));

      foreach ($fields as $field) {
        if (!is_array($field) || empty($field['key'])) continue;

        $name_matches = isset($field['name']) && (string) $field['name'] === $candidate;
        $key_matches  = isset($field['key']) && (string) $field['key'] === $candidate;
        if (!$name_matches && !$key_matches) continue;

        return [
          'field'     => $field,
          'remaining' => array_slice($parts, $length),
        ];
      }
    }

    return null;
  }

  function cf_tmrb_acf_find_flexible_layout($field, $layout_name) {
    if (!is_array($field) || empty($field['layouts']) || !is_array($field['layouts'])) return null;
    $layout_name = (string) $layout_name;
    if ($layout_name === '') return null;

    foreach ($field['layouts'] as $layout) {
      if (!is_array($layout)) continue;

      $name_matches = isset($layout['name']) && (string) $layout['name'] === $layout_name;
      $key_matches  = isset($layout['key']) && (string) $layout['key'] === $layout_name;
      if ($name_matches || $key_matches) return $layout;
    }

    return null;
  }

  function cf_tmrb_acf_raw_index_value($top_name, $index, $incoming, $existing) {
    $layout_key = $top_name . '_' . (int) $index . '_acf_fc_layout';
    $sources = [$incoming, $existing];
    foreach ($sources as $source) {
      if (is_array($source) && array_key_exists($layout_key, $source) && is_scalar($source[$layout_key])) {
        return (string) $source[$layout_key];
      }

      if (!is_array($source) || !array_key_exists($top_name, $source) || !is_array($source[$top_name])) {
        continue;
      }

      if (array_key_exists($index, $source[$top_name])) {
        $row = $source[$top_name][$index];
        if (is_array($row) && isset($row['acf_fc_layout']) && is_scalar($row['acf_fc_layout'])) {
          return (string) $row['acf_fc_layout'];
        }
        if (is_scalar($row)) return (string) $row;
      }

      $string_index = (string) $index;
      if (array_key_exists($string_index, $source[$top_name])) {
        $row = $source[$top_name][$string_index];
        if (is_array($row) && isset($row['acf_fc_layout']) && is_scalar($row['acf_fc_layout'])) {
          return (string) $row['acf_fc_layout'];
        }
        if (is_scalar($row)) return (string) $row;
      }
    }

    return '';
  }

  function cf_tmrb_acf_resolve_sub_field_key_from_parts($parts, $fields) {
    if (empty($parts) || !is_array($fields)) return '';

    $match = cf_tmrb_acf_match_field_from_path(implode('_', $parts), $fields);
    if (!is_array($match) || empty($match['field']['key'])) return '';

    $field = $match['field'];
    $remaining = isset($match['remaining']) && is_array($match['remaining']) ? $match['remaining'] : [];
    if (empty($remaining)) return (string) $field['key'];

    $type = isset($field['type']) ? (string) $field['type'] : '';
    if (in_array($type, ['repeater', 'group', 'clone'], true) && !empty($field['sub_fields']) && is_array($field['sub_fields'])) {
      if ($type === 'repeater' && isset($remaining[0]) && ctype_digit((string) $remaining[0])) {
        array_shift($remaining);
      }

      return cf_tmrb_acf_resolve_sub_field_key_from_parts($remaining, $field['sub_fields']);
    }

    return '';
  }

  function cf_tmrb_acf_resolve_raw_reference_key($raw_key, $incoming, $existing, $acf_post_id, $context = []) {
    $raw_key = cf_tmrb_normalize_key($raw_key);
    if ($raw_key === '' || strpos($raw_key, '_') === 0 || strpos($raw_key, '.') !== false) return '';

    $direct_field = cf_tmrb_acf_field_for_selector($raw_key, $acf_post_id, $context);
    if (is_array($direct_field) && !empty($direct_field['key'])) {
      return (string) $direct_field['key'];
    }

    $top_match = cf_tmrb_acf_match_field_from_path($raw_key, cf_tmrb_acf_top_level_fields($acf_post_id, $context));
    if (!is_array($top_match) || empty($top_match['field']['key'])) return '';

    $top_field = $top_match['field'];
    $remaining = isset($top_match['remaining']) && is_array($top_match['remaining']) ? $top_match['remaining'] : [];
    if (empty($remaining)) return (string) $top_field['key'];

    $top_name = isset($top_field['name']) ? (string) $top_field['name'] : '';
    $type = isset($top_field['type']) ? (string) $top_field['type'] : '';

    if ($type === 'flexible_content' && isset($remaining[0]) && ctype_digit((string) $remaining[0])) {
      $row_index = (int) array_shift($remaining);
      $layout_name = cf_tmrb_acf_raw_index_value($top_name, $row_index, $incoming, $existing);
      $layout = cf_tmrb_acf_find_flexible_layout($top_field, $layout_name);

      if (is_array($layout) && !empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
        return cf_tmrb_acf_resolve_sub_field_key_from_parts($remaining, $layout['sub_fields']);
      }

      return '';
    }

    if ($type === 'repeater' && isset($remaining[0]) && ctype_digit((string) $remaining[0])) {
      array_shift($remaining);
    }

    if (in_array($type, ['repeater', 'group', 'clone'], true) && !empty($top_field['sub_fields']) && is_array($top_field['sub_fields'])) {
      return cf_tmrb_acf_resolve_sub_field_key_from_parts($remaining, $top_field['sub_fields']);
    }

    return '';
  }

  function cf_tmrb_sync_post_acf_raw_references($post_id, $incoming, $existing, $context = []) {
    if (!function_exists('acf_get_field_groups')) return false;

    $updated = false;

    $reference_source = cf_tmrb_acf_reference_source_from_payload($incoming);
    foreach ($reference_source as $raw_key => $_value) {
      $norm_key = cf_tmrb_normalize_key($raw_key);
      if ($norm_key === '' || strpos($norm_key, '_') === 0) continue;

      $field_key = cf_tmrb_acf_resolve_raw_reference_key($norm_key, $reference_source, $existing, $post_id, $context);
      if ($field_key === '') continue;

      update_post_meta((int) $post_id, '_' . $norm_key, $field_key);
      $updated = true;
    }

    if (function_exists('cf_tmrb_sync_post_acf_clone_wrappers')) {
      cf_tmrb_sync_post_acf_clone_wrappers($post_id, $incoming, $existing, $context);
    }

    return $updated;
  }

  function cf_tmrb_sync_term_acf_raw_references($term_id, $taxonomy, $incoming, $existing, $context = []) {
    if (!function_exists('acf_get_field_groups')) return;

    $acf_post_id = "{$taxonomy}_{$term_id}";
    $reference_source = cf_tmrb_acf_reference_source_from_payload($incoming);
    foreach ($reference_source as $raw_key => $_value) {
      $norm_key = cf_tmrb_normalize_key($raw_key);
      if ($norm_key === '' || strpos($norm_key, '_') === 0) continue;

      $field_key = cf_tmrb_acf_resolve_raw_reference_key($norm_key, $reference_source, $existing, $acf_post_id, $context);
      if ($field_key === '') continue;

      update_term_meta((int) $term_id, '_' . $norm_key, $field_key);
    }
  }

  function cf_tmrb_acf_post_context($post_id) {
    $post = get_post($post_id);
    $context = ['post_id' => $post_id];

    if ($post instanceof WP_Post) {
      $context['post_type'] = $post->post_type;
      $context['post_status'] = $post->post_status;
    }

    return (array) apply_filters('cf_tmrb_acf_post_context', $context, $post_id);
  }

  function cf_tmrb_acf_term_context($taxonomy, $term_id) {
    $acf_post_id = "{$taxonomy}_{$term_id}";
    $context = [
      'post_id'  => $acf_post_id,
      'taxonomy' => $taxonomy,
      'term_id'  => $term_id,
    ];

    return (array) apply_filters('cf_tmrb_acf_term_context', $context, $taxonomy, $term_id);
  }
}

/** ---------- Legacy options for terms ---------- **/
if (!function_exists('cf_tmrb_legacy_option_candidates')) {
  function cf_tmrb_legacy_option_candidates($taxonomy, $term_id) {
    $names = [ "{$taxonomy}_{$term_id}" ];
    if ($taxonomy !== 'category') $names[] = "category_{$term_id}";
    $names[] = "term_{$term_id}";
    return array_unique((array) apply_filters('cf_tmrb_legacy_option_patterns', $names, $taxonomy, $term_id));
  }
  function cf_tmrb_load_legacy_options($taxonomy, $term_id) {
    $out = [];
    foreach (cf_tmrb_legacy_option_candidates($taxonomy, $term_id) as $name) {
      $val = get_option($name);
      if (is_array($val) && !empty($val)) $out[$name] = $val;
    }
    return $out;
  }
  function cf_tmrb_update_legacy_option($option_name, $arr) {
    if (!is_array($arr)) return false;
    return update_option($option_name, $arr);
  }
}

/** ---------- Yoast taxonomy meta helpers ---------- **/
if (!function_exists('cf_tmrb_map_yoast_term_key')) {
  function cf_tmrb_map_yoast_term_key($key) {
    $key = trim((string)$key);
    if ($key === '') return '';

    if (strpos($key, '_yoast_wpseo_') === 0) {
      $rest = substr($key, strlen('_yoast_wpseo_'));
      if ($rest === 'metadesc' || $rest === 'metadescription') return 'wpseo_desc';
      return 'wpseo_' . $rest;
    }

    if ($key === 'wpseo_metadesc' || $key === 'wpseo_metadescription') return 'wpseo_desc';
    if (strpos($key, 'wpseo_') === 0) return $key;

    return '';
  }

  function cf_tmrb_get_yoast_term_meta($taxonomy, $term_id) {
    $opt = get_option('wpseo_taxonomy_meta');
    if (!is_array($opt) || !isset($opt[$taxonomy]) || !is_array($opt[$taxonomy])) return [];

    $term_id = (int) $term_id;
    return (isset($opt[$taxonomy][$term_id]) && is_array($opt[$taxonomy][$term_id]))
      ? $opt[$taxonomy][$term_id]
      : [];
  }

  function cf_tmrb_update_yoast_term_meta($taxonomy, $term_id, array $updates) {
    $canon = [];
    foreach ($updates as $k => $v) {
      $mapped = cf_tmrb_map_yoast_term_key($k);
      if ($mapped !== '') $canon[$mapped] = $v;
    }
    if (empty($canon)) return false;

    $all = get_option('wpseo_taxonomy_meta');
    if (!is_array($all)) $all = [];
    if (!isset($all[$taxonomy]) || !is_array($all[$taxonomy])) $all[$taxonomy] = [];

    $term_id = (int) $term_id;
    if (!isset($all[$taxonomy][$term_id]) || !is_array($all[$taxonomy][$term_id])) $all[$taxonomy][$term_id] = [];

    $changed = false;
    foreach ($canon as $k => $v) {
      if (!array_key_exists($k, $all[$taxonomy][$term_id]) || $all[$taxonomy][$term_id][$k] !== $v) {
        $all[$taxonomy][$term_id][$k] = $v;
        $changed = true;
      }
    }

    if ($changed) update_option('wpseo_taxonomy_meta', $all);
    return $changed;
  }

  function cf_tmrb_touch_yoast_term_indexable($term_obj) {
    if (!($term_obj instanceof WP_Term)) return;

    $term_id  = (int) $term_obj->term_id;
    $taxonomy = $term_obj->taxonomy;
    $tt_id    = isset($term_obj->term_taxonomy_id) ? (int) $term_obj->term_taxonomy_id : 0;

    if (function_exists('YoastSEO') && class_exists('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository')) {
      try {
        $yoast = YoastSEO();
        if (is_object($yoast) && isset($yoast->classes) && method_exists($yoast->classes, 'get')) {
          $repo = $yoast->classes->get('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository');
          if (is_object($repo)) {
            if (method_exists($repo, 'delete_by_object_id_and_type')) {
              $repo->delete_by_object_id_and_type($term_id, 'term');
            } elseif (method_exists($repo, 'find_by_id_and_type') && method_exists($repo, 'delete')) {
              $idx = $repo->find_by_id_and_type($term_id, 'term');
              if ($idx) $repo->delete($idx);
            }
          }
        }
      } catch (\Throwable $e) { /* best effort only */ }
    }

    do_action('edited_term', $term_id, $tt_id, $taxonomy); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
    clean_term_cache($term_id, $taxonomy);
  }
}

/** ---------- Guess container from other TERMS (leaf-only writes) ---------- **/
if (!function_exists('cf_tmrb_guess_container_for_leaf')) {
  function cf_tmrb_guess_container_for_leaf($taxonomy, $leaf) {
    $cache_key = "tmrb_guess_{$taxonomy}_" . md5($leaf);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $candidates = [];

    $term_ids = get_terms([
      'taxonomy'   => $taxonomy,
      'fields'     => 'ids',
      'number'     => 150,
      'hide_empty' => false,
    ]);

    foreach ($term_ids as $tid) {
      $all = get_term_meta($tid);
      foreach ($all as $k => $vals) {
        if (strpos($k, '_') === 0) continue;
        $val = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
        if (is_array($val) && array_key_exists($leaf, $val)) {
          $candidates[] = ['type' => 'term', 'top' => $k];
        }
      }
      $legacy = cf_tmrb_load_legacy_options($taxonomy, $tid);
      foreach ($legacy as $opt_name => $arr) {
        if (is_array($arr) && array_key_exists($leaf, $arr)) {
          $candidates[] = ['type' => 'opt', 'opt' => $opt_name, 'top' => null];
        }
        foreach ($arr as $k => $v) {
          if (is_array($v) && array_key_exists($leaf, $v)) {
            $candidates[] = ['type' => 'opt', 'opt' => $opt_name, 'top' => $k];
          }
        }
      }
    }

    $uniq = [];
    foreach ($candidates as $c) $uniq[ md5(serialize($c)) ] = $c;
    $candidates = array_values($uniq);

    $result = count($candidates) === 1 ? $candidates[0] : '';
    set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
    return $result;
  }
}

/** ---------- Guess container from other POSTS (leaf-only writes) ---------- **/
if (!function_exists('cf_tmrb_guess_post_container_for_leaf')) {
  function cf_tmrb_guess_post_container_for_leaf($post_type, $leaf) {
    $cache_key = "tmrb_guess_post_{$post_type}_" . md5($leaf);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $candidates = [];

    $q = new WP_Query([
      'post_type'      => $post_type,
      'post_status'    => 'any',
      'posts_per_page' => 150,
      'fields'         => 'ids',
      'no_found_rows'  => true,
      'orderby'        => 'date',
      'order'          => 'DESC',
    ]);

    foreach ($q->posts as $pid) {
      $all = get_post_meta($pid);
      foreach ($all as $k => $vals) {
        if (strpos($k, '_') === 0) continue;
        $val = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
        if (is_array($val) && array_key_exists($leaf, $val)) {
          $candidates[] = ['type' => 'post', 'top' => $k];
        }
      }
    }

    $uniq = [];
    foreach ($candidates as $c) $uniq[ md5(serialize($c)) ] = $c;
    $candidates = array_values($uniq);

    $result = count($candidates) === 1 ? $candidates[0] : '';
    set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
    return $result;
  }
}

/** ---------- Optional Genesis fallback for POSTS ---------- **/
if (!function_exists('cf_tmrb_apply_genesis_fallback')) {
  function cf_tmrb_apply_genesis_fallback($post_id, $leaf, $value) {
    $leaf = (string) $leaf;
    $map = (array) apply_filters('cf_tmrb_post_genesis_leaf_map', [
      'doctitle'    => '_genesis_title',
      'description' => '_genesis_description',
    ], $post_id, $leaf, $value);

    if (isset($map[$leaf]) && is_string($map[$leaf]) && $map[$leaf] !== '') {
      update_post_meta($post_id, $map[$leaf], $value);
    }
  }
}

/** ---------- SEO plugin detection + key mapping for POSTS ---------- **/
if (!function_exists('cf_tmrb_get_active_plugin_basenames')) {
  function cf_tmrb_get_active_plugin_basenames() {
    $active = get_option('active_plugins');
    if (!is_array($active)) $active = [];

    if (is_multisite()) {
      $sitewide = get_site_option('active_sitewide_plugins');
      if (is_array($sitewide)) $active = array_merge($active, array_keys($sitewide));
    }

    $active = array_map('strval', $active);
    return array_values(array_unique($active));
  }
}

if (!function_exists('cf_tmrb_detect_active_seo_plugins')) {
  function cf_tmrb_detect_active_seo_plugins() {
    $active_plugins = cf_tmrb_get_active_plugin_basenames();
    $plugin_active = function($basename) use ($active_plugins) {
      return in_array((string) $basename, $active_plugins, true);
    };

    $detected = [
      'yoast' => $plugin_active('wordpress-seo/wp-seo.php'),
      'rank_math' => $plugin_active('seo-by-rank-math/rank-math.php'),
      'tsf' => $plugin_active('autodescription/autodescription.php'),
      'aioseo' => $plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php'),
      'seopress' => $plugin_active('wp-seopress/seopress.php'),
      'smartcrawl' => $plugin_active('smartcrawl-seo/wpmu-dev-seo.php') || $plugin_active('wpmu-dev-seo/wpmu-dev-seo.php'),
      'squirrly' => $plugin_active('squirrly-seo/squirrly.php'),
      'slim_seo' => $plugin_active('slim-seo/slim-seo.php'),
    ];

    return (array) apply_filters('cf_tmrb_active_seo_plugins', $detected);
  }
}

if (!function_exists('cf_tmrb_post_seo_key_map')) {
  function cf_tmrb_post_seo_key_map() {
    $map = [
      'yoast' => [
        'title'       => '_yoast_wpseo_title',
        'description' => '_yoast_wpseo_metadesc',
      ],
      'rank_math' => [
        'title'       => 'rank_math_title',
        'description' => 'rank_math_description',
      ],
      'tsf' => [
        'title'       => '_genesis_title',
        'description' => '_genesis_description',
      ],
      'aioseo' => [
        'title'       => '_aioseo_title',
        'description' => '_aioseo_description',
      ],
      'seopress' => [
        'title'       => '_seopress_titles_title',
        'description' => '_seopress_titles_desc',
      ],
      'smartcrawl' => [
        'title'       => '_wds_title',
        'description' => '_wds_metadesc',
      ],
      'squirrly' => [
        'title'       => '_sq_title',
        'description' => '_sq_description',
      ],
      'slim_seo' => [
        'title'       => 'slim_seo.title',
        'description' => 'slim_seo.description',
      ],
    ];

    return (array) apply_filters('cf_tmrb_post_seo_key_map', $map);
  }
}

if (!function_exists('cf_tmrb_post_seo_alias_groups')) {
  function cf_tmrb_post_seo_alias_groups() {
    $groups = [
      'title' => [
        'meta_title',
        '_yoast_wpseo_title',
        'wpseo_title',
        'rank_math_title',
        '_genesis_title',
        'autodescription-meta.title',
        'autodescription-meta.doctitle',
        '_aioseo_title',
        'aioseo_title',
        '_seopress_titles_title',
        'seopress_titles_title',
        '_wds_title',
        'wds_title',
        '_sq_title',
        'sq_title',
        'slim_seo.title',
        'slim_seo_title',
      ],
      'description' => [
        'meta_description',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_metadescription',
        'wpseo_desc',
        'wpseo_metadesc',
        'wpseo_metadescription',
        'rank_math_description',
        '_genesis_description',
        'autodescription-meta.description',
        '_aioseo_description',
        'aioseo_description',
        '_seopress_titles_desc',
        'seopress_titles_desc',
        '_wds_metadesc',
        'wds_metadesc',
        '_sq_description',
        'sq_description',
        'slim_seo.description',
        'slim_seo_description',
      ],
    ];

    return (array) apply_filters('cf_tmrb_post_seo_alias_groups', $groups);
  }
}

if (!function_exists('cf_tmrb_coerce_post_seo_string')) {
  function cf_tmrb_coerce_post_seo_string($value, &$ok = true) {
    $ok = false;
    if (is_scalar($value) || $value === null) {
      $ok = true;
      return (string) $value;
    }
    return '';
  }
}

if (!function_exists('cf_tmrb_resolve_post_seo_from_meta_input')) {
  function cf_tmrb_resolve_post_seo_from_meta_input(array $meta_input) {
    $aliases = cf_tmrb_post_seo_alias_groups();
    $consumed = [];
    $title = null;
    $description = null;

    $resolver = function(array $keys) use ($meta_input, &$consumed) {
      $resolved = null;
      foreach ($keys as $key) {
        if (!array_key_exists($key, $meta_input)) continue;
        $consumed[] = $key;
        if ($resolved !== null) continue;
        $ok = false;
        $resolved = cf_tmrb_coerce_post_seo_string($meta_input[$key], $ok);
        if (!$ok) $resolved = null;
      }
      return $resolved;
    };

    if (isset($aliases['title']) && is_array($aliases['title'])) {
      $title = $resolver($aliases['title']);
    }
    if (isset($aliases['description']) && is_array($aliases['description'])) {
      $description = $resolver($aliases['description']);
    }

    $key_map = cf_tmrb_post_seo_key_map();
    $active_plugins = cf_tmrb_detect_active_seo_plugins();

    $target_plugins = [];
    foreach ($active_plugins as $slug => $is_active) {
      if (!empty($is_active) && isset($key_map[$slug])) $target_plugins[] = $slug;
    }
    if (empty($target_plugins)) $target_plugins = array_keys($key_map);

    $updates = [];
    if ($title !== null) {
      foreach ($target_plugins as $slug) {
        $target_key = $key_map[$slug]['title'] ?? '';
        if (is_string($target_key) && $target_key !== '') $updates[$target_key] = $title;
      }
    }
    if ($description !== null) {
      foreach ($target_plugins as $slug) {
        $target_key = $key_map[$slug]['description'] ?? '';
        if (is_string($target_key) && $target_key !== '') $updates[$target_key] = $description;
      }
    }

    $consumed = array_values(array_unique($consumed));
    $updates  = (array) apply_filters(
      'cf_tmrb_post_seo_updates',
      $updates,
      $meta_input,
      $active_plugins,
      $target_plugins,
      $title,
      $description
    );

    return [
      'title'          => $title,
      'description'    => $description,
      'consumed_keys'  => $consumed,
      'updates'        => $updates,
      'active_plugins' => $active_plugins,
      'target_plugins' => $target_plugins,
    ];
  }
}

if (!function_exists('cf_tmrb_enrich_post_meta_with_seo_aliases')) {
  function cf_tmrb_enrich_post_meta_with_seo_aliases(array $meta_values, $include_private = false) {
    $resolved = cf_tmrb_resolve_post_seo_from_meta_input($meta_values);

    if ($resolved['title'] !== null && !array_key_exists('meta_title', $meta_values)) {
      $meta_values['meta_title'] = $resolved['title'];
    }
    if ($resolved['description'] !== null && !array_key_exists('meta_description', $meta_values)) {
      $meta_values['meta_description'] = $resolved['description'];
    }

    if ($include_private) {
      foreach ((array) $resolved['updates'] as $k => $v) {
        if (!array_key_exists($k, $meta_values)) $meta_values[$k] = $v;
      }
    }

    return $meta_values;
  }
}

/** ===========================================================
 *  product_cat: meta_all & meta_all_flat  (conditional private)
 *  =========================================================== */
if (!function_exists('cf_tmrb_expand_nested_acf_meta_payload')) {
  function cf_tmrb_expand_nested_acf_meta_payload($value) {
    if (!is_array($value) || !isset($value['acf']) || !is_array($value['acf'])) {
      return $value;
    }

    $acf = $value['acf'];
    unset($value['acf']);

    // Accept meta_all.acf as a grouped ACF payload while preserving explicit top-level keys.
    foreach ($acf as $acf_key => $acf_value) {
      if (!array_key_exists($acf_key, $value)) {
        $value[$acf_key] = $acf_value;
      }
    }

    return $value;
  }

  function cf_tmrb_extract_grouped_acf_payload($value) {
    return (is_array($value) && isset($value['acf']) && is_array($value['acf'])) ? $value['acf'] : null;
  }

  function cf_tmrb_acf_schema_keys_from_rest_handler($handler) {
    if (!is_array($handler) || empty($handler['args']) || !is_array($handler['args'])) return [];
    if (empty($handler['args']['acf']['properties']) || !is_array($handler['args']['acf']['properties'])) return [];

    return array_fill_keys(array_map('strval', array_keys($handler['args']['acf']['properties'])), true);
  }

  function cf_tmrb_extract_direct_acf_payload($value, $acf_schema_keys) {
    if (!is_array($value) || empty($acf_schema_keys)) return null;

    $acf = [];
    foreach ($value as $key => $field_value) {
      $norm_key = cf_tmrb_normalize_key($key);
      if (isset($acf_schema_keys[$norm_key])) {
        $acf[$norm_key] = $field_value;
      }
    }

    return !empty($acf) ? $acf : null;
  }

  function cf_tmrb_merge_acf_payload(&$target, $payload) {
    if (!is_array($payload)) return;

    foreach ($payload as $key => $value) {
      $target[$key] = $value;
    }
  }

  function cf_tmrb_acf_payload_from_rest_request($request, $handler = null, $post_id = 0) {
    if (!($request instanceof WP_REST_Request)) return null;

    $acf_schema_keys = cf_tmrb_acf_schema_keys_from_rest_handler($handler);
    $post_id = (int) $post_id;
    $acf_context = $post_id > 0 ? cf_tmrb_acf_post_context($post_id) : [];
    $payload = [];
    cf_tmrb_merge_acf_payload($payload, $request->get_param('acf'));

    $merge_meta_source = function ($source) use (&$payload, $acf_schema_keys, $post_id, $acf_context) {
      cf_tmrb_merge_acf_payload($payload, cf_tmrb_extract_grouped_acf_payload($source));
      cf_tmrb_merge_acf_payload($payload, cf_tmrb_extract_direct_acf_payload($source, $acf_schema_keys));
      if ($post_id > 0) {
        cf_tmrb_merge_acf_payload($payload, cf_tmrb_acf_payload_from_flat_meta($source, $post_id, $acf_context));
      }
    };

    $meta_all = $request->get_param('meta_all');
    $merge_meta_source($meta_all);

    $meta = $request->get_param('meta');
    if (is_array($meta)) {
      $merge_meta_source($meta);

      if (isset($meta['meta_all'])) {
        $merge_meta_source($meta['meta_all']);
      }

      if (isset($meta['meta_all_flat'])) {
        $merge_meta_source($meta['meta_all_flat']);
      }
    }

    return !empty($payload) ? $payload : null;
  }

  function cf_tmrb_add_acf_reference_paths(&$source, $prefix, $value) {
    if (!is_array($source) || !is_array($value) || $prefix === '') return;

    foreach ($value as $key => $child_value) {
      if ($key === 'acf_fc_layout') continue;

      $key = cf_tmrb_normalize_key($key);
      if ($key === '') continue;

      $storage_key = $prefix . '_' . $key;
      $source[$storage_key] = $child_value;

      if (is_array($child_value)) {
        foreach ($child_value as $child_key => $grandchild_value) {
          if (is_array($grandchild_value) && (is_int($child_key) || ctype_digit((string) $child_key))) {
            cf_tmrb_add_acf_reference_paths($source, $storage_key . '_' . (int) $child_key, $grandchild_value);
          }
        }

        if (!cf_tmrb_is_list_array($child_value)) {
          cf_tmrb_add_acf_reference_paths($source, $storage_key, $child_value);
        }
      }
    }
  }

  function cf_tmrb_acf_reference_source_from_payload($incoming) {
    if (!is_array($incoming)) return [];

    $reference_source = $incoming;
    foreach ($incoming as $raw_key => $value) {
      $norm_key = cf_tmrb_normalize_key($raw_key);
      if ($norm_key === '' || strpos($norm_key, '_') === 0 || strpos($norm_key, '.') !== false) continue;

      if ($norm_key !== $raw_key && !array_key_exists($norm_key, $reference_source)) {
        $reference_source[$norm_key] = $value;
      }

      if (is_array($value)) {
        foreach ($value as $index => $row) {
          if (!is_array($row) || !(is_int($index) || ctype_digit((string) $index))) continue;
          cf_tmrb_add_acf_reference_paths($reference_source, $norm_key . '_' . (int) $index, $row);
        }
      }
    }

    return $reference_source;
  }

  function cf_tmrb_post_meta_snapshot($post_id) {
    $existing = [];
    foreach (get_post_meta((int) $post_id) as $key => $values) {
      $existing[$key] = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
    }

    return $existing;
  }

  function cf_tmrb_sync_post_acf_clone_wrappers($post_id, $incoming, $existing, $context = []) {
    if (!is_array($incoming) || empty($incoming)) return;

    $post_id = (int) $post_id;
    $existing = is_array($existing) ? $existing : [];
    $reference_source = cf_tmrb_acf_reference_source_from_payload($incoming);

    foreach (cf_tmrb_acf_top_level_fields($post_id, $context) as $field) {
      if (!cf_tmrb_acf_clone_field_is_seamless($field) || empty($field['key']) || empty($field['name'])) {
        continue;
      }

      $matched = false;
      foreach (cf_tmrb_acf_cloned_fields($field) as $cloned_field) {
        $selectors = [];
        if (!empty($cloned_field['name'])) $selectors[] = (string) $cloned_field['name'];
        if (!empty($cloned_field['key'])) $selectors[] = (string) $cloned_field['key'];

        foreach (array_unique($selectors) as $selector) {
          if (array_key_exists($selector, $reference_source) || array_key_exists($selector, $existing)) {
            $matched = true;

            if (!empty($cloned_field['name']) && !empty($cloned_field['key'])) {
              update_post_meta($post_id, '_' . (string) $cloned_field['name'], (string) $cloned_field['key']);
            }
          }
        }
      }

      if (!$matched) continue;

      if (!array_key_exists((string) $field['name'], $existing) && !metadata_exists('post', $post_id, (string) $field['name'])) {
        update_post_meta($post_id, (string) $field['name'], '');
      }

      update_post_meta($post_id, '_' . (string) $field['name'], (string) $field['key']);
    }
  }

  function cf_tmrb_queue_post_acf_rest_resave($post_id, $acf_payload = [], $context = []) {
    static $queued = [];
    static $registered = false;
    static $running = false;

    $post_id = (int) $post_id;
    if ($post_id <= 0 || $running) return;

    if (!isset($queued[$post_id])) {
      $queued[$post_id] = [
        'acf'     => [],
        'context' => is_array($context) ? $context : [],
      ];
    }

    if (is_array($acf_payload)) {
      cf_tmrb_merge_acf_payload($queued[$post_id]['acf'], $acf_payload);
    }

    if (!$registered) {
      $registered = true;
      add_action('shutdown', function () use (&$queued, &$running) {
        if (empty($queued)) return;

        $pending = $queued;
        $queued = [];
        $running = true;

        try {
          foreach ($pending as $post_id => $entry) {
            $post_id = (int) $post_id;
            if ($post_id <= 0 || !get_post($post_id)) continue;

            $context = isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : cf_tmrb_acf_post_context($post_id);
            $payload = isset($entry['acf']) && is_array($entry['acf']) ? $entry['acf'] : [];

            if (!empty($payload)) {
              $prepared_admin_acf_keys = [];
              cf_tmrb_save_acf_payload_like_admin($post_id, $payload, $context, $prepared_admin_acf_keys);
              cf_tmrb_raw_store_acf_flexible_payload($post_id, $payload, $context);
              cf_tmrb_sync_post_acf_raw_references($post_id, $payload, cf_tmrb_post_meta_snapshot($post_id), $context);
            }

            if (function_exists('acf_flush_value_cache')) {
              acf_flush_value_cache($post_id);
            }
            clean_post_cache($post_id);
            wp_cache_delete($post_id, 'post_meta');

            wp_update_post(['ID' => $post_id]);

            if (function_exists('acf_flush_value_cache')) {
              acf_flush_value_cache($post_id);
            }
            clean_post_cache($post_id);
            wp_cache_delete($post_id, 'post_meta');
          }
        } finally {
          $running = false;
        }
      }, 20);
    }
  }

  function cf_tmrb_finalize_post_acf_rest_payload($post, $request, $force = false, $handler = null) {
    if (!($post instanceof WP_Post) || !($request instanceof WP_REST_Request)) return;

    $acf_payload = cf_tmrb_acf_payload_from_rest_request($request, $handler, (int) $post->ID);
    if (!is_array($acf_payload)) $acf_payload = [];
    if (empty($acf_payload) && !$force) return;

    static $finalizing = [];
    static $finalized = [];
    $post_id = (int) $post->ID;
    if (isset($finalizing[$post_id])) return;

    $request_token = $post_id . ':' . spl_object_hash($request);
    if (isset($finalized[$request_token])) return;
    $finalized[$request_token] = true;

    $finalizing[$post_id] = true;
    try {
      $acf_context = cf_tmrb_acf_post_context($post_id);
      if (!empty($acf_payload)) {
        cf_tmrb_sync_post_acf_raw_references($post_id, $acf_payload, cf_tmrb_post_meta_snapshot($post_id), $acf_context);
      }

      if (function_exists('acf_flush_value_cache')) {
        acf_flush_value_cache($post_id);
      }
      clean_post_cache($post_id);
      wp_cache_delete($post_id, 'post_meta');

      // A normal admin Update runs post-save hooks after ACF data exists.
      cf_tmrb_queue_post_acf_rest_resave($post_id, $acf_payload, $acf_context);
    } finally {
      unset($finalizing[$post_id]);
    }
  }
}

add_filter('rest_request_before_callbacks', function ($response, $handler, $request) {
  if (!($request instanceof WP_REST_Request)) return $response;
  if ($request->get_param('acf') !== null) return $response;
  if (!in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) return $response;

  $route = $request->get_route();
  if (!is_string($route) || strpos($route, '/wp/v2/') !== 0) return $response;

  // meta_all/meta_all_flat payloads are saved by the bridge. Do not promote
  // them into ACF's native REST writer; flexible-content rows can otherwise be
  // stored as full row arrays in the raw layout meta before the response loads.

  return $response;
}, 9, 3);

if (!function_exists('cf_tmrb_post_from_rest_response')) {
  function cf_tmrb_post_from_rest_response($response, $request) {
    if (!($request instanceof WP_REST_Request)) return null;

    $data = null;
    if ($response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response) {
      $data = $response->get_data();
    } elseif (is_array($response)) {
      $data = $response;
    }

    $post_id = 0;
    if (is_array($data) && isset($data['id'])) {
      $post_id = absint($data['id']);
    }

    if (!$post_id) {
      $route = $request->get_route();
      if (is_string($route) && preg_match('#/wp/v2/[^/]+/(\\d+)(?:/|$)#', $route, $matches)) {
        $post_id = absint($matches[1]);
      }
    }

    if (!$post_id) return null;

    $post = get_post($post_id);
    return $post instanceof WP_Post ? $post : null;
  }
}

add_filter('rest_request_after_callbacks', function ($response, $handler, $request) {
  if (!($request instanceof WP_REST_Request)) return $response;
  if (is_wp_error($response)) return $response;
  if (!in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) return $response;

  $route = $request->get_route();
  if (!is_string($route) || strpos($route, '/wp/v2/') !== 0) return $response;

  $has_bridge_payload = is_array($request->get_param('meta_all'))
    || is_array($request->get_param('meta_all_flat'))
    || is_array($request->get_param('acf'));

  $meta = $request->get_param('meta');
  if (!$has_bridge_payload && is_array($meta)) {
    $direct_meta = $meta;
    unset($direct_meta['meta_all'], $direct_meta['meta_all_flat']);

    $has_bridge_payload = (isset($meta['meta_all']) && is_array($meta['meta_all']))
      || (isset($meta['meta_all_flat']) && is_array($meta['meta_all_flat']));

    if (!$has_bridge_payload && !empty($direct_meta)) {
      $has_bridge_payload = is_array(cf_tmrb_extract_direct_acf_payload(
        $direct_meta,
        cf_tmrb_acf_schema_keys_from_rest_handler($handler)
      ));
    }
  }

  if (!$has_bridge_payload) return $response;

  $post = cf_tmrb_post_from_rest_response($response, $request);
  if (!($post instanceof WP_Post)) return $response;

  $applied_meta_payload = cf_tmrb_apply_meta_payload_updates($post, $request);
  cf_tmrb_finalize_post_acf_rest_payload($post, $request, $applied_meta_payload || is_array($request->get_param('acf')), $handler);

  return $response;
}, 20, 3);

if (!function_exists('cf_tmrb_should_expand_rest_meta_field')) {
  /**
   * Avoid expanding large meta payloads during mutation responses.
   *
   * Create, update, and delete responses serialize registered REST fields too.
   * Keep these helpers fully available on GET by default, but skip them on
   * non-GET requests unless a site explicitly opts back in.
   */
  function cf_tmrb_should_expand_rest_meta_field($request) {
    if (!($request instanceof WP_REST_Request)) {
      return true;
    }

    $method = strtoupper((string) $request->get_method());
    if (!in_array($method, ['GET', 'HEAD'], true)) {
      return (bool) apply_filters('cf_tmrb_expand_meta_all_on_non_get', false, $request);
    }

    $override = $request->get_param('include_meta_all');
    if ($override === null) {
      return true;
    }

    if (is_bool($override)) {
      return $override;
    }

    if (is_numeric($override)) {
      return ((int) $override) === 1;
    }

    if (is_string($override)) {
      return in_array(strtolower(trim($override)), ['1', 'true', 'yes', 'on'], true);
    }

    return !empty($override);
  }
}

add_action('rest_api_init', function () {

  $can_manage_terms = function($term_id = 0) {
    // We use generic manage_terms/manage_product_terms since term-specific caps vary by install.
    return current_user_can('manage_product_terms') || current_user_can('manage_terms');
  };

  $include_private_term = function($term_id = 0) use ($can_manage_terms) {
    /**
     * Allow sites to override via filter if needed.
     * Return true only for authenticated+authorized callers.
     */
    $allow = $can_manage_terms($term_id);
    return (bool) apply_filters('cf_tmrb_include_private_term_meta', $allow, $term_id);
  };

  /* ---------- meta_all: read/write with nested paths + ACF + legacy options ---------- */
  register_rest_field('product_cat', 'meta_all', [
    'get_callback' => function($term_arr, $field_name = null, $request = null) use ($include_private_term) {
      if (!cf_tmrb_should_expand_rest_meta_field($request)) {
        return [];
      }

      $term_id  = (int)$term_arr['id'];
      $taxonomy = isset($term_arr['taxonomy']) ? $term_arr['taxonomy'] : 'product_cat';
      $include_private = $include_private_term($term_id);

      // 1) core term meta
      $out = [];
      foreach (get_term_meta($term_id) as $key => $vals) {
        if (!$include_private && strpos($key, '_') === 0) continue;
        $val = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
        $out[$key] = $val;
      }

      // 2) ACF values
      if (function_exists('get_fields')) {
        $acf_vals = get_fields("{$taxonomy}_{$term_id}");
        if (is_array($acf_vals)) {
          foreach ($acf_vals as $name => $value) {
            if (!$include_private && strpos($name, '_') === 0) continue;
            if (!array_key_exists($name, $out)) $out[$name] = $value;
          }
        }
      }

      // 3) legacy options
      $legacy = cf_tmrb_load_legacy_options($taxonomy, $term_id);
      foreach ($legacy as $opt_name => $arr) {
        foreach ($arr as $k => $v) {
          if (!$include_private && strpos($k, '_') === 0) continue;
          if (!array_key_exists($k, $out)) $out[$k] = $v;
        }
      }

      // 4) Yoast taxonomy meta (stored in wpseo_taxonomy_meta option)
      $yoast = cf_tmrb_get_yoast_term_meta($taxonomy, $term_id);
      foreach ($yoast as $k => $v) {
        if (!$include_private && strpos($k, '_') === 0) continue;
        if (!array_key_exists($k, $out)) $out[$k] = $v;
      }
      if ($include_private) {
        if (isset($yoast['wpseo_title']) && !array_key_exists('_yoast_wpseo_title', $out)) {
          $out['_yoast_wpseo_title'] = $yoast['wpseo_title'];
        }
        if (isset($yoast['wpseo_desc']) && !array_key_exists('_yoast_wpseo_metadesc', $out)) {
          $out['_yoast_wpseo_metadesc'] = $yoast['wpseo_desc'];
        }
      }

      return $out;
    },

    'update_callback' => function($value, $term_obj) use ($can_manage_terms) {
      if (!($term_obj instanceof WP_Term)) return new WP_Error('rest_invalid','Invalid term object.',['status'=>400]);
      if (!$can_manage_terms($term_obj->term_id)) return new WP_Error('rest_forbidden','Insufficient permissions.',['status'=>403]);
      if (!is_array($value))   return new WP_Error('rest_invalid_param','meta_all must be an object.',['status'=>400]);
      $value = cf_tmrb_expand_nested_acf_meta_payload($value);

      $term_id  = (int)$term_obj->term_id;
      $taxonomy = $term_obj->taxonomy;
      $acf_post_id = "{$taxonomy}_{$term_id}";
      $acf_context = cf_tmrb_acf_term_context($taxonomy, $term_id);
      $acf_save_values = [];
      $acf_touched = false;

      $include_private = true; // caller is authorized at this point

      // Load current term meta (unserialized)
      $existing = [];
      foreach (get_term_meta($term_id) as $k => $vals) {
        $existing[$k] = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
      }

      // Load legacy options
      $legacy_opts = cf_tmrb_load_legacy_options($taxonomy, $term_id);

      $yoast_updates = [];

      foreach ($value as $k => $v) {
        if (!$include_private && strpos($k, '_') === 0) continue;
        $normKey = cf_tmrb_normalize_key($k);
        $san = cf_tmrb_sanitize_deep($v);
        $yk = cf_tmrb_map_yoast_term_key($normKey);
        if ($yk !== '') {
          $is_alias = (strpos($normKey, '_yoast_wpseo_') === 0) || ($normKey === 'wpseo_metadesc') || ($normKey === 'wpseo_metadescription');
          if ($is_alias && array_key_exists($yk, $yoast_updates)) {
            // Prefer canonical wpseo_* keys over aliases if both are provided
          } else {
            $yoast_updates[$yk] = $san;
          }
        }

        /** dotted/bracket path -> seed container in term meta (prefers ACF) */
        if (strpos($normKey, '.') !== false) {
          list($top, $rest) = explode('.', $normKey, 2);
          $container = (isset($existing[$top]) && is_array($existing[$top])) ? $existing[$top] : [];
          cf_tmrb_set_by_path($container, $rest, $san);

          if (!cf_tmrb_update_acf_field_if_available($top, $container, $acf_post_id, $acf_context, $acf_save_values, $acf_touched)) {
            update_term_meta($term_id, $top, $container);
          }
          $existing[$top] = $container;
          continue;
        }

        /* Case 1: plain key present in term meta */
        if (array_key_exists($normKey, $existing)) {
          $nextValue = (is_array($existing[$normKey]) && is_array($san))
            ? cf_tmrb_merge_meta_value($existing[$normKey], $san)
            : $san;
          if (!cf_tmrb_update_acf_field_if_available($normKey, $nextValue, $acf_post_id, $acf_context, $acf_save_values, $acf_touched)) {
            update_term_meta($term_id, $normKey, $nextValue);
          }
          $existing[$normKey] = $nextValue;
          continue;
        }

        /* Case 2: leaf-only auto-resolve in term meta nested arrays */
        $paths = [];
        foreach ($existing as $topKey => $val) {
          if (is_array($val)) {
            foreach (cf_tmrb_find_leaf_paths([$topKey => $val], $normKey) as $p) $paths[] = ['type'=>'term','top'=>$topKey,'path'=>$p];
          }
        }

        /* Case 3: leaf-only auto-resolve in legacy options arrays */
        foreach ($legacy_opts as $opt => $arr) {
          $flat = cf_tmrb_flatten($arr);
          foreach ($flat as $path => $_v) {
            $parts = explode('.', $path);
            if (end($parts) === $normKey) $paths[] = ['type'=>'opt','opt'=>$opt,'path'=>$path];
          }
          if (array_key_exists($normKey, $arr)) $paths[] = ['type'=>'opt','opt'=>$opt,'path'=>$normKey];
        }

        $uniq = [];
        foreach ($paths as $p) $uniq[ md5(serialize($p)) ] = $p;
        $paths = array_values($uniq);

        if (count($paths) === 1) {
          $one = $paths[0];
          if ($one['type'] === 'term') {
            list($top, $rest) = explode('.', $one['path'], 2);
            $container = isset($existing[$top]) && is_array($existing[$top]) ? $existing[$top] : [];
            cf_tmrb_set_by_path($container, $rest, $san);
            if (!cf_tmrb_update_acf_field_if_available($top, $container, $acf_post_id, $acf_context, $acf_save_values, $acf_touched)) {
              update_term_meta($term_id, $top, $container);
            }
            $existing[$top] = $container;
            continue;
          } else {
            $opt = $one['opt']; $arr = $legacy_opts[$opt];
            cf_tmrb_set_by_path($arr, $one['path'], $san);
            cf_tmrb_update_legacy_option($opt, $arr);
            $legacy_opts[$opt] = $arr;
            continue;
          }
        } elseif (count($paths) > 1) {
          $where = array_map(function($p){ return ($p['type']==='term'?'termmeta:':'option:').($p['type']==='term'?$p['path']:$p['opt'].'.'.$p['path']); }, $paths);
          return new WP_Error('rest_ambiguous',
            sprintf('Key "%s" found in multiple places (%s). Provide a dotted path.', $normKey, implode(', ', $where)),
            ['status' => 409]
          );
        }

        /** Guess container from other terms before falling back to top-level */
        $guess = cf_tmrb_guess_container_for_leaf($taxonomy, $normKey);
        if (is_array($guess) && isset($guess['type'])) {
          if ($guess['type'] === 'term' && !empty($guess['top'])) {
            $container = [];
            cf_tmrb_set_by_path($container, $normKey, $san);
            if (!cf_tmrb_update_acf_field_if_available($guess['top'], $container, $acf_post_id, $acf_context, $acf_save_values, $acf_touched)) {
              update_term_meta($term_id, $guess['top'], $container);
            }
            $existing[$guess['top']] = $container;
            continue;
          }
          if ($guess['type'] === 'opt' && !empty($guess['opt'])) {
            $arr = get_option($guess['opt']);
            if (!is_array($arr)) $arr = [];
            if (!empty($guess['top'])) {
              if (!isset($arr[$guess['top']]) || !is_array($arr[$guess['top']])) $arr[$guess['top']] = [];
              $arr[$guess['top']][$normKey] = $san;
            } else {
              $arr[$normKey] = $san;
            }
            update_option($guess['opt'], $arr);
            continue;
          }
        }

        /* Fallback: create as top-level term meta, unless this is a known ACF field. */
        $acf_field = cf_tmrb_update_acf_field_if_available($normKey, $san, $acf_post_id, $acf_context, $acf_save_values, $acf_touched);
        if (is_array($acf_field)) {
          $existing[isset($acf_field['name']) ? $acf_field['name'] : $normKey] = $san;
        } else {
          update_term_meta($term_id, $normKey, $san);
          $existing[$normKey] = $san;
        }
      }

      if (!empty($yoast_updates)) {
        $changed = cf_tmrb_update_yoast_term_meta($taxonomy, $term_id, $yoast_updates);
        if ($changed) cf_tmrb_touch_yoast_term_indexable($term_obj);
      }

      cf_tmrb_sync_term_acf_raw_references($term_id, $taxonomy, $value, $existing, $acf_context);
      return true;
    },

    'schema' => [
      'description' => 'All term meta (ACF + legacy). Public keys always; private keys only for authenticated callers with manage_terms.',
      'type' => 'object',
      'context' => ['view','edit'],
      'additionalProperties' => true,
    ],
  ]);

  /* ---------- meta_all_flat: flattened view ---------- */
  register_rest_field('product_cat', 'meta_all_flat', [
    'get_callback' => function($term_arr, $field_name = null, $request = null) use ($include_private_term) {
      if (!cf_tmrb_should_expand_rest_meta_field($request)) {
        return [];
      }

      $term_id  = (int)$term_arr['id'];
      $taxonomy = isset($term_arr['taxonomy']) ? $term_arr['taxonomy'] : 'product_cat';
      $include_private = $include_private_term($term_id);

      $flat = [];
      foreach (get_term_meta($term_id) as $key => $vals) {
        if (!$include_private && strpos($key, '_') === 0) continue;
        $val = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
        if (is_array($val)) $flat += cf_tmrb_flatten([$key => $val]);
        else $flat[$key] = $val;
      }
      if (function_exists('get_fields')) {
        $acf_vals = get_fields("{$taxonomy}_{$term_id}");
        if (is_array($acf_vals)) {
          foreach ($acf_vals as $name => $value) {
            if (!$include_private && strpos($name, '_') === 0) continue;
            if (is_array($value)) $flat += cf_tmrb_flatten([$name => $value]);
            else $flat[$name] = $value;
          }
        }
      }
      $legacy = cf_tmrb_load_legacy_options($taxonomy, $term_id);
      foreach ($legacy as $opt => $arr) {
        // legacy option arrays may include private-ish keys; we respect $include_private
        foreach (cf_tmrb_flatten($arr) as $k => $v) {
          $leaf = basename(str_replace('\\','/', $k));
          if (!$include_private && strpos($leaf, '_') === 0) continue;
          $flat[$k] = $v;
        }
      }

      $yoast = cf_tmrb_get_yoast_term_meta($taxonomy, $term_id);
      foreach ($yoast as $k => $v) {
        if (!$include_private && strpos($k, '_') === 0) continue;
        $flat[$k] = $v;
      }
      if ($include_private) {
        if (isset($yoast['wpseo_title']) && !array_key_exists('_yoast_wpseo_title', $flat)) {
          $flat['_yoast_wpseo_title'] = $yoast['wpseo_title'];
        }
        if (isset($yoast['wpseo_desc']) && !array_key_exists('_yoast_wpseo_metadesc', $flat)) {
          $flat['_yoast_wpseo_metadesc'] = $yoast['wpseo_desc'];
        }
      }
      return $flat;
    },
    'schema' => [
      'description' => 'Flattened view of term meta/ACF/legacy (dot-notation). Public only unless authenticated.',
      'type' => 'object',
      'context' => ['view','edit'],
      'additionalProperties' => true,
    ],
  ]);

  /* Discovery endpoint (kept; still excludes private keys in SQL) */
  register_rest_route('cf-bridge/v1', '/product-cat/meta-keys', [
    'methods'  => 'GET',
    'permission_callback' => $can_manage_terms,
    'callback' => function(WP_REST_Request $req) {
      global $wpdb;
      // phpcs:disable WordPress.DB.DirectDatabaseQuery
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT tm.meta_key, COUNT(*) AS uses
        FROM {$wpdb->termmeta} tm
        JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
        WHERE tt.taxonomy = %s
          AND tm.meta_key NOT LIKE %s
        GROUP BY tm.meta_key
        ORDER BY uses DESC
      ", 'product_cat', $wpdb->esc_like('_') . '%'), ARRAY_A);
      // phpcs:enable WordPress.DB.DirectDatabaseQuery
      return $rows;
    },
  ]);

});

add_action('created_product_cat', function() { flush_rewrite_rules(); });

/** ===========================================================
 *  POSTS: meta_all & meta_all_flat + Genesis fallback (conditional private)
 *  =========================================================== */

/**
 * Which post types to support. Filterable.
 * Example to add pages:
 * add_filter('cf_tmrb_supported_post_types', function($pts){ $pts[] = 'page'; return $pts; });
 */
if (!function_exists('cf_tmrb_supported_post_types')) {
  function cf_tmrb_supported_post_types() {
    $default = ['post','page'];
    return apply_filters('cf_tmrb_supported_post_types', $default);
  }
}

/** Expose some commonly-used Genesis keys via REST (optional & harmless) */
add_action('init', function () {
  foreach (cf_tmrb_supported_post_types() as $pt) {
    register_post_meta($pt, '_genesis_title', [
      'single'        => true,
      'type'          => 'string',
      'show_in_rest'  => true,
      'auth_callback' => fn($allowed,$meta_key,$post_id) => current_user_can('edit_post', $post_id),
    ]);
    register_post_meta($pt, '_genesis_description', [
      'single'        => true,
      'type'          => 'string',
      'show_in_rest'  => true,
      'auth_callback' => fn($allowed,$meta_key,$post_id) => current_user_can('edit_post', $post_id),
    ]);
  }
});

if (!function_exists('cf_tmrb_get_post_meta_all_payload')) {
  function cf_tmrb_get_post_meta_all_payload($post_id, $include_private = false) {
    $post_id = (int) $post_id;
    $include_private = $include_private && current_user_can('edit_post', $post_id);
    $out = [];

    foreach (get_post_meta($post_id) as $key => $vals) {
      // Reserved virtual builder namespace; never expose or persist it as ordinary post meta.
      if ($key === 'wpbakery') continue;
      if (!$include_private && strpos($key, '_') === 0) continue;
      $out[$key] = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
    }

    if (function_exists('get_fields')) {
      $acf_vals = get_fields($post_id);
      if (is_array($acf_vals)) {
        foreach ($acf_vals as $name => $value) {
          if ($name === 'wpbakery') continue;
          if (!$include_private && strpos($name, '_') === 0) continue;
          if (!array_key_exists($name, $out)) $out[$name] = $value;
        }
      }
    }

    $out = cf_tmrb_enrich_post_meta_with_seo_aliases($out, $include_private);

    $post = $include_private ? get_post($post_id) : null;
    if (
      $post instanceof WP_Post
      && function_exists('nova_wpb_has_wpbakery_layout')
      && function_exists('nova_wpb_build_meta_all_payload')
      && nova_wpb_has_wpbakery_layout($post)
    ) {
      $out['wpbakery'] = nova_wpb_build_meta_all_payload($post);
    }

    return $out;
  }
}

if (!function_exists('cf_tmrb_update_post_meta_all_payload')) {
  function cf_tmrb_update_post_meta_all_payload($value, $post_obj) {
    if (!($post_obj instanceof WP_Post)) return new WP_Error('rest_invalid','Invalid post object.',['status'=>400]);
    $post_id = (int) $post_obj->ID;
    if (!current_user_can('edit_post', $post_id)) return new WP_Error('rest_forbidden','Insufficient permissions.',['status'=>403]);
    if (!is_array($value)) return new WP_Error('rest_invalid_param','meta_all must be an object.',['status'=>400]);

    if (array_key_exists('wpbakery', $value)) {
      $wpbakery = $value['wpbakery'];
      unset($value['wpbakery']);

      if (!function_exists('nova_wpb_apply_meta_all_payload')) {
        return new WP_Error('missing_dependency', 'WPBakery bridge not loaded.', ['status'=>500]);
      }
      $wpbakery_result = nova_wpb_apply_meta_all_payload($wpbakery, $post_obj);
      if (is_wp_error($wpbakery_result)) return $wpbakery_result;
    }

    $grouped_acf_input = cf_tmrb_extract_grouped_acf_payload($value);
    $explicit_meta_keys = [];
    foreach ($value as $explicit_key => $_explicit_value) {
      if ((string) $explicit_key === 'acf') continue;
      $explicit_meta_keys[cf_tmrb_normalize_key($explicit_key)] = true;
    }

    $value = cf_tmrb_expand_nested_acf_meta_payload($value);

    $acf_context = cf_tmrb_acf_post_context($post_id);
    $acf_save_values = [];
    $acf_touched = false;
    $admin_saved_acf_keys = [];

    $flat_acf_consumed_keys = [];
    $flat_acf_input = cf_tmrb_acf_payload_from_flat_meta($value, $post_id, $acf_context, $flat_acf_consumed_keys);
    if (!empty($flat_acf_input)) {
      if (!is_array($grouped_acf_input)) $grouped_acf_input = [];

      foreach ($flat_acf_input as $acf_key => $acf_value) {
        if (!array_key_exists($acf_key, $grouped_acf_input)) {
          $grouped_acf_input[$acf_key] = $acf_value;
        }
      }
    }

    if (is_array($grouped_acf_input) && !empty($grouped_acf_input)) {
      foreach ($grouped_acf_input as $acf_key => $_acf_value) {
        $admin_saved_acf_keys[cf_tmrb_normalize_key($acf_key)] = true;
      }

      $prepared_admin_acf_keys = [];
      if (cf_tmrb_save_acf_payload_like_admin($post_id, $grouped_acf_input, $acf_context, $prepared_admin_acf_keys)) {
        foreach ($prepared_admin_acf_keys as $acf_key) {
          $admin_saved_acf_keys[cf_tmrb_normalize_key($acf_key)] = true;
        }
      }

      foreach (cf_tmrb_raw_store_acf_flexible_payload($post_id, $grouped_acf_input, $acf_context) as $acf_key) {
        $admin_saved_acf_keys[cf_tmrb_normalize_key($acf_key)] = true;
      }

      cf_tmrb_sync_post_acf_raw_references($post_id, $grouped_acf_input, cf_tmrb_post_meta_snapshot($post_id), $acf_context);
    }

    // Caller is authorized -> allow private and public keys alike.
    $existing = [];
    foreach (get_post_meta($post_id) as $k => $vals) {
      $existing[$k] = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
    }

    $aliases = (array) apply_filters('cf_tmrb_post_key_aliases', array(
      'autodescription-term-settings.' => 'autodescription-meta.',
      'autodescription-term-settings'  => 'autodescription-meta',
    ));

    $normalized_input = [];
    foreach ($value as $k => $v) {
      $nk = cf_tmrb_normalize_key($k);
      foreach ($aliases as $from => $to) {
        if ($nk === $from) { $nk = $to; break; }
        if (strpos($nk, $from) === 0) { $nk = $to . substr($nk, strlen($from)); break; }
      }
      if (isset($flat_acf_consumed_keys[$nk]) || (isset($admin_saved_acf_keys[$nk]) && !isset($explicit_meta_keys[$nk]))) {
        continue;
      }
      $normalized_input[$nk] = $v;
    }

    // Normalize incoming SEO fields to the active SEO plugin's storage keys.
    $seo_resolution = cf_tmrb_resolve_post_seo_from_meta_input($normalized_input);
    foreach ((array) ($seo_resolution['consumed_keys'] ?? []) as $consumed_key) {
      unset($normalized_input[$consumed_key]);
    }
    foreach ((array) ($seo_resolution['updates'] ?? []) as $seo_key => $seo_value) {
      $normalized_input[$seo_key] = $seo_value;
    }
    $seo_resolved_keys = array_keys((array) ($seo_resolution['updates'] ?? []));

    foreach ($normalized_input as $k => $v) {
      $normKey = cf_tmrb_normalize_key($k);
      $san     = ($normKey === 'aux_metafields_custom_styles' && is_string($v))
        ? sanitize_textarea_field($v)
        : cf_tmrb_sanitize_deep($v);

      // Dotted/bracket path -> container write
      if (strpos($normKey, '.') !== false) {
        list($top, $rest) = explode('.', $normKey, 2);
        $container = (isset($existing[$top]) && is_array($existing[$top])) ? $existing[$top] : [];
        cf_tmrb_set_by_path($container, $rest, $san);

        if (!cf_tmrb_update_acf_field_if_available($top, $container, $post_id, $acf_context, $acf_save_values, $acf_touched)) {
          update_post_meta($post_id, $top, $container);
        }
        $existing[$top] = $container;

        cf_tmrb_apply_genesis_fallback($post_id, $normKey, $san);
        continue;
      }

      // Plain key present -> update in place
      if (array_key_exists($normKey, $existing)) {
        $nextValue = (is_array($existing[$normKey]) && is_array($san))
          ? cf_tmrb_merge_meta_value($existing[$normKey], $san)
          : $san;
        if (!cf_tmrb_update_acf_field_if_available($normKey, $nextValue, $post_id, $acf_context, $acf_save_values, $acf_touched)) {
          update_post_meta($post_id, $normKey, $nextValue);
        }
        cf_tmrb_apply_genesis_fallback($post_id, $normKey, $nextValue);
        $existing[$normKey] = $nextValue;
        continue;
      }

      // SEO-resolved keys are always top-level post meta — skip guess/leaf resolution
      if (in_array($normKey, $seo_resolved_keys, true)) {
        update_post_meta($post_id, $normKey, $san);
        $existing[$normKey] = $san;
        continue;
      }

      // Leaf-only auto-resolve
      $paths = [];
      foreach ($existing as $topKey => $val) {
        if (is_array($val)) {
          foreach (cf_tmrb_find_leaf_paths([$topKey => $val], $normKey) as $p) $paths[] = $p;
        }
      }
      $paths = array_values(array_unique($paths));

      if (count($paths) === 1) {
        list($top, $rest) = explode('.', $paths[0], 2);
        $container = isset($existing[$top]) && is_array($existing[$top]) ? $existing[$top] : [];
        cf_tmrb_set_by_path($container, $rest, $san);

        if (!cf_tmrb_update_acf_field_if_available($top, $container, $post_id, $acf_context, $acf_save_values, $acf_touched)) {
          update_post_meta($post_id, $top, $container);
        }
        $existing[$top] = $container;

        cf_tmrb_apply_genesis_fallback($post_id, $paths[0], $san);
        continue;
      } elseif (count($paths) > 1) {
        return new WP_Error(
          'rest_ambiguous',
          sprintf('Key "%s" found in multiple places (%s). Provide a dotted path.',
            $normKey, implode(', ', $paths)),
          ['status' => 409]
        );
      }

      // Guess container from other posts
      $guess = cf_tmrb_guess_post_container_for_leaf($post_obj->post_type, $normKey);
      if (is_array($guess) && isset($guess['type']) && $guess['type'] === 'post' && !empty($guess['top'])) {
        $container = [];
        cf_tmrb_set_by_path($container, $normKey, $san);
        if (!cf_tmrb_update_acf_field_if_available($guess['top'], $container, $post_id, $acf_context, $acf_save_values, $acf_touched)) {
          update_post_meta($post_id, $guess['top'], $container);
        }
        $existing[$guess['top']] = $container;

        cf_tmrb_apply_genesis_fallback($post_id, $normKey, $san);
        continue;
      }

      // Fallback: create as top-level post meta, unless this is a known ACF field.
      $acf_field = cf_tmrb_update_acf_field_if_available($normKey, $san, $post_id, $acf_context, $acf_save_values, $acf_touched);
      if (is_array($acf_field)) {
        $existing[isset($acf_field['name']) ? $acf_field['name'] : $normKey] = $san;
      } else {
        update_post_meta($post_id, $normKey, $san);
        $existing[$normKey] = $san;
      }
      cf_tmrb_apply_genesis_fallback($post_id, $normKey, $san);
    }

    $acf_refs_updated = cf_tmrb_sync_post_acf_raw_references($post_id, $normalized_input, $existing, $acf_context);
    $acf_resave_payload = is_array($grouped_acf_input) ? $grouped_acf_input : [];
    if ($acf_touched || $acf_refs_updated || !empty($acf_resave_payload)) {
      cf_tmrb_queue_post_acf_rest_resave($post_id, $acf_resave_payload, $acf_context);
    }

    return true;
  }
}

if (!function_exists('cf_tmrb_update_post_acf_payload')) {
  function cf_tmrb_update_post_acf_payload($value, $post_obj) {
    if (!($post_obj instanceof WP_Post)) return new WP_Error('rest_invalid','Invalid post object.',['status'=>400]);
    $post_id = (int) $post_obj->ID;
    if (!current_user_can('edit_post', $post_id)) return new WP_Error('rest_forbidden','Insufficient permissions.',['status'=>403]);
    if (!is_array($value)) return new WP_Error('rest_invalid_param','acf must be an object.',['status'=>400]);

    $acf_context = cf_tmrb_acf_post_context($post_id);
    $acf_save_values = [];
    $acf_touched = false;
    $admin_saved_acf_keys = [];

    $prepared_admin_acf_keys = [];
    if (cf_tmrb_save_acf_payload_like_admin($post_id, $value, $acf_context, $prepared_admin_acf_keys)) {
      foreach ($prepared_admin_acf_keys as $acf_key) {
        $admin_saved_acf_keys[cf_tmrb_normalize_key($acf_key)] = true;
      }
    }

    foreach (cf_tmrb_raw_store_acf_flexible_payload($post_id, $value, $acf_context) as $acf_key) {
      $admin_saved_acf_keys[cf_tmrb_normalize_key($acf_key)] = true;
    }

    foreach ($value as $selector => $field_value) {
      $selector = cf_tmrb_normalize_key($selector);
      if (isset($admin_saved_acf_keys[$selector])) continue;

      $san = cf_tmrb_sanitize_deep($field_value);
      cf_tmrb_update_acf_field_if_available($selector, $san, $post_id, $acf_context, $acf_save_values, $acf_touched);
    }

    cf_tmrb_sync_post_acf_raw_references($post_id, $value, cf_tmrb_post_meta_snapshot($post_id), $acf_context);

    return true;
  }
}

if (!function_exists('cf_tmrb_apply_meta_payload_updates')) {
  function cf_tmrb_apply_meta_payload_updates($post_obj, $request) {
    if (!($post_obj instanceof WP_Post) || !($request instanceof WP_REST_Request)) return;

    static $applied_requests = [];
    $request_token = (int) $post_obj->ID . ':' . spl_object_hash($request);
    if (isset($applied_requests[$request_token])) return false;
    $applied_requests[$request_token] = true;

    $applied = false;

    $acf = $request->get_param('acf');
    if (is_array($acf)) {
      cf_tmrb_update_post_acf_payload($acf, $post_obj);
      $applied = true;
    }

    $meta_all = $request->get_param('meta_all');
    if (is_array($meta_all)) {
      cf_tmrb_update_post_meta_all_payload($meta_all, $post_obj);
      $applied = true;
    }

    $meta_all_flat = $request->get_param('meta_all_flat');
    if (is_array($meta_all_flat)) {
      cf_tmrb_update_post_meta_all_payload($meta_all_flat, $post_obj);
      $applied = true;
    }

    $meta = $request->get_param('meta');
    if (!is_array($meta)) return $applied;

    $direct_meta = $meta;
    unset($direct_meta['meta_all'], $direct_meta['meta_all_flat']);
    if (!empty($direct_meta)) {
      $acf_context = cf_tmrb_acf_post_context((int) $post_obj->ID);
      $direct_acf_payload = cf_tmrb_acf_payload_from_flat_meta($direct_meta, (int) $post_obj->ID, $acf_context);
      if (!empty($direct_acf_payload)) {
        $prepared_admin_acf_keys = [];
        cf_tmrb_save_acf_payload_like_admin((int) $post_obj->ID, $direct_acf_payload, $acf_context, $prepared_admin_acf_keys);
        cf_tmrb_raw_store_acf_flexible_payload((int) $post_obj->ID, $direct_acf_payload, $acf_context);
      }

      $acf_refs_updated = cf_tmrb_sync_post_acf_raw_references((int) $post_obj->ID, !empty($direct_acf_payload) ? $direct_acf_payload : $direct_meta, cf_tmrb_post_meta_snapshot((int) $post_obj->ID), $acf_context);
      if (!empty($direct_acf_payload) || $acf_refs_updated) {
        cf_tmrb_queue_post_acf_rest_resave((int) $post_obj->ID, $direct_acf_payload, $acf_context);
        $applied = true;
      }
    }

    if (isset($meta['meta_all']) && is_array($meta['meta_all'])) {
      cf_tmrb_update_post_meta_all_payload($meta['meta_all'], $post_obj);
      $applied = true;
    }

    if (isset($meta['meta_all_flat']) && is_array($meta['meta_all_flat'])) {
      cf_tmrb_update_post_meta_all_payload($meta['meta_all_flat'], $post_obj);
      $applied = true;
    }

    return $applied;
  }
}

if (!function_exists('cf_tmrb_apply_nested_meta_payload_updates')) {
  function cf_tmrb_apply_nested_meta_payload_updates($post_obj, $request) {
    cf_tmrb_apply_meta_payload_updates($post_obj, $request);
  }
}

add_action('rest_api_init', function () {

  $pts = cf_tmrb_supported_post_types();

  $include_private_post = function($post_id = 0) {
    $allow = current_user_can('edit_post', $post_id);
    return (bool) apply_filters('cf_tmrb_include_private_post_meta', $allow, $post_id);
  };

  /* ---------- meta_all for posts ---------- */
  register_rest_field($pts, 'meta_all', [
    'get_callback' => function ($post_arr, $field_name = null, $request = null) use ($include_private_post) {
      if (!cf_tmrb_should_expand_rest_meta_field($request)) {
        return [];
      }

      $post_id = (int) $post_arr['id'];
      $include_private = $include_private_post($post_id);
      return cf_tmrb_get_post_meta_all_payload($post_id, $include_private);
    },

    'update_callback' => 'cf_tmrb_update_post_meta_all_payload',

    'schema' => [
      'description' => 'All post meta (ACF-aware). Public keys always; private keys only for authenticated callers with edit_post.',
      'type' => 'object',
      'context' => ['view','edit'],
      'additionalProperties' => true,
    ],
  ]);

  /* ---------- meta_all_flat for posts ---------- */
  register_rest_field($pts, 'meta_all_flat', [
    'get_callback' => function ($post_arr, $field_name = null, $request = null) use ($include_private_post) {
      if (!cf_tmrb_should_expand_rest_meta_field($request)) {
        return [];
      }

      $post_id = (int) $post_arr['id'];
      $include_private = $include_private_post($post_id);

      $flat = [];
      foreach (get_post_meta($post_id) as $key => $vals) {
        if ($key === 'wpbakery') continue;
        if (!$include_private && strpos($key, '_') === 0) continue;
        $val = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
        if (is_array($val)) $flat += cf_tmrb_flatten([$key => $val]);
        else $flat[$key] = $val;
      }
      if (function_exists('get_fields')) {
        $acf_vals = get_fields($post_id);
        if (is_array($acf_vals)) {
          foreach ($acf_vals as $name => $value) {
            if (!$include_private && strpos($name, '_') === 0) continue;
            if (is_array($value)) $flat += cf_tmrb_flatten([$name => $value]);
            else $flat[$name] = $value;
          }
        }
      }

      $flat = cf_tmrb_enrich_post_meta_with_seo_aliases($flat, $include_private);
      return $flat;
    },
    'update_callback' => 'cf_tmrb_update_post_meta_all_payload',
    'schema' => [
      'description' => 'Flattened view of post meta (ACF-aware). Public only unless authenticated.',
      'type' => 'object',
      'context' => ['view','edit'],
      'additionalProperties' => true,
    ],
  ]);

  foreach ($pts as $pt) {
    add_action("rest_after_insert_{$pt}", function($post, $request, $creating) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
      $applied_meta_payload = cf_tmrb_apply_meta_payload_updates($post, $request);
      cf_tmrb_finalize_post_acf_rest_payload($post, $request, $applied_meta_payload);
    }, 15, 3);
  }

  /* Optional discovery endpoint for post meta keys (kept, excludes private in SQL) */
  register_rest_route('cf-bridge/v1', '/post/meta-keys', [
    'methods'  => 'GET',
    'permission_callback' => function(){ return current_user_can('edit_posts'); },
    'callback' => function(WP_REST_Request $req) {
      global $wpdb;
      $post_type = $req->get_param('post_type') ?: 'post';
      // phpcs:disable WordPress.DB.DirectDatabaseQuery
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT pm.meta_key, COUNT(*) AS uses
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = %s
          AND pm.meta_key NOT LIKE %s
        GROUP BY pm.meta_key
        ORDER BY uses DESC
      ", $post_type, $wpdb->esc_like('_') . '%'), ARRAY_A);
      // phpcs:enable WordPress.DB.DirectDatabaseQuery
      return $rows;
    },
  ]);

});

/* End of file */



/**
 * Enable `meta_all` for all REST-enabled post types (including all CPTs).
 * This extends the plugin's default ['post','page'] list without hardcoding per site.
 */
add_filter('cf_tmrb_supported_post_types', function ($pts) {
  $all = get_post_types([ 'show_in_rest' => true ], 'names');
  $exclude = [
    'attachment','revision','nav_menu_item','custom_css','customize_changeset',
    'oembed_cache','user_request','wp_block','wp_template','wp_template_part',
    'wp_navigation','wp_font_family','wp_font_face','wp_global_styles','wp_pattern','wp_theme'
  ];
  $all = array_diff($all, $exclude);
  return array_values(array_unique(array_merge((array)$pts, (array)$all)));
});
