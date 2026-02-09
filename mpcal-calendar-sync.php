<?php
/**
 * Plugin Name: MotoPress Calendar - ICS Feed Sync
 * Description: Automated ICS feed syncing for MotoPress Calendar
 * Version: 1.0.0
 * Requires Plugins: motopress-calendar
 * Author: FRS
 * Text Domain: mpcal-ics-sync
 */

namespace Motopress_Calendar\Addons\ICS_Sync;

use Motopress_Calendar\Addon;
use Motopress_Calendar\Motopress_Calendar;
use Motopress_Calendar\Core\Event_Data;
use Motopress_Calendar\Core\Event_Group_Record;
use Motopress_Calendar\Core\Calendar_Data;
use Motopress_Calendar\Core\Event_Location_Data;
use Motopress_Calendar\Core\Event_Organizer_Data;
use Motopress_Calendar\Core\Event_Category_Data;
use Motopress_Calendar\Core\Event_Repeat_Frequency;
use Motopress_Calendar\Core\Event_Repeat_By_Day_Of_Week;
use Motopress_Calendar\Core\Event_Status;
use Motopress_Calendar\Core\Event_Page_Type;

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	function () {

		if ( class_exists( \Motopress_Calendar\Addon::class ) ) {

			init_plugin();

		} else {

			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'MotoPress Calendar - ICS Feed Sync requires MotoPress Calendar plugin version 2.0.0 or higher.', 'mpcal-ics-sync' ) .
						'</p></div>';
				}
			);
		}
	}
);

