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

/** ===========================================================
 *  product_cat: meta_all & meta_all_flat  (conditional private)
 *  =========================================================== */
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
    'get_callback' => function($term_arr) use ($include_private_term) {
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

      $term_id  = (int)$term_obj->term_id;
      $taxonomy = $term_obj->taxonomy;

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

          if (function_exists('get_field_object')) {
            $fo = get_field_object($top, "{$taxonomy}_{$term_id}");
            if (is_array($fo) && isset($fo['key'])) {
              update_field($fo['key'], $container, "{$taxonomy}_{$term_id}");
            } else {
              update_term_meta($term_id, $top, $container);
            }
          } else {
            update_term_meta($term_id, $top, $container);
          }
          $existing[$top] = $container;
          continue;
        }

        /* Case 1: plain key present in term meta */
        if (array_key_exists($normKey, $existing)) {
          if (function_exists('get_field_object')) {
            $fo = get_field_object($normKey, "{$taxonomy}_{$term_id}");
            if (is_array($fo) && isset($fo['key'])) { update_field($fo['key'], $san, "{$taxonomy}_{$term_id}"); }
            else { update_term_meta($term_id, $normKey, $san); }
          } else {
            update_term_meta($term_id, $normKey, $san);
          }
          $existing[$normKey] = $san;
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
            if (function_exists('get_field_object')) {
              $fo = get_field_object($top, "{$taxonomy}_{$term_id}");
              if (is_array($fo) && isset($fo['key'])) { update_field($fo['key'], $container, "{$taxonomy}_{$term_id}"); }
              else { update_term_meta($term_id, $top, $container); }
            } else {
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
            if (function_exists('get_field_object')) {
              $fo = get_field_object($guess['top'], "{$taxonomy}_{$term_id}");
              if (is_array($fo) && isset($fo['key'])) {
                update_field($fo['key'], $container, "{$taxonomy}_{$term_id}");
              } else {
                update_term_meta($term_id, $guess['top'], $container);
              }
            } else {
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

        /* Fallback: create as top-level term meta */
        update_term_meta($term_id, $normKey, $san);
        $existing[$normKey] = $san;
      }

      if (!empty($yoast_updates)) {
        $changed = cf_tmrb_update_yoast_term_meta($taxonomy, $term_id, $yoast_updates);
        if ($changed) cf_tmrb_touch_yoast_term_indexable($term_obj);
      }
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
    'get_callback' => function($term_arr) use ($include_private_term) {
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

add_action('rest_api_init', function () {

  $pts = cf_tmrb_supported_post_types();

  $include_private_post = function($post_id = 0) {
    $allow = current_user_can('edit_post', $post_id);
    return (bool) apply_filters('cf_tmrb_include_private_post_meta', $allow, $post_id);
  };

  /* ---------- meta_all for posts ---------- */
  register_rest_field($pts, 'meta_all', [
    'get_callback' => function ($post_arr) use ($include_private_post) {
      $post_id = (int) $post_arr['id'];
      $include_private = $include_private_post($post_id);

      // 1) core post meta
      $out = [];
      foreach (get_post_meta($post_id) as $key => $vals) {
        if (!$include_private && strpos($key, '_') === 0) continue;
        $val = count($vals) === 1 ? maybe_unserialize($vals[0]) : array_map('maybe_unserialize', $vals);
        $out[$key] = $val;
      }

      // 2) merge ACF values
      if (function_exists('get_fields')) {
        $acf_vals = get_fields($post_id);
        if (is_array($acf_vals)) {
          foreach ($acf_vals as $name => $value) {
            if (!$include_private && strpos($name, '_') === 0) continue;
            if (!array_key_exists($name, $out)) $out[$name] = $value;
          }
        }
      }

      return $out;
    },

    'update_callback' => function ($value, $post_obj) {
      if (!($post_obj instanceof WP_Post)) return new WP_Error('rest_invalid','Invalid post object.',['status'=>400]);
      $post_id = (int) $post_obj->ID;
      if (!current_user_can('edit_post', $post_id)) return new WP_Error('rest_forbidden','Insufficient permissions.',['status'=>403]);
      if (!is_array($value)) return new WP_Error('rest_invalid_param','meta_all must be an object.',['status'=>400]);

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
        $normalized_input[$nk] = $v;
      }

      foreach ($normalized_input as $k => $v) {
        $normKey = cf_tmrb_normalize_key($k);
        $san     = cf_tmrb_sanitize_deep($v);

        // Dotted/bracket path -> container write
        if (strpos($normKey, '.') !== false) {
          list($top, $rest) = explode('.', $normKey, 2);
          $container = (isset($existing[$top]) && is_array($existing[$top])) ? $existing[$top] : [];
          cf_tmrb_set_by_path($container, $rest, $san);

          if (function_exists('get_field_object')) {
            $fo = get_field_object($top, $post_id);
            if (is_array($fo) && isset($fo['key'])) { update_field($fo['key'], $container, $post_id); }
            else { update_post_meta($post_id, $top, $container); }
          } else {
            update_post_meta($post_id, $top, $container);
          }
          $existing[$top] = $container;

          $leafSegs = array_values(array_filter(explode('.', $rest), 'strlen'));
          $lastLeaf = end($leafSegs);
          if ($lastLeaf !== false) cf_tmrb_apply_genesis_fallback($post_id, $lastLeaf, $san);
          continue;
        }

        // Plain key present -> update in place
        if (array_key_exists($normKey, $existing)) {
          if (function_exists('get_field_object')) {
            $fo = get_field_object($normKey, $post_id);
            if (is_array($fo) && isset($fo['key'])) { update_field($fo['key'], $san, $post_id); }
            else { update_post_meta($post_id, $normKey, $san); }
          } else {
            update_post_meta($post_id, $normKey, $san);
          }
          cf_tmrb_apply_genesis_fallback($post_id, $normKey, $san);
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

          if (function_exists('get_field_object')) {
            $fo = get_field_object($top, $post_id);
            if (is_array($fo) && isset($fo['key'])) { update_field($fo['key'], $container, $post_id); }
            else { update_post_meta($post_id, $top, $container); }
          } else {
            update_post_meta($post_id, $top, $container);
          }
          $existing[$top] = $container;

          $leafSegs = array_values(array_filter(explode('.', $rest), 'strlen'));
          $lastLeaf = end($leafSegs);
          if ($lastLeaf !== false) cf_tmrb_apply_genesis_fallback($post_id, $lastLeaf, $san);
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
          if (function_exists('get_field_object')) {
            $fo = get_field_object($guess['top'], $post_id);
            if (is_array($fo) && isset($fo['key'])) { update_field($fo['key'], $container, $post_id); }
            else { update_post_meta($post_id, $guess['top'], $container); }
          } else {
            update_post_meta($post_id, $guess['top'], $container);
          }
          $existing[$guess['top']] = $container;

          cf_tmrb_apply_genesis_fallback($post_id, $normKey, $san);
          continue;
        }

        // Fallback: create as top-level post meta
        update_post_meta($post_id, $normKey, $san);
        cf_tmrb_apply_genesis_fallback($post_id, $normKey, $san);
        $existing[$normKey] = $san;
      }

      return true;
    },

    'schema' => [
      'description' => 'All post meta (ACF-aware). Public keys always; private keys only for authenticated callers with edit_post.',
      'type' => 'object',
      'context' => ['view','edit'],
      'additionalProperties' => true,
    ],
  ]);

  /* ---------- meta_all_flat for posts ---------- */
  register_rest_field($pts, 'meta_all_flat', [
    'get_callback' => function ($post_arr) use ($include_private_post) {
      $post_id = (int) $post_arr['id'];
      $include_private = $include_private_post($post_id);

      $flat = [];
      foreach (get_post_meta($post_id) as $key => $vals) {
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

      return $flat;
    },
    'schema' => [
      'description' => 'Flattened view of post meta (ACF-aware). Public only unless authenticated.',
      'type' => 'object',
      'context' => ['view','edit'],
      'additionalProperties' => true,
    ],
  ]);

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
