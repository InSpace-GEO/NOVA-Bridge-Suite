<?php
/**
 * NOVA Bridge Suite module: Avada bridge.
 */
declare(strict_types=1);

namespace NovaAvadaBridge;

use function add_action;

defined('ABSPATH') || exit;

define('NOVA_BRIDGE_SUITE_AVADA_VERSION', '1.0.0');
define('NOVA_BRIDGE_SUITE_AVADA_PATH', __DIR__);

require_once NOVA_BRIDGE_SUITE_AVADA_PATH . '/includes/class-nab-layout-transformer.php';
require_once NOVA_BRIDGE_SUITE_AVADA_PATH . '/includes/class-nab-page-service.php';
require_once NOVA_BRIDGE_SUITE_AVADA_PATH . '/includes/class-nab-rest-controller.php';

final class Plugin {
    private static ?Plugin $instance = null;

    private Layout_Transformer $transformer;
    private Page_Service $page_service;
    private REST_Controller $rest_controller;

    private function __construct() {
        $this->transformer    = new Layout_Transformer();
        $this->page_service   = new Page_Service($this->transformer);
        $this->rest_controller = new REST_Controller($this->page_service, $this->transformer);

        add_action('rest_api_init', [$this->rest_controller, 'register_routes']);
    }

    public static function init(): Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}

add_action('plugins_loaded', [Plugin::class, 'init']);
