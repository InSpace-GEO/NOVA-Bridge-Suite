<?php
/** Compatibility name for retained NOVA CPT image operations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-media-importer.php';
class_alias( Nova_Bridge_Suite_Media_Importer::class, 'Nova_Bridge_Suite_Owned_CPT_Media' );
