<?php
/** The optional mapped connector owns its recurrence without restoring delivery cron. */
define( 'ABSPATH', __DIR__ . '/' );
$checks = 0; $actions = []; $filters = []; $options = []; $events = [ 'unrelated_hook' => [ 'timestamp' => 1 ] ]; $scheduled = 0;
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function add_action( $hook, $callback, ...$args ) { $GLOBALS['actions'][$hook][] = $callback; }
function add_filter( $hook, $callback, ...$args ) { $GLOBALS['filters'][$hook][] = $callback; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function wp_next_scheduled( $hook ) { return $GLOBALS['events'][$hook]['timestamp'] ?? false; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['events'][$hook] ); }
function wp_get_schedules() {
    $schedules = [ 'hourly' => [ 'interval' => 3600, 'display' => 'Hourly' ] ];
    foreach ( $GLOBALS['filters']['cron_schedules'] ?? [] as $callback ) { $schedules = call_user_func( $callback, $schedules ); }
    return $schedules;
}
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
    $schedules = wp_get_schedules();
    if ( ! isset( $schedules[$recurrence] ) ) { return false; }
    ++$GLOBALS['scheduled'];
    $GLOBALS['events'][$hook] = [ 'timestamp' => $timestamp, 'recurrence' => $recurrence, 'interval' => $schedules[$recurrence]['interval'] ];
    return true;
}
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-connector.php';
Nova_Bridge_Suite_Mapped_Content_Connector::bootstrap();
$hook = Nova_Bridge_Suite_Mapped_Content_Connector::CRON_HOOK;
check( isset( $filters['cron_schedules'], $actions[$hook] ) && ! isset( $actions['nova_bridge_posting_drain'] ), 'Only the separate mapped connector registers its poll action and recurrence.' );
check( 60 === wp_get_schedules()['nova_posting_minute']['interval'] && 3600 === wp_get_schedules()['hourly']['interval'], 'Existing minute recurrence remains available without altering unrelated intervals.' );
Nova_Bridge_Suite_Mapped_Content_Connector::schedule();
check( 0 === $scheduled, 'Disabled default does not activate polling.' );
$options[Nova_Bridge_Suite_Mapped_Content_Connector::OPTION] = [ 'connection_source' => 'separate', 'enabled' => true, 'automatic' => false ];
Nova_Bridge_Suite_Mapped_Content_Connector::schedule();
check( 0 === $scheduled, 'Enabled manual connection does not activate automatic polling.' );
$options[Nova_Bridge_Suite_Mapped_Content_Connector::OPTION]['automatic'] = true;
Nova_Bridge_Suite_Mapped_Content_Connector::schedule();
check( 1 === $scheduled && 60 === $events[$hook]['interval'] && 'nova_posting_minute' === $events[$hook]['recurrence'], 'Existing opt-in automatic connection successfully schedules its own poll.' );
Nova_Bridge_Suite_Mapped_Content_Connector::schedule();
check( 1 === $scheduled, 'An existing mapped poll is not scheduled twice.' );
$options[Nova_Bridge_Suite_Mapped_Content_Connector::OPTION]['automatic'] = false;
Nova_Bridge_Suite_Mapped_Content_Connector::schedule();
check( ! isset( $events[$hook] ) && isset( $events['unrelated_hook'] ), 'Disabling automatic polling removes only the mapped connector event.' );
echo 'PASS ' . $checks . " mapped connector scheduling checks.\n";