function init_plugin(): void {

	final class ICS_Sync_Addon extends Addon {

		private const OPTION_NAME_IS_PLUGIN_ACTIVATED = 'mpcal_ics_sync_activated';
		private const OPTION_FEEDS                    = 'mpcal_sync_feeds';
		private const MAX_URL_LENGTH                  = 2048;
		private const MAX_ICS_SIZE                    = 10485760; // 10MB

		private static $instance;
		private string $plugin_dir_with_last_slash;
		private string $plugin_url_with_last_slash;
		private array $plugin_data = array();

		/**
		 * Blocked URL patterns for SSRF protection.
		 */
		private static array $blocked_url_patterns = array(
			'#^https?://(localhost|127\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)#i',
			'#^https?://\[?(::1|fe80:|fc00:|fd00:)#i',
			'#^https?://169\.254\.#',
			'#^https?://metadata\.google#i',
			'#^https?://100\.100\.100\.200#',
		);

		private function __construct() {
			$this->plugin_dir_with_last_slash = plugin_dir_path( __FILE__ );
			$this->plugin_url_with_last_slash = plugin_dir_url( __FILE__ );
		}

		public static function get_instance() {

			if ( ! isset( self::$instance ) ) {

				self::$instance = new ICS_Sync_Addon();

				load_plugin_textdomain(
					'mpcal-ics-sync',
					false,
					dirname( plugin_basename( __FILE__ ) ) . '/languages/'
				);

				register_activation_hook(
					__FILE__,
					function ( $is_network_wide = false ) {
						self::activate_plugin( $is_network_wide );
					}
				);

				register_deactivation_hook(
					__FILE__,
					function ( $is_network_wide = false ) {
						self::deactivate_plugin( $is_network_wide );
					}
				);

				// Register as MotoPress Calendar addon
				add_filter(
					'mpcal_get_addons',
					function ( array $addons ) {
						$addons[] = self::$instance;
						return $addons;
					}
				);

				// Initialize addon functionality
				self::$instance->init_hooks();
			}

			return self::$instance;
		}

		/**
		 * Initialize hooks for admin menu, AJAX, and cron.
		 */
		private function init_hooks(): void {
			// Admin menu
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 99 );

			// AJAX handlers
			add_action( 'wp_ajax_mpcal_sync_test_feed', array( $this, 'ajax_test_feed' ) );
			add_action( 'wp_ajax_mpcal_sync_now', array( $this, 'ajax_sync_now' ) );
			add_action( 'wp_ajax_mpcal_sync_all', array( $this, 'ajax_sync_all' ) );
			add_action( 'wp_ajax_mpcal_sync_add_feed', array( $this, 'ajax_add_feed' ) );
			add_action( 'wp_ajax_mpcal_sync_update_feed', array( $this, 'ajax_update_feed' ) );
			add_action( 'wp_ajax_mpcal_sync_delete_feed', array( $this, 'ajax_delete_feed' ) );

			// Cron
			add_action( 'mpcal_ics_sync_cron', array( $this, 'run_scheduled_sync' ) );
			add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

			// Add-to-Calendar buttons on event display
			add_filter( 'render_block_motopress-calendar/event-schedule', array( $this, 'append_addtocal_buttons_to_block' ), 10, 2 );
			add_filter( 'mpcal_template_filepath', array( $this, 'override_event_preview_template' ), 10, 2 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_addtocal_styles' ) );

			// REST API caching for events endpoint
			add_filter( 'rest_pre_dispatch', array( $this, 'maybe_serve_cached_events' ), 10, 3 );
			add_filter( 'rest_post_dispatch', array( $this, 'maybe_cache_events_response' ), 10, 3 );

			// Invalidate cache when events change
			add_action( 'save_post_mpcal_event', array( $this, 'invalidate_events_cache' ) );
			add_action( 'save_post_mpcal_event_group', array( $this, 'invalidate_events_cache' ) );
			add_action( 'delete_post', array( $this, 'invalidate_events_cache' ) );
		}

		// Addon interface methods
		public static function get_plugin_name(): string {
			return 'ICS Feed Sync';
		}

		public static function get_plugin_version(): string {
			return '1.0.0';
		}

		public static function get_plugin_author_name(): string {
			return 'FRS';
		}

		public static function get_product_id(): string {
			return 'mpcal-ics-sync';
		}

		public static function get_plugin_source_server_url(): string {
			return '';
		}

		/**
		 * Add custom cron schedules.
		 */
		public function add_cron_schedules( array $schedules ): array {
			$schedules['every_15_minutes'] = array(
				'interval' => 900,
				'display'  => __( 'Every 15 Minutes', 'mpcal-ics-sync' ),
			);
			$schedules['every_30_minutes'] = array(
				'interval' => 1800,
				'display'  => __( 'Every 30 Minutes', 'mpcal-ics-sync' ),
			);
			return $schedules;
		}

		/**
		 * Limit default date range for events API to prevent loading all recurring instances.
		 * Only applies when no date range is explicitly requested.
		 */
		public function maybe_serve_cached_events( $result, $server, $request ) {
			// Only handle GET requests to events endpoint
			if ( $request->get_method() !== 'GET' ) {
				return $result;
			}

			$route = $request->get_route();
			if ( strpos( $route, '/mp-calendar/v1/events' ) === false ) {
				return $result;
			}

			// If no date range specified, limit to 3 months before/after (using date only for cache consistency)
			$params = $request->get_params();
			if ( empty( $params['min_start_datetime'] ) && empty( $params['max_start_datetime'] ) ) {
				$tz = wp_timezone();
				$today = new \DateTime( 'today', $tz );

				$min = ( clone $today )->modify( '-3 months' )->setTime( 0, 0, 0 );
				$max = ( clone $today )->modify( '+3 months' )->setTime( 23, 59, 59 );

				$request->set_param( 'min_start_datetime', $min->format( 'Y-m-d\TH:i:sP' ) );
				$request->set_param( 'max_start_datetime', $max->format( 'Y-m-d\TH:i:sP' ) );
			}

			// Check cache
			$cache_key = $this->get_events_cache_key( $request );
			$cached = get_transient( $cache_key );

			if ( false !== $cached ) {
				return new \WP_REST_Response( $cached['data'], $cached['status'] );
			}

			return $result;
		}

		/**
		 * Cache key for events REST API response.
		 */
		private function get_events_cache_key( $request ): string {
			$params = $request->get_params();
			ksort( $params );
			return 'mpcal_events_cache_' . md5( wp_json_encode( $params ) );
		}

		/**
		 * Cache events response after it's generated.
		 */
		public function maybe_cache_events_response( $response, $server, $request ) {
			// Only cache GET requests to events endpoint
			if ( $request->get_method() !== 'GET' ) {
				return $response;
			}

			$route = $request->get_route();
			if ( strpos( $route, '/mp-calendar/v1/events' ) === false ) {
				return $response;
			}

			// Don't cache errors
			if ( $response->get_status() >= 400 ) {
				return $response;
			}

			$cache_key = $this->get_events_cache_key( $request );

			// Cache for 1 hour
			set_transient( $cache_key, array(
				'data'   => $response->get_data(),
				'status' => $response->get_status(),
			), HOUR_IN_SECONDS );

			return $response;
		}

		/**
		 * Invalidate events cache when events change.
		 */
		public function invalidate_events_cache( $post_id = null ): void {
			global $wpdb;

			// Delete all event cache transients
			$wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mpcal_events_cache_%' OR option_name LIKE '_transient_timeout_mpcal_events_cache_%'"
			);
		}

		/**
		 * Add admin menu under MotoPress Calendar.
		 */
		public function add_admin_menu(): void {
			add_submenu_page(
				'motopress-calendar',
				__( 'ICS Feed Sync', 'mpcal-ics-sync' ),
				__( 'ICS Feed Sync', 'mpcal-ics-sync' ),
				'manage_options',
				'mpcal-ics-sync',
				array( $this, 'render_admin_page' ),
				100
			);
		}

		/**
		 * Get all configured feeds.
		 */
		public function get_feeds(): array {
			return get_option( self::OPTION_FEEDS, array() );
		}

		/**
		 * Save feeds to options.
		 */
		public function save_feeds( array $feeds ): void {
			update_option( self::OPTION_FEEDS, $feeds );
		}

		/**
		 * Get MotoPress calendars for dropdown.
		 */
		public function get_calendars(): array {
			$calendars = array( 0 => __( '-- Auto-detect from ICS --', 'mpcal-ics-sync' ) );

			try {
				$events_api     = Motopress_Calendar::get_events_api();
				$search_request = new \Motopress_Calendar\Core\Search_Request( 0, 100 );
				$result         = $events_api->get_calendars( $search_request );

				foreach ( $result as $calendar ) {
					$calendars[ $calendar->get_id() ] = $calendar->get_title();
				}
			} catch ( \Exception $e ) {
				// Return just the auto-detect option
			}

			return $calendars;
		}

		/**
		 * Validate ICS URL with SSRF protection.
		 */
		public function validate_url( string $url ) {
			if ( empty( $url ) ) {
				return new \WP_Error( 'empty_url', __( 'URL is required.', 'mpcal-ics-sync' ) );
			}

			if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
				return new \WP_Error( 'url_too_long', __( 'URL is too long.', 'mpcal-ics-sync' ) );
			}

			$parsed = wp_parse_url( $url );
			if ( ! isset( $parsed['scheme'] ) || 'https' !== $parsed['scheme'] ) {
				return new \WP_Error( 'insecure_url', __( 'Only HTTPS URLs are allowed.', 'mpcal-ics-sync' ) );
			}

			foreach ( self::$blocked_url_patterns as $pattern ) {
				if ( preg_match( $pattern, $url ) ) {
					return new \WP_Error( 'blocked_url', __( 'This URL is not allowed for security reasons.', 'mpcal-ics-sync' ) );
				}
			}

			return true;
		}

		/**
		 * Fetch ICS content from URL.
		 */
		public function fetch_ics( string $url ) {
			$validation = $this->validate_url( $url );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 60,
					'user-agent' => 'mpcal-ics-sync/1.0.0 (WordPress Plugin)',
					'headers'    => array( 'Accept' => 'text/calendar, */*' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'fetch_failed', sprintf( __( 'Failed to fetch ICS feed: %s', 'mpcal-ics-sync' ), $response->get_error_message() ) );
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				return new \WP_Error( 'bad_status', sprintf( __( 'ICS feed returned HTTP status %d.', 'mpcal-ics-sync' ), $status_code ) );
			}

			$content = wp_remote_retrieve_body( $response );

			if ( empty( $content ) ) {
				return new \WP_Error( 'empty_content', __( 'ICS feed returned empty content.', 'mpcal-ics-sync' ) );
			}

			if ( strlen( $content ) > self::MAX_ICS_SIZE ) {
				return new \WP_Error( 'too_large', __( 'ICS feed is too large to process.', 'mpcal-ics-sync' ) );
			}

			if ( strpos( $content, 'BEGIN:VCALENDAR' ) === false ) {
				return new \WP_Error( 'invalid_ics', __( 'The URL did not return valid iCalendar data.', 'mpcal-ics-sync' ) );
			}

			return $content;
		}

		/**
		 * Sync a single feed - creates events properly via MotoPress API.
		 */
		public function sync_feed( string $feed_id ) {
			$feeds = $this->get_feeds();

			if ( ! isset( $feeds[ $feed_id ] ) ) {
				return new \WP_Error( 'feed_not_found', __( 'Feed not found.', 'mpcal-ics-sync' ) );
			}

			$feed = $feeds[ $feed_id ];

			// Ensure admin user context
			if ( 0 === get_current_user_id() ) {
				$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
				if ( ! empty( $admins ) ) {
					wp_set_current_user( $admins[0]->ID );
				}
			}

			// Check permission
			if ( ! Motopress_Calendar::get_users_api()->is_user_can_create_event() ) {
				return new \WP_Error( 'no_permission', __( 'Current user cannot create events.', 'mpcal-ics-sync' ) );
			}

			// Fetch ICS
			$ics_content = $this->fetch_ics( $feed['url'] );
			if ( is_wp_error( $ics_content ) ) {
				$this->update_feed_status( $feed_id, 'error', $ics_content->get_error_message() );
				return $ics_content;
			}

			// Parse ICS with Sabre VObject
			try {
				$vcalendar = \Sabre\VObject\Reader::read( $ics_content, \Sabre\VObject\Reader::OPTION_FORGIVING );
			} catch ( \Exception $e ) {
				$this->update_feed_status( $feed_id, 'error', 'Failed to parse ICS: ' . $e->getMessage() );
				return new \WP_Error( 'parse_failed', 'Failed to parse ICS: ' . $e->getMessage() );
			}

			$vevents = $vcalendar->select( 'VEVENT' );
			$total   = count( $vevents );

			if ( 0 === $total ) {
				$this->update_feed_status( $feed_id, 'success', __( 'No events found in feed.', 'mpcal-ics-sync' ) );
				return array(
					'success' => true,
					'total'   => 0,
					'created' => 0,
					'updated' => 0,
					'skipped' => 0,
					'errors'  => 0,
					'message' => __( 'No events found in feed.', 'mpcal-ics-sync' ),
				);
			}

			$events_api  = Motopress_Calendar::get_events_api();
			$settings_api = Motopress_Calendar::get_settings_api();
			$wp_timezone = $settings_api->get_wp_timezone();

			// Resolve calendar ID
			$calendar_id = (int) $feed['calendar_id'];
			if ( 0 === $calendar_id ) {
				$calendar = $events_api->get_calendar_by_title( 'Imported Events' );
				if ( null === $calendar ) {
					$calendar_data = new Calendar_Data();
					$calendar_data->set_title( 'Imported Events' );
					$calendar = $events_api->create_calendar( $calendar_data );
				}
				$calendar_id = $calendar->get_id();
			}

			$created = 0;
			$updated = 0;
			$skipped = 0;
			$errors  = 0;

			foreach ( $vevents as $vevent ) {
				try {
					// Skip events without UID
					if ( empty( $vevent->UID ) ) {
						++$skipped;
						continue;
					}

					$uid = (string) $vevent->UID;

					// Skip recurring instances for now (RECURRENCE-ID)
					// These need parent event to exist first
					if ( ! empty( $vevent->{'RECURRENCE-ID'} ) ) {
						++$skipped;
						continue;
					}

					// Check DTSTART
					if ( ! isset( $vevent->DTSTART ) ) {
						++$skipped;
						continue;
					}

					// Check if event exists by imported_uid
					$existing_event = $events_api->get_event_by_imported_uid( $uid );

					// Handle update mode
					if ( null !== $existing_event && 'no' === $feed['update_mode'] ) {
						++$skipped;
						continue;
					}

					// Get title
					$title = ! empty( $vevent->SUMMARY ) ? (string) $vevent->SUMMARY : 'Untitled Event';

					// Get description
					$description = ! empty( $vevent->DESCRIPTION ) ? (string) $vevent->DESCRIPTION : '';

					// Parse start/end times
					$dtstart    = $vevent->DTSTART;
					$is_all_day = ( 'DATE' === (string) $dtstart->getValueType() );

					$start_dt = $this->parse_datetime( $dtstart, $wp_timezone, $is_all_day );

					$end_dt = null;
					if ( isset( $vevent->DTEND ) ) {
						$end_dt = $this->parse_datetime( $vevent->DTEND, $wp_timezone, $is_all_day );
						if ( $is_all_day ) {
							// ICS all-day end is exclusive, subtract 1 day
							$end_dt->modify( '-1 day' )->setTime( 23, 59, 59 );
						}
					} elseif ( isset( $vevent->DURATION ) ) {
						// Handle DURATION instead of DTEND
						$end_dt = clone $start_dt;
						$duration = \Sabre\VObject\DateTimeParser::parseDuration( (string) $vevent->DURATION );
						$end_dt->add( $duration );
					} else {
						$end_dt = clone $start_dt;
						if ( $is_all_day ) {
							$end_dt->setTime( 23, 59, 59 );
						}
					}

					// Build Event_Data
					$event_data = new Event_Data();
					$event_data->set_imported_uid( $uid );
					$event_data->set_calendar_id( $calendar_id );
					$event_data->set_title( $title );
					$event_data->set_description( $description );
					$event_data->set_all_day_event( $is_all_day );
					$event_data->set_start_in_wp_timezone( $start_dt );
					$event_data->set_end_in_wp_timezone( $end_dt );

					// Set page type to PAGE so event groups are published (visible on calendar)
					$event_data->set_event_page_type( Event_Page_Type::PAGE() );

					// Set event group imported ID for recurring event grouping
					// This helps link all instances of a recurring event
					if ( ! empty( $vevent->RRULE ) ) {
						$event_data->set_event_group_imported_id( $uid );
					}

					// Location
					if ( ! empty( $vevent->LOCATION ) ) {
						$location_title = (string) $vevent->LOCATION;
						$location       = $events_api->get_event_location_by_title( $location_title );
						if ( null === $location ) {
							$location_data = new Event_Location_Data();
							$location_data->set_title( $location_title );
							$location = $events_api->create_event_location( $location_data );
						}
						$event_data->set_event_location_id( $location->get_id() );
					}

					// Organizer - parse from ORGANIZER property
					if ( ! empty( $vevent->ORGANIZER ) ) {
						$organizer_name = $this->parse_organizer( $vevent->ORGANIZER );
						if ( ! empty( $organizer_name ) ) {
							$organizer = $events_api->get_event_organizer_by_name( $organizer_name );
							if ( null === $organizer ) {
								$organizer_data = new Event_Organizer_Data();
								$organizer_data->set_name( $organizer_name );
								$organizer = $events_api->create_event_organizer( $organizer_data );
							}
							$event_data->set_event_organizer_id( $organizer->get_id() );
						}
					}

					// Categories - parse from CATEGORIES property
					if ( ! empty( $vevent->CATEGORIES ) ) {
						$category_ids = $this->parse_categories( $vevent->CATEGORIES, $events_api );
						if ( ! empty( $category_ids ) ) {
							$event_data->set_category_ids( $category_ids );
						}
					}

					// URL - store for later use (don't use CUSTOM_URL type as it makes event private)
					$event_url = '';
					if ( ! empty( $vevent->URL ) ) {
						$event_url = (string) $vevent->URL;
					}

					// Status - map ICS STATUS to MotoPress status
					if ( ! empty( $vevent->STATUS ) ) {
						$ics_status = strtoupper( (string) $vevent->STATUS );
						if ( 'CANCELLED' === $ics_status || 'CANCELED' === $ics_status ) {
							$event_data->set_event_status( Event_Status::CANCELED() );
						} else {
							// CONFIRMED, TENTATIVE, or anything else = published
							$event_data->set_event_status( Event_Status::PUBLISHED() );
						}
					}

					// Color - check various color properties
					$color = $this->parse_color( $vevent );
					if ( ! empty( $color ) ) {
						$event_data->set_color( $color );
					}

					// Parse RRULE for repeating events
					if ( ! empty( $vevent->RRULE ) ) {
						$this->parse_rrule( $vevent->RRULE, $event_data, $wp_timezone );
					}

					// Create or update
					if ( null === $existing_event ) {
						$created_event = $events_api->create_event( $event_data );
						$event_post_id = $created_event->get_id();
						$event_group_id = $created_event->get_event_group_id();
						++$created;
					} else {
						$event_data->set_id( $existing_event->get_id() );
						$events_api->update_event( $event_data );
						$event_post_id = $existing_event->get_id();
						$event_group_id = $existing_event->get_event_group_id();
						++$updated;
					}

					// Store event URL if available
					if ( ! empty( $event_url ) && filter_var( $event_url, FILTER_VALIDATE_URL ) ) {
						update_post_meta( $event_group_id, '_mpcal_event_url', $event_url );
					}

				} catch ( \Exception $e ) {
					++$errors;
					error_log( 'MPCAL Sync error for UID ' . ( $uid ?? 'unknown' ) . ': ' . $e->getMessage() );
				}
			}

			$message = sprintf(
				__( 'Synced %d events: %d created, %d updated, %d skipped, %d errors.', 'mpcal-ics-sync' ),
				$total,
				$created,
				$updated,
				$skipped,
				$errors
			);

			$this->update_feed_status( $feed_id, ( $errors > 0 ? 'warning' : 'success' ), $message );

			// Invalidate events cache after sync
			$this->invalidate_events_cache();

			return array(
				'success' => true,
				'total'   => $total,
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
				'errors'  => $errors,
				'message' => $message,
			);
		}

		/**
		 * Parse datetime from VEVENT property.
		 */
		private function parse_datetime( $property, \DateTimeZone $wp_timezone, bool $is_all_day ): \DateTime {
			$params   = $property->parameters();
			$timezone = $wp_timezone;

			if ( ! $is_all_day ) {
				if ( ! empty( $params['TZID'] ) ) {
					try {
						$timezone = new \DateTimeZone( (string) $params['TZID'] );
					} catch ( \Exception $e ) {
						// Fall back to WP timezone
					}
				} elseif ( 'Z' === substr( (string) $property, -1 ) ) {
					$timezone = new \DateTimeZone( 'UTC' );
				}
			}

			$dt = \Sabre\VObject\DateTimeParser::parse( (string) $property, $timezone );
			$dt = \DateTime::createFromImmutable( $dt );

			if ( ! $is_all_day ) {
				$dt->setTimezone( $wp_timezone );
			}

			return $dt;
		}

		/**
		 * Parse RRULE into Event_Data repeat fields.
		 */
		private function parse_rrule( $rrule, Event_Data $event_data, \DateTimeZone $wp_timezone ): void {
			$parts = $rrule->getParts();

			// Frequency
			if ( isset( $parts['FREQ'] ) ) {
				$freq_map = array(
					'DAILY'   => Event_Repeat_Frequency::DAILY(),
					'WEEKLY'  => Event_Repeat_Frequency::WEEKLY(),
					'MONTHLY' => Event_Repeat_Frequency::MONTHLY(),
					'YEARLY'  => Event_Repeat_Frequency::YEARLY(),
				);
				if ( isset( $freq_map[ $parts['FREQ'] ] ) ) {
					$event_data->set_repeat_frequency( $freq_map[ $parts['FREQ'] ] );
				}
			}

			// Interval
			if ( isset( $parts['INTERVAL'] ) ) {
				$event_data->set_repeat_interval( max( 1, absint( $parts['INTERVAL'] ) ) );
			} else {
				$event_data->set_repeat_interval( 1 );
			}

			// Count
			if ( isset( $parts['COUNT'] ) ) {
				$event_data->set_repeat_count( max( 1, absint( $parts['COUNT'] ) ) );
			}

			// Until
			if ( isset( $parts['UNTIL'] ) ) {
				$until = \DateTime::createFromFormat( 'Ymd\THis\Z', $parts['UNTIL'], new \DateTimeZone( 'UTC' ) );
				if ( false === $until ) {
					$until = \DateTime::createFromFormat( 'Ymd', $parts['UNTIL'], $wp_timezone );
					if ( false !== $until ) {
						$until->setTime( 23, 59, 59 );
					}
				} else {
					$until->setTimezone( $wp_timezone );
				}
				if ( false !== $until ) {
					$event_data->set_repeat_until_in_wp_timezone( $until );
				}
			}

			// BYDAY
			if ( isset( $parts['BYDAY'] ) ) {
				$by_day = new Event_Repeat_By_Day_Of_Week();
				$days   = is_array( $parts['BYDAY'] ) ? $parts['BYDAY'] : array( $parts['BYDAY'] );

				foreach ( $days as $day_value ) {
					if ( preg_match( '/^(-?\d+)?([A-Z]{2})$/', $day_value, $matches ) ) {
						$in_month    = isset( $matches[1] ) && $matches[1] !== '' ? intval( $matches[1] ) : 0;
						$day_of_week = $matches[2];
						$by_day->add_day( $day_of_week, $in_month );
					}
				}

				if ( ! $by_day->is_empty() ) {
					$event_data->set_repeat_by_day_of_week( $by_day );
				}
			}

			// BYMONTHDAY
			if ( isset( $parts['BYMONTHDAY'] ) ) {
				$month_days = is_array( $parts['BYMONTHDAY'] ) ? $parts['BYMONTHDAY'] : explode( ',', $parts['BYMONTHDAY'] );
				$filtered   = array();
				foreach ( $month_days as $day ) {
					$d = intval( $day );
					if ( $d >= 1 && $d <= 31 ) {
						$filtered[] = $d;
					}
				}
				if ( ! empty( $filtered ) ) {
					$event_data->set_repeat_by_month_day( $filtered );
				}
			}
		}

		/**
		 * Build calendar service URLs from event data.
		 */
		private function build_calendar_urls( string $title, string $description, \DateTime $start, \DateTime $end, bool $is_all_day, string $location ): array {
			$start_utc = clone $start;
			$start_utc->setTimezone( new \DateTimeZone( 'UTC' ) );
			$end_utc = clone $end;
			$end_utc->setTimezone( new \DateTimeZone( 'UTC' ) );

			$title_enc = rawurlencode( $title );
			$desc_enc  = rawurlencode( substr( wp_strip_all_tags( $description ), 0, 1000 ) );
			$loc_enc   = rawurlencode( $location );

			if ( $is_all_day ) {
				$g_dates = $start->format( 'Ymd' ) . '/' . ( clone $end )->modify( '+1 day' )->format( 'Ymd' );
				$o_start = $start->format( 'Y-m-d' );
				$o_end   = $end->format( 'Y-m-d' );
			} else {
				$g_dates = $start_utc->format( 'Ymd\THis\Z' ) . '/' . $end_utc->format( 'Ymd\THis\Z' );
				$o_start = $start_utc->format( 'Y-m-d\TH:i:s\Z' );
				$o_end   = $end_utc->format( 'Y-m-d\TH:i:s\Z' );
			}

			// Google
			$google = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . $title_enc . '&dates=' . $g_dates;
			if ( $description ) $google .= '&details=' . $desc_enc;
			if ( $location )    $google .= '&location=' . $loc_enc;

			// Outlook.com
			$outlook = 'https://outlook.live.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent&subject=' . $title_enc . '&startdt=' . rawurlencode( $o_start ) . '&enddt=' . rawurlencode( $o_end );
			if ( $is_all_day )  $outlook .= '&allday=true';
			if ( $description ) $outlook .= '&body=' . $desc_enc;
			if ( $location )    $outlook .= '&location=' . $loc_enc;

			// Office 365
			$office365 = 'https://outlook.office.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent&subject=' . $title_enc . '&startdt=' . rawurlencode( $o_start ) . '&enddt=' . rawurlencode( $o_end );
			if ( $is_all_day )  $office365 .= '&allday=true';
			if ( $description ) $office365 .= '&body=' . $desc_enc;
			if ( $location )    $office365 .= '&location=' . $loc_enc;

			// Yahoo
			$yahoo = 'https://calendar.yahoo.com/?v=60&title=' . $title_enc;
			$yahoo .= '&st=' . ( $is_all_day ? $start->format( 'Ymd' ) : $start_utc->format( 'Ymd\THis\Z' ) );
			$yahoo .= '&et=' . ( $is_all_day ? $end->format( 'Ymd' ) : $end_utc->format( 'Ymd\THis\Z' ) );
			if ( $is_all_day )  $yahoo .= '&dur=allday';
			if ( $description ) $yahoo .= '&desc=' . $desc_enc;
			if ( $location )    $yahoo .= '&in_loc=' . $loc_enc;

			// ICS via calndr.link
			$ics_params = array( 'title' => $title, 'start' => $is_all_day ? $start->format( 'Y-m-d' ) : $start_utc->format( 'Y-m-d\TH:i:s\Z' ), 'end' => $is_all_day ? $end->format( 'Y-m-d' ) : $end_utc->format( 'Y-m-d\TH:i:s\Z' ) );
			if ( $description ) $ics_params['description'] = substr( wp_strip_all_tags( $description ), 0, 500 );
			if ( $location )    $ics_params['location'] = $location;
			if ( $is_all_day )  $ics_params['allDay'] = 'true';

			return array(
				'google'    => $google,
				'outlook'   => $outlook,
				'office365' => $office365,
				'yahoo'     => $yahoo,
				'ics'       => 'https://calndr.link/d/event/?' . http_build_query( $ics_params ),
			);
		}

		/**
		 * Parse ORGANIZER property to get organizer name.
		 * Format: ORGANIZER;CN=Name:mailto:email@example.com
		 */
		private function parse_organizer( $organizer ): string {
			$params = $organizer->parameters();

			// Try CN (Common Name) parameter first
			if ( ! empty( $params['CN'] ) ) {
				return trim( (string) $params['CN'] );
			}

			// Fall back to email address
			$value = (string) $organizer;
			if ( 0 === strpos( strtolower( $value ), 'mailto:' ) ) {
				$email = substr( $value, 7 );
				// Extract name part before @ if no CN
				$at_pos = strpos( $email, '@' );
				if ( false !== $at_pos ) {
					return ucwords( str_replace( array( '.', '_', '-' ), ' ', substr( $email, 0, $at_pos ) ) );
				}
				return $email;
			}

			return trim( $value );
		}

		/**
		 * Parse CATEGORIES property to get category IDs.
		 * Format: CATEGORIES:Category1,Category2,Category3
		 */
		private function parse_categories( $categories, $events_api ): array {
			$category_ids = array();

			// CATEGORIES can be a single property with comma-separated values
			// or multiple CATEGORIES properties
			$cat_values = array();

			if ( is_array( $categories ) ) {
				foreach ( $categories as $cat ) {
					$cat_values = array_merge( $cat_values, explode( ',', (string) $cat ) );
				}
			} else {
				$cat_values = explode( ',', (string) $categories );
			}

			foreach ( $cat_values as $cat_name ) {
				$cat_name = trim( $cat_name );
				if ( empty( $cat_name ) ) {
					continue;
				}

				try {
					$category = $events_api->get_event_category_by_title( $cat_name );
					if ( null === $category ) {
						$category_data = new Event_Category_Data();
						$category_data->set_title( $cat_name );
						$category = $events_api->create_event_category( $category_data );
					}
					$category_ids[] = $category->get_id();
				} catch ( \Exception $e ) {
					// Skip category on error
					error_log( 'MPCAL Sync: Failed to create category "' . $cat_name . '": ' . $e->getMessage() );
				}
			}

			return array_unique( $category_ids );
		}

		/**
		 * Parse color from various ICS color properties.
		 * Checks: COLOR, X-APPLE-CALENDAR-COLOR, X-OUTLOOK-COLOR, X-FUNAMBOL-COLOR
		 */
		private function parse_color( $vevent ): ?string {
			// Check standard COLOR property (RFC 7986)
			if ( ! empty( $vevent->COLOR ) ) {
				return $this->normalize_color( (string) $vevent->COLOR );
			}

			// Check Apple's X-APPLE-CALENDAR-COLOR
			if ( ! empty( $vevent->{'X-APPLE-CALENDAR-COLOR'} ) ) {
				return $this->normalize_color( (string) $vevent->{'X-APPLE-CALENDAR-COLOR'} );
			}

			// Check Outlook's color
			if ( ! empty( $vevent->{'X-OUTLOOK-COLOR'} ) ) {
				return $this->normalize_color( (string) $vevent->{'X-OUTLOOK-COLOR'} );
			}

			// Check Funambol color
			if ( ! empty( $vevent->{'X-FUNAMBOL-COLOR'} ) ) {
				return $this->normalize_color( (string) $vevent->{'X-FUNAMBOL-COLOR'} );
			}

			return null;
		}

		/**
		 * Normalize color to hex format.
		 */
		private function normalize_color( string $color ): ?string {
			$color = trim( $color );

			// Already hex format
			if ( preg_match( '/^#[0-9A-Fa-f]{6}$/', $color ) ) {
				return strtolower( $color );
			}
			if ( preg_match( '/^#[0-9A-Fa-f]{3}$/', $color ) ) {
				// Expand 3-char hex to 6-char
				return '#' . strtolower( $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3] );
			}

			// Without hash
			if ( preg_match( '/^[0-9A-Fa-f]{6}$/', $color ) ) {
				return '#' . strtolower( $color );
			}

			// Named colors mapping
			$named_colors = array(
				'red'     => '#ff0000',
				'green'   => '#00ff00',
				'blue'    => '#0000ff',
				'yellow'  => '#ffff00',
				'orange'  => '#ffa500',
				'purple'  => '#800080',
				'pink'    => '#ffc0cb',
				'cyan'    => '#00ffff',
				'magenta' => '#ff00ff',
				'brown'   => '#a52a2a',
				'gray'    => '#808080',
				'grey'    => '#808080',
				'black'   => '#000000',
				'white'   => '#ffffff',
			);

			$lower = strtolower( $color );
			if ( isset( $named_colors[ $lower ] ) ) {
				return $named_colors[ $lower ];
			}

			return null;
		}

		/**
		 * Update feed status after sync.
		 */
		private function update_feed_status( string $feed_id, string $status, string $message ): void {
			$feeds = $this->get_feeds();

			if ( isset( $feeds[ $feed_id ] ) ) {
				$feeds[ $feed_id ]['last_sync']    = time();
				$feeds[ $feed_id ]['last_status']  = $status;
				$feeds[ $feed_id ]['last_message'] = $message;
				$this->save_feeds( $feeds );
			}
		}

		/**
		 * Run scheduled sync for all enabled feeds.
		 */
		public function run_scheduled_sync(): void {
			$feeds = $this->get_feeds();

			foreach ( $feeds as $feed_id => $feed ) {
				if ( empty( $feed['enabled'] ) ) {
					continue;
				}

				$last_sync = $feed['last_sync'] ?? 0;
				$interval  = $feed['sync_interval'] ?? 'hourly';

				$interval_map = array(
					'every_15_minutes' => 900,
					'every_30_minutes' => 1800,
					'hourly'           => 3600,
					'twicedaily'       => 43200,
					'daily'            => 86400,
				);

				$interval_seconds = $interval_map[ $interval ] ?? 3600;

				if ( ( time() - $last_sync ) >= $interval_seconds ) {
					$this->sync_feed( $feed_id );
				}
			}
		}

		// ==================== ADD TO CALENDAR DISPLAY ====================

		/**
		 * Append add-to-calendar buttons after the event-schedule block output.
		 */
		public function append_addtocal_buttons_to_block( string $block_content, array $block ): string {
			if ( ! is_singular( 'mpcal_event_group' ) ) {
				return $block_content;
			}

			$post_id = get_the_ID();
			$buttons = $this->render_addtocal_buttons( $post_id );

			if ( empty( $buttons ) ) {
				return $block_content;
			}

			return $block_content . $buttons;
		}

		/**
		 * Override event preview template to include add-to-calendar buttons.
		 */
		public function override_event_preview_template( string $filepath, string $template_name ): string {
			if ( 'event-preview.php' !== $template_name ) {
				return $filepath;
			}

			// Return our custom template
			$custom_template = $this->plugin_dir_with_last_slash . 'templates/event-preview.php';

			if ( file_exists( $custom_template ) ) {
				return $custom_template;
			}

			return $filepath;
		}

		/**
		 * Enqueue add-to-calendar button assets.
		 */
		public function enqueue_addtocal_styles(): void {
			if ( ! is_singular( 'mpcal_event_group' ) ) {
				return;
			}
			wp_add_inline_style( 'wp-block-library', $this->get_addtocal_css() );
			wp_add_inline_script( 'wp-block-library', $this->get_addtocal_js(), 'after' );
		}

		/**
		 * Brand SVG icons for calendar services.
		 */
		private function get_icon( string $service ): string {
			$icons = array(
				'google'    => '<svg width="18" height="18" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18A11.96 11.96 0 0 0 1 12c0 1.94.46 3.77 1.18 5.07l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>',
				'outlook'   => '<svg width="18" height="18" viewBox="0 0 24 24"><path d="M24 7.387v10.478c0 .23-.08.424-.238.576a.806.806 0 0 1-.588.234h-8.42v-6.56l1.678 1.253a.546.546 0 0 0 .672 0L24 7.387z" fill="#0072C6"/><path d="M17.204 5.108l-1.278.96a.546.546 0 0 1-.672 0l-1.278-.96h-.007v2.58l1.617 1.21a.546.546 0 0 0 .672 0L24 2.86V2.1c0-.23-.08-.424-.238-.576A.806.806 0 0 0 23.174 1.29H14.753v3.818h2.451z" fill="#0072C6"/><path d="M8.758 8.633c-.626-.417-1.41-.626-2.35-.626-.96 0-1.75.213-2.37.638-.618.426-.93 1.047-.93 1.863v3.998c0 .824.315 1.45.946 1.877.63.426 1.416.64 2.354.64.94 0 1.72-.214 2.343-.64.622-.428.934-1.053.934-1.877v-3.998c0-.808-.31-1.43-.927-1.875z" fill="#0072C6"/><path d="M7.542 15.29c-.213.33-.558.495-1.034.495-.475 0-.822-.164-1.04-.492-.22-.328-.328-.818-.328-1.47v-4.09c0-.665.113-1.16.34-1.485.226-.326.574-.49 1.04-.49.46 0 .8.165 1.02.496.22.33.33.823.33 1.48v4.088c0 .652-.11 1.142-.328 1.47z" fill="#fff"/><path d="M14.004 5.11V18.4H1.588C1.152 18.4.8 18.252.53 17.955.176 17.576 0 17.133 0 16.625V4.565c0-.508.176-.95.53-1.33.27-.296.622-.444 1.057-.444h10.52c.648 0 1.214.244 1.698.732.16.166.2.34.2.52v1.065z" fill="#0072C6"/></svg>',
				'office365' => '<svg width="18" height="18" viewBox="0 0 24 24"><path d="M11.5 3L2 7v10l9.5 4L22 17V7L11.5 3z" fill="#D83B01"/><path d="M11.5 3L2 7v10l9.5 4V3z" fill="#EA3E23"/><path d="M11.5 3v18L22 17V7L11.5 3z" fill="#FF6A33"/><path d="M8 9h8v6H8V9z" fill="#fff" opacity=".3"/></svg>',
				'yahoo'     => '<svg width="18" height="18" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="#6001D2" rx="4"/><path d="M5.5 5l3.8 7.4L13 5h3l-5.7 10.5V19h-3v-3.5L1.5 5h4zm11.5 0h3v14h-3V5z" fill="#fff"/></svg>',
				'apple'     => '<svg width="18" height="18" viewBox="0 0 24 24"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z" fill="#333"/></svg>',
			);
			return $icons[ $service ] ?? '';
		}

		/**
		 * Render add-to-calendar dropdown button.
		 * Pulls event data from MotoPress Calendar and builds URLs dynamically.
		 */
		public function render_addtocal_buttons( int $event_group_id ): string {
			if ( ! class_exists( 'Motopress_Calendar\Motopress_Calendar' ) ) {
				return '';
			}

			$event_group = \Motopress_Calendar\Motopress_Calendar::get_events_api()->get_event_group_by_id( $event_group_id );
			if ( null === $event_group ) {
				return '';
			}

			$wp_tz    = wp_timezone();
			$now      = new \DateTime( 'now', $wp_tz );
			$result   = \Motopress_Calendar\Core\Event_Search_Helper::find_upcoming_group_events( $event_group_id, $now, 1 );
			$events   = $result->get_events();
			$event    = ! empty( $events ) ? $events[0] : null;

			if ( null === $event ) {
				return '';
			}

			$title       = $event_group->get_event_title();
			$description = $event_group->get_event_description();
			$start       = $event->get_start_in_wp_timezone();
			$end         = $event->get_end_in_wp_timezone();
			$is_all_day  = $event->is_all_day_event();
			$location    = $event->get_event_location() ? $event->get_event_location()->get_title() : '';

			$urls = $this->build_calendar_urls( $title, $description, $start, $end, $is_all_day, $location );

			$html  = '<div class="mpcal-atc-wrap">';
			$html .= '<button type="button" class="mpcal-atc-btn" onclick="this.parentElement.classList.toggle(\'open\')">';
			$html .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
			$html .= '<span>Add to Calendar</span>';
			$html .= '<svg class="mpcal-atc-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
			$html .= '</button>';
			$html .= '<div class="mpcal-atc-dropdown">';

			$links = array(
				array( 'url' => $urls['google'],    'label' => 'Google Calendar', 'icon' => 'google' ),
				array( 'url' => $urls['office365'], 'label' => 'Microsoft 365',  'icon' => 'office365' ),
				array( 'url' => $urls['outlook'],   'label' => 'Outlook.com',    'icon' => 'outlook' ),
				array( 'url' => $urls['yahoo'],     'label' => 'Yahoo Calendar', 'icon' => 'yahoo' ),
				array( 'url' => $urls['ics'],       'label' => 'Apple / iCal',   'icon' => 'apple' ),
			);

			foreach ( $links as $link ) {
				if ( ! empty( $link['url'] ) ) {
					$html .= '<a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener">';
					$html .= '<span class="mpcal-atc-icon">' . $this->get_icon( $link['icon'] ) . '</span>';
					$html .= '<span>' . esc_html( $link['label'] ) . '</span>';
					$html .= '</a>';
				}
			}

			$html .= '</div></div>';
			return $html;
		}

		/**
		 * Get CSS for add-to-calendar dropdown.
		 */
		private function get_addtocal_css(): string {
			return '
.mpcal-atc-wrap{position:relative;display:inline-block;margin:1.5em 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,sans-serif}
.mpcal-atc-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;background:#1a73e8;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;transition:background .2s,box-shadow .2s;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.mpcal-atc-btn:hover{background:#1557b0;box-shadow:0 2px 6px rgba(0,0,0,.2)}
.mpcal-atc-btn svg{flex-shrink:0}
.mpcal-atc-chevron{transition:transform .2s}
.mpcal-atc-wrap.open .mpcal-atc-chevron{transform:rotate(180deg)}
.mpcal-atc-dropdown{display:none;position:absolute;top:calc(100% + 6px);left:0;min-width:220px;background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:1000;overflow:hidden;border:1px solid rgba(0,0,0,.08)}
.mpcal-atc-wrap.open .mpcal-atc-dropdown{display:block;animation:mpcal-fade-in .15s ease}
@keyframes mpcal-fade-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
.mpcal-atc-dropdown a{display:flex;align-items:center;gap:12px;padding:11px 16px;color:#333;text-decoration:none;font-size:14px;transition:background .12s}
.mpcal-atc-dropdown a:hover{background:#f5f7fa}
.mpcal-atc-dropdown a:not(:last-child){border-bottom:1px solid #f0f0f0}
.mpcal-atc-icon{display:flex;align-items:center;justify-content:center;width:24px;height:24px;flex-shrink:0}
.mpcal-atc-icon svg{display:block}';
		}

		/**
		 * Get JS for closing dropdown on outside click.
		 */
		private function get_addtocal_js(): string {
			return 'document.addEventListener("click",function(e){document.querySelectorAll(".mpcal-atc-wrap.open").forEach(function(w){if(!w.contains(e.target))w.classList.remove("open")})});';
		}

		// ==================== AJAX HANDLERS ====================

		public function ajax_test_feed(): void {
			check_ajax_referer( 'mpcal_sync_ajax' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Permission denied.', 'mpcal-ics-sync' ) );
			}

			$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

			if ( empty( $url ) ) {
				wp_send_json_error( __( 'URL is required.', 'mpcal-ics-sync' ) );
			}

			$ics_content = $this->fetch_ics( $url );

			if ( is_wp_error( $ics_content ) ) {
				wp_send_json_error( $ics_content->get_error_message() );
			}

			$event_count = substr_count( $ics_content, 'BEGIN:VEVENT' );

			wp_send_json_success(
				sprintf( __( 'Valid ICS feed with %d events.', 'mpcal-ics-sync' ), $event_count )
			);
		}

		public function ajax_sync_now(): void {
			check_ajax_referer( 'mpcal_sync_ajax' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Permission denied.', 'mpcal-ics-sync' ) );
			}

			$feed_id = isset( $_POST['feed_id'] ) ? sanitize_key( $_POST['feed_id'] ) : '';

			if ( empty( $feed_id ) ) {
				wp_send_json_error( __( 'Feed ID is required.', 'mpcal-ics-sync' ) );
			}

			$result = $this->sync_feed( $feed_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success( $result['message'] ?? __( 'Sync completed.', 'mpcal-ics-sync' ) );
		}

		public function ajax_sync_all(): void {
			check_ajax_referer( 'mpcal_sync_ajax' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Permission denied.', 'mpcal-ics-sync' ) );
			}

			$feeds   = $this->get_feeds();
			$results = array( 'success' => 0, 'failed' => 0, 'total' => count( $feeds ) );

			foreach ( $feeds as $feed_id => $feed ) {
				$result = $this->sync_feed( $feed_id );
				if ( is_wp_error( $result ) ) {
					++$results['failed'];
				} else {
					++$results['success'];
				}
			}

			wp_send_json_success(
				sprintf(
					__( 'Synced %d of %d feeds. %d failed.', 'mpcal-ics-sync' ),
					$results['success'],
					$results['total'],
					$results['failed']
				)
			);
		}

		public function ajax_add_feed(): void {
			check_ajax_referer( 'mpcal_sync_ajax' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Permission denied.', 'mpcal-ics-sync' ) );
			}

			$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

			if ( empty( $name ) || empty( $url ) ) {
				wp_send_json_error( __( 'Name and URL are required.', 'mpcal-ics-sync' ) );
			}

			$validation = $this->validate_url( $url );
			if ( is_wp_error( $validation ) ) {
				wp_send_json_error( $validation->get_error_message() );
			}

			$feeds   = $this->get_feeds();
			$feed_id = 'feed_' . wp_generate_password( 8, false );

			$feeds[ $feed_id ] = array(
				'name'          => $name,
				'url'           => $url,
				'calendar_id'   => isset( $_POST['calendar_id'] ) ? absint( $_POST['calendar_id'] ) : 0,
				'update_mode'   => isset( $_POST['update_mode'] ) ? sanitize_key( $_POST['update_mode'] ) : 'yes',
				'sync_interval' => isset( $_POST['sync_interval'] ) ? sanitize_key( $_POST['sync_interval'] ) : 'hourly',
				'enabled'       => ! empty( $_POST['enabled'] ),
				'last_sync'     => 0,
				'last_status'   => '',
				'last_message'  => '',
			);

			$this->save_feeds( $feeds );

			wp_send_json_success( __( 'Feed added successfully.', 'mpcal-ics-sync' ) );
		}

		public function ajax_update_feed(): void {
			check_ajax_referer( 'mpcal_sync_ajax' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Permission denied.', 'mpcal-ics-sync' ) );
			}

			$feed_id = isset( $_POST['feed_id'] ) ? sanitize_key( $_POST['feed_id'] ) : '';
			$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

			if ( empty( $feed_id ) || empty( $name ) || empty( $url ) ) {
				wp_send_json_error( __( 'Feed ID, name, and URL are required.', 'mpcal-ics-sync' ) );
			}

			$feeds = $this->get_feeds();

			if ( ! isset( $feeds[ $feed_id ] ) ) {
				wp_send_json_error( __( 'Feed not found.', 'mpcal-ics-sync' ) );
			}

			$validation = $this->validate_url( $url );
			if ( is_wp_error( $validation ) ) {
				wp_send_json_error( $validation->get_error_message() );
			}

			$feeds[ $feed_id ] = array_merge(
				$feeds[ $feed_id ],
				array(
					'name'          => $name,
					'url'           => $url,
					'calendar_id'   => isset( $_POST['calendar_id'] ) ? absint( $_POST['calendar_id'] ) : 0,
					'update_mode'   => isset( $_POST['update_mode'] ) ? sanitize_key( $_POST['update_mode'] ) : 'yes',
					'sync_interval' => isset( $_POST['sync_interval'] ) ? sanitize_key( $_POST['sync_interval'] ) : 'hourly',
					'enabled'       => ! empty( $_POST['enabled'] ),
				)
			);

			$this->save_feeds( $feeds );

			wp_send_json_success( __( 'Feed updated successfully.', 'mpcal-ics-sync' ) );
		}

		public function ajax_delete_feed(): void {
			check_ajax_referer( 'mpcal_sync_ajax' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Permission denied.', 'mpcal-ics-sync' ) );
			}

			$feed_id = isset( $_POST['feed_id'] ) ? sanitize_key( $_POST['feed_id'] ) : '';

			if ( empty( $feed_id ) ) {
				wp_send_json_error( __( 'Feed ID is required.', 'mpcal-ics-sync' ) );
			}

			$feeds = $this->get_feeds();

			if ( ! isset( $feeds[ $feed_id ] ) ) {
				wp_send_json_error( __( 'Feed not found.', 'mpcal-ics-sync' ) );
			}

			unset( $feeds[ $feed_id ] );
			$this->save_feeds( $feeds );

			wp_send_json_success( __( 'Feed deleted successfully.', 'mpcal-ics-sync' ) );
		}

		// ==================== ADMIN PAGE ====================

		public function render_admin_page(): void {
			$feeds     = $this->get_feeds();
			$calendars = $this->get_calendars();

			$intervals = array(
				'every_15_minutes' => __( 'Every 15 Minutes', 'mpcal-ics-sync' ),
				'every_30_minutes' => __( 'Every 30 Minutes', 'mpcal-ics-sync' ),
				'hourly'           => __( 'Hourly', 'mpcal-ics-sync' ),
				'twicedaily'       => __( 'Twice Daily', 'mpcal-ics-sync' ),
				'daily'            => __( 'Daily', 'mpcal-ics-sync' ),
			);

			$update_modes = array(
				'yes' => __( 'Yes - Update existing events', 'mpcal-ics-sync' ),
				'no'  => __( 'No - Skip existing events', 'mpcal-ics-sync' ),
			);

			$nonce = wp_create_nonce( 'mpcal_sync_ajax' );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'ICS Feed Sync', 'mpcal-ics-sync' ); ?></h1>

				<h2><?php esc_html_e( 'Configured Feeds', 'mpcal-ics-sync' ); ?></h2>

				<?php if ( empty( $feeds ) ) : ?>
					<p class="description"><?php esc_html_e( 'No feeds configured yet. Add one below.', 'mpcal-ics-sync' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped" id="mpcal-feeds-table">
						<thead>
							<tr>
								<th style="width: 20%;"><?php esc_html_e( 'Name', 'mpcal-ics-sync' ); ?></th>
								<th style="width: 30%;"><?php esc_html_e( 'URL', 'mpcal-ics-sync' ); ?></th>
								<th style="width: 12%;"><?php esc_html_e( 'Calendar', 'mpcal-ics-sync' ); ?></th>
								<th style="width: 12%;"><?php esc_html_e( 'Last Sync', 'mpcal-ics-sync' ); ?></th>
								<th style="width: 10%;"><?php esc_html_e( 'Status', 'mpcal-ics-sync' ); ?></th>
								<th style="width: 16%;"><?php esc_html_e( 'Actions', 'mpcal-ics-sync' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $feeds as $feed_id => $feed ) : ?>
								<tr data-feed-id="<?php echo esc_attr( $feed_id ); ?>">
									<td>
										<strong><?php echo esc_html( $feed['name'] ); ?></strong>
										<?php if ( empty( $feed['enabled'] ) ) : ?>
											<br><em class="description">(Disabled)</em>
										<?php endif; ?>
									</td>
									<td><code style="font-size: 11px; word-break: break-all;"><?php echo esc_html( substr( $feed['url'], 0, 60 ) . ( strlen( $feed['url'] ) > 60 ? '...' : '' ) ); ?></code></td>
									<td><?php echo esc_html( $calendars[ $feed['calendar_id'] ] ?? 'Auto' ); ?></td>
									<td>
										<?php
										if ( ! empty( $feed['last_sync'] ) ) {
											echo esc_html( human_time_diff( $feed['last_sync'], time() ) . ' ago' );
										} else {
											esc_html_e( 'Never', 'mpcal-ics-sync' );
										}
										?>
									</td>
									<td>
										<?php
										$status_class = 'success' === $feed['last_status'] ? 'dashicons-yes-alt' : ( 'error' === $feed['last_status'] ? 'dashicons-warning' : 'dashicons-minus' );
										$status_color = 'success' === $feed['last_status'] ? '#46b450' : ( 'error' === $feed['last_status'] ? '#dc3232' : '#999' );
										?>
										<span class="dashicons <?php echo esc_attr( $status_class ); ?>" style="color: <?php echo esc_attr( $status_color ); ?>;" title="<?php echo esc_attr( $feed['last_message'] ?? '' ); ?>"></span>
									</td>
									<td>
										<button type="button" class="button button-small sync-feed-btn" data-feed-id="<?php echo esc_attr( $feed_id ); ?>">Sync</button>
										<button type="button" class="button button-small edit-feed-btn" data-feed-id="<?php echo esc_attr( $feed_id ); ?>">Edit</button>
										<button type="button" class="button button-small button-link-delete delete-feed-btn" data-feed-id="<?php echo esc_attr( $feed_id ); ?>">Delete</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top: 15px;">
						<button type="button" id="sync-all-feeds" class="button button-secondary">
							<span class="dashicons dashicons-update" style="margin-top: 4px;"></span>
							<?php esc_html_e( 'Sync All Feeds', 'mpcal-ics-sync' ); ?>
						</button>
					</p>
				<?php endif; ?>

				<h2 id="form-title"><?php esc_html_e( 'Add New Feed', 'mpcal-ics-sync' ); ?></h2>

				<form id="mpcal-feed-form">
					<input type="hidden" name="feed_id" id="feed_id" value="">

					<table class="form-table">
						<tr>
							<th><label for="feed_name"><?php esc_html_e( 'Feed Name', 'mpcal-ics-sync' ); ?></label></th>
							<td>
								<input type="text" name="feed_name" id="feed_name" class="regular-text" required>
								<p class="description"><?php esc_html_e( 'A descriptive name for this feed.', 'mpcal-ics-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="feed_url"><?php esc_html_e( 'ICS Feed URL', 'mpcal-ics-sync' ); ?></label></th>
							<td>
								<input type="url" name="feed_url" id="feed_url" class="large-text" required placeholder="https://...">
								<p class="description"><?php esc_html_e( 'HTTPS URL to the ICS feed.', 'mpcal-ics-sync' ); ?></p>
								<button type="button" id="test-feed-url" class="button button-secondary" style="margin-top: 5px;">Test URL</button>
								<span id="test-result" style="margin-left: 10px;"></span>
							</td>
						</tr>
						<tr>
							<th><label for="feed_calendar_id"><?php esc_html_e( 'Target Calendar', 'mpcal-ics-sync' ); ?></label></th>
							<td>
								<select name="feed_calendar_id" id="feed_calendar_id">
									<?php foreach ( $calendars as $id => $name ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="feed_update_mode"><?php esc_html_e( 'Update Mode', 'mpcal-ics-sync' ); ?></label></th>
							<td>
								<select name="feed_update_mode" id="feed_update_mode">
									<?php foreach ( $update_modes as $mode => $label ) : ?>
										<option value="<?php echo esc_attr( $mode ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="feed_sync_interval"><?php esc_html_e( 'Sync Interval', 'mpcal-ics-sync' ); ?></label></th>
							<td>
								<select name="feed_sync_interval" id="feed_sync_interval">
									<?php foreach ( $intervals as $interval => $label ) : ?>
										<option value="<?php echo esc_attr( $interval ); ?>" <?php selected( $interval, 'hourly' ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Enabled', 'mpcal-ics-sync' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="feed_enabled" id="feed_enabled" value="1" checked>
									<?php esc_html_e( 'Enable automatic syncing', 'mpcal-ics-sync' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" id="save-feed" class="button button-primary"><?php esc_html_e( 'Add Feed', 'mpcal-ics-sync' ); ?></button>
						<button type="button" id="cancel-edit" class="button" style="display: none;"><?php esc_html_e( 'Cancel', 'mpcal-ics-sync' ); ?></button>
					</p>
				</form>

				<div id="sync-results" style="margin-top: 20px;"></div>

				<script>
				jQuery(function($) {
					var nonce = '<?php echo esc_js( $nonce ); ?>';
					var feeds = <?php echo wp_json_encode( $feeds ); ?>;

					$('#test-feed-url').on('click', function() {
						var url = $('#feed_url').val();
						if (!url) { $('#test-result').html('<span style="color:#dc3232;">Enter URL first</span>'); return; }
						$(this).prop('disabled', true).text('Testing...');
						$('#test-result').html('<span class="spinner" style="visibility:visible;float:none;"></span>');
						$.post(ajaxurl, { action: 'mpcal_sync_test_feed', _wpnonce: nonce, url: url }, function(r) {
							$('#test-feed-url').prop('disabled', false).text('Test URL');
							$('#test-result').html('<span style="color:' + (r.success ? '#46b450' : '#dc3232') + ';">' + r.data + '</span>');
						});
					});

					$('#mpcal-feed-form').on('submit', function(e) {
						e.preventDefault();
						var feedId = $('#feed_id').val();
						var action = feedId ? 'mpcal_sync_update_feed' : 'mpcal_sync_add_feed';
						$('#save-feed').prop('disabled', true).text(feedId ? 'Updating...' : 'Adding...');
						$.post(ajaxurl, {
							action: action, _wpnonce: nonce, feed_id: feedId,
							name: $('#feed_name').val(), url: $('#feed_url').val(),
							calendar_id: $('#feed_calendar_id').val(), update_mode: $('#feed_update_mode').val(),
							sync_interval: $('#feed_sync_interval').val(), enabled: $('#feed_enabled').is(':checked') ? 1 : 0
						}, function(r) {
							$('#save-feed').prop('disabled', false).text(feedId ? 'Update Feed' : 'Add Feed');
							if (r.success) { location.reload(); } else { alert(r.data); }
						});
					});

					$(document).on('click', '.edit-feed-btn', function() {
						var id = $(this).data('feed-id'), f = feeds[id];
						$('#feed_id').val(id);
						$('#feed_name').val(f.name);
						$('#feed_url').val(f.url);
						$('#feed_calendar_id').val(f.calendar_id);
						$('#feed_update_mode').val(f.update_mode);
						$('#feed_sync_interval').val(f.sync_interval);
						$('#feed_enabled').prop('checked', f.enabled);
						$('#form-title').text('Edit Feed');
						$('#save-feed').text('Update Feed');
						$('#cancel-edit').show();
						$('html,body').animate({scrollTop: $('#form-title').offset().top - 50}, 300);
					});

					$('#cancel-edit').on('click', function() {
						$('#feed_id').val('');
						$('#mpcal-feed-form')[0].reset();
						$('#feed_enabled').prop('checked', true);
						$('#form-title').text('Add New Feed');
						$('#save-feed').text('Add Feed');
						$(this).hide();
					});

					$(document).on('click', '.delete-feed-btn', function() {
						if (!confirm('Delete this feed?')) return;
						var btn = $(this), id = btn.data('feed-id');
						btn.prop('disabled', true).text('...');
						$.post(ajaxurl, { action: 'mpcal_sync_delete_feed', _wpnonce: nonce, feed_id: id }, function(r) {
							if (r.success) { btn.closest('tr').fadeOut(300, function(){ $(this).remove(); }); }
							else { btn.prop('disabled', false).text('Delete'); alert(r.data); }
						});
					});

					$(document).on('click', '.sync-feed-btn', function() {
						var btn = $(this), row = btn.closest('tr'), id = btn.data('feed-id');
						btn.prop('disabled', true).text('Syncing...');
						$.ajax({ url: ajaxurl, type: 'POST', timeout: 300000,
							data: { action: 'mpcal_sync_now', _wpnonce: nonce, feed_id: id },
							success: function(r) {
								btn.prop('disabled', false).text('Sync');
								$('#sync-results').html('<div class="notice notice-' + (r.success ? 'success' : 'error') + '"><p>' + r.data + '</p></div>');
								if (r.success) { row.find('td:eq(3)').text('Just now'); row.find('td:eq(4)').html('<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>'); }
							},
							error: function() { btn.prop('disabled', false).text('Sync'); alert('Sync failed'); }
						});
					});

					$('#sync-all-feeds').on('click', function() {
						var btn = $(this);
						btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Syncing...');
						$.post(ajaxurl, { action: 'mpcal_sync_all', _wpnonce: nonce }, function(r) {
							btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:4px;"></span> Sync All Feeds');
							$('#sync-results').html('<div class="notice notice-' + (r.success ? 'success' : 'error') + '"><p>' + r.data + '</p></div>');
							if (r.success) location.reload();
						});
					});
				});
				</script>
				<style>.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }</style>
			</div>
			<?php
		}

		// ==================== ACTIVATION / DEACTIVATION ====================

		private static function activate_plugin( bool $is_network_wide = false ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( is_multisite() && $is_network_wide ) {
				$sites = get_sites();
				foreach ( $sites as $site ) {
					switch_to_blog( $site->blog_id );
					self::do_on_plugin_activation();
					restore_current_blog();
				}
			} else {
				self::do_on_plugin_activation();
			}
		}

		private static function do_on_plugin_activation(): void {
			if ( get_option( self::OPTION_NAME_IS_PLUGIN_ACTIVATED, false ) ) {
				return;
			}

			// Schedule cron
			if ( ! wp_next_scheduled( 'mpcal_ics_sync_cron' ) ) {
				wp_schedule_event( time(), 'hourly', 'mpcal_ics_sync_cron' );
			}

			update_option( self::OPTION_NAME_IS_PLUGIN_ACTIVATED, true, false );
		}

		private static function deactivate_plugin( bool $is_network_wide = false ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( is_multisite() && $is_network_wide ) {
				$sites = get_sites();
				foreach ( $sites as $site ) {
					switch_to_blog( $site->blog_id );
					self::do_on_plugin_deactivation();
					restore_current_blog();
				}
			} else {
				self::do_on_plugin_deactivation();
			}
		}

		private static function do_on_plugin_deactivation(): void {
			wp_clear_scheduled_hook( 'mpcal_ics_sync_cron' );
			update_option( self::OPTION_NAME_IS_PLUGIN_ACTIVATED, false, false );
		}
	}

	// Initialize
	ICS_Sync_Addon::get_instance();
}
