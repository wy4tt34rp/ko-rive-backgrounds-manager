<?php
/**
 * Plugin Name: KO – Rive Manager
 * Description: Manages reusable Rive backgrounds, heroes, and page loaders through one library and one shared runtime.
 * Version: 3.0.15
 * Author: KO
 * Author URI: mailto:6822858@kevinoneill.us
 * License: GPL-2.0-or-later
 * Text Domain: ko-rive-backgrounds-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KO_Rive_Backgrounds_Manager {
	const VERSION                  = '3.0.15';
	const MANAGE_CAPABILITY        = 'edit_pages';
	const LIBRARY_OPTION           = 'ko_rive_library_v3';
	const SETTINGS_OPTION          = 'ko_rive_manager_settings_v3';
	const MIGRATION_OPTION         = 'ko_rive_manager_migration_v3';
	const LEGACY_BACKGROUND_OPTION = 'ko_rbm_backgrounds';
	const LEGACY_HERO_OPTION       = 'ko_rh_hero';
	const LEGACY_HERO_CODE_OPTION  = 'ko_rh_animation_code';
	const LEGACY_LOADER_OPTION     = 'ko_rpl_settings';
	const REPAIR_OPTION            = 'ko_rive_manager_repair_v301';
	const MENU_SLUG                = 'ko-rive-backgrounds-manager';
	const SHORTCODE                = 'ko_rive';
	const LEGACY_SHORTCODE         = 'ko_rive_background';
	const UPLOAD_DIRECTORY         = 'ko-rive-backgrounds-manager';
	const RUNTIME_VERSION          = '2.38.5';
	const NAVIGATION_HANDOFF_KEY   = 'koRiveLoaderNavigationHandoff';

	private $assets_enqueued = false;
	private $loader_rendered = false;

	public function __construct() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate' ), 20 );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_repair_v301' ), 21 );
		add_action( 'init', array( $this, 'register_shortcodes' ), 20 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'admin_post_ko_rive_save_item', array( $this, 'handle_save_item' ) );
		add_action( 'admin_post_ko_rive_delete_item', array( $this, 'handle_delete_item' ) );
		add_action( 'admin_post_ko_rive_reimport_legacy', array( $this, 'handle_reimport_legacy' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 1 );
		add_action( 'wp_head', array( $this, 'render_loader_bootstrap' ), 0 );
		add_action( 'wp_head', array( $this, 'render_preload_links' ), 2 );
		add_action( 'wp_body_open', array( $this, 'render_automatic_loader' ), 1 );
		add_action( 'wp_footer', array( $this, 'render_automatic_loader_fallback' ), 1 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
	}

	public static function activate() {
		self::maybe_migrate();
		self::maybe_repair_v301();
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, array( 'schema' => 1 ), '', false );
		}
	}

	public static function maybe_migrate() {
		if ( false !== get_option( self::LIBRARY_OPTION, false ) ) {
			return;
		}

		$sources = self::legacy_sources();
		$library = self::build_legacy_library( $sources );
		add_option( self::LIBRARY_OPTION, $library, '', false );
		add_option(
			self::MIGRATION_OPTION,
			array(
				'migrated_at' => current_time( 'mysql' ),
				'sources'     => $sources,
				'counts'      => self::legacy_counts( $sources ),
			),
			'',
			false
		);
	}

	public static function maybe_repair_v301() {
		$completed = get_option( self::REPAIR_OPTION, false );
		if ( is_array( $completed ) && version_compare( (string) ( $completed['version'] ?? '0' ), '3.0.1', '>=' ) ) {
			return;
		}

		$library = self::library();
		$repaired = 0;
		$library = self::repair_library_from_legacy( $library, self::legacy_sources(), $repaired );
		if ( $repaired ) {
			update_option( self::LIBRARY_OPTION, $library, false );
		}
		update_option(
			self::REPAIR_OPTION,
			array(
				'version'     => '3.0.1',
				'repaired_at' => current_time( 'mysql' ),
				'items'       => $repaired,
			),
			false
		);
	}

	private static function repair_library_from_legacy( $library, $sources, &$repaired = 0 ) {
		$library = is_array( $library ) ? $library : array();
		$legacy_hero = self::normalized_legacy_hero( $sources['hero'] ?? false, $sources['hero_code'] ?? '' );
		$legacy_loader = is_array( $sources['loader'] ?? false ) ? $sources['loader'] : array();

		foreach ( $library as $id => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$item = array_merge( self::item_defaults(), $raw );
			$changed = false;

			if ( 'hero' === $item['role'] && self::legacy_hero_is_meaningful( $legacy_hero ) && self::item_matches_legacy_source( $item, $legacy_hero, 'link' ) ) {
				if ( '' === $item['artboard'] && ! empty( $legacy_hero['artboard'] ) ) {
					$item['artboard'] = sanitize_text_field( $legacy_hero['artboard'] );
					$changed = true;
				}
				if ( '' === $item['animation'] && '' === $item['state_machine'] && ( ! empty( $legacy_hero['animation'] ) || ! empty( $legacy_hero['state_machine'] ) ) ) {
					$item['animation'] = sanitize_text_field( $legacy_hero['animation'] ?? '' );
					$item['state_machine'] = sanitize_text_field( $legacy_hero['state_machine'] ?? '' );
					$changed = true;
				}
			}

			if ( 'loader' === $item['role'] && self::legacy_loader_is_meaningful( $legacy_loader ) && self::item_matches_legacy_source( $item, $legacy_loader, 'rive_url' ) ) {
				if ( '' === $item['artboard'] && ! empty( $legacy_loader['artboard'] ) ) {
					$item['artboard'] = sanitize_text_field( $legacy_loader['artboard'] );
					$changed = true;
				}
				if ( '' === $item['animation'] && '' === $item['state_machine'] && ! empty( $legacy_loader['state_machine'] ) ) {
					$item['state_machine'] = sanitize_text_field( $legacy_loader['state_machine'] );
					$changed = true;
				}
				if ( '' === $item['loader_completion_event'] && ! empty( $legacy_loader['completion_event'] ) ) {
					$item['loader_completion_event'] = sanitize_text_field( $legacy_loader['completion_event'] );
					$changed = true;
				}
			}

			if ( $changed ) {
				$library[ $id ] = $item;
				$repaired++;
			}
		}
		return $library;
	}

	private static function item_matches_legacy_source( $item, $legacy, $legacy_url_key ) {
		$item_url = esc_url_raw( $item['link'] ?: $item['external_url'] );
		$legacy_url = esc_url_raw( $legacy[ $legacy_url_key ] ?? '' );
		if ( '' !== $item_url && '' !== $legacy_url && $item_url === $legacy_url ) {
			return true;
		}
		$item_hash = sanitize_text_field( $item['hash'] ?? '' );
		$legacy_hash = sanitize_text_field( $legacy['hash'] ?? '' );
		if ( '' !== $item_hash && '' !== $legacy_hash && hash_equals( $legacy_hash, $item_hash ) ) {
			return true;
		}
		return ( 'hero' === ( $item['role'] ?? '' ) && 'legacy_hero' === ( $item['source_owner'] ?? '' ) ) || ( 'loader' === ( $item['role'] ?? '' ) && 'legacy_loader' === ( $item['source_owner'] ?? '' ) );
	}

	public function register_shortcodes() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_shortcode( self::LEGACY_SHORTCODE, array( $this, 'render_legacy_background_shortcode' ) );
		if ( ! shortcode_exists( 'ko_rive_hero' ) ) {
			add_shortcode( 'ko_rive_hero', array( $this, 'render_legacy_hero_shortcode' ) );
		}
	}

	public function admin_menu() {
		add_menu_page(
			__( 'KO – Rive Manager', 'ko-rive-backgrounds-manager' ),
			__( 'Rive Manager', 'ko-rive-backgrounds-manager' ),
			self::MANAGE_CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_admin_page' ),
			'dashicons-format-image',
			58
		);
	}

	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::admin_page_url() ) . '">' . esc_html__( 'Manage Rive Library', 'ko-rive-backgrounds-manager' ) . '</a>' );
		return $links;
	}

	public function admin_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ko-rive-manager-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), self::VERSION );
		wp_enqueue_script( 'ko-rive-manager-admin', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', array(), self::VERSION, true );
	}

	private static function library() {
		$library = get_option( self::LIBRARY_OPTION, array() );
		return is_array( $library ) ? $library : array();
	}

	private static function admin_page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function legacy_sources() {
		return array(
			'backgrounds' => get_option( self::LEGACY_BACKGROUND_OPTION, false ),
			'hero'        => get_option( self::LEGACY_HERO_OPTION, false ),
			'hero_code'   => get_option( self::LEGACY_HERO_CODE_OPTION, '' ),
			'loader'      => get_option( self::LEGACY_LOADER_OPTION, false ),
		);
	}

	private static function legacy_counts( $sources ) {
		$hero = self::normalized_legacy_hero( $sources['hero'] ?? false, $sources['hero_code'] ?? '' );
		return array(
			'backgrounds' => is_array( $sources['backgrounds'] ) ? count( $sources['backgrounds'] ) : 0,
			'hero'        => self::legacy_hero_is_meaningful( $hero ) ? 1 : 0,
			'loader'      => is_array( $sources['loader'] ) && self::legacy_loader_is_meaningful( $sources['loader'] ) ? 1 : 0,
		);
	}

	private static function build_legacy_library( $sources, $existing = array() ) {
		$library = is_array( $existing ) ? $existing : array();
		$backgrounds = is_array( $sources['backgrounds'] ) ? $sources['backgrounds'] : array();
		foreach ( $backgrounds as $legacy_key => $legacy_item ) {
			if ( ! is_array( $legacy_item ) ) {
				continue;
			}
			$id = self::legacy_background_class( $legacy_key );
			if ( isset( $library[ $id ] ) || self::library_has_alias( $library, $legacy_key ) ) {
				continue;
			}
			$aliases = array_values( array_unique( array_filter( array( sanitize_title( $legacy_key ), $id ) ) ) );
			$library[ $id ] = array_merge(
				self::item_defaults(),
				array(
					'name'          => sanitize_text_field( $legacy_item['name'] ?? $legacy_item['title'] ?? $id ),
					'role'          => 'background',
					'load_policy'   => 'preload',
					'link'          => esc_url_raw( $legacy_item['link'] ?? '' ),
					'file'          => sanitize_text_field( $legacy_item['file'] ?? '' ),
					'hash'          => sanitize_text_field( $legacy_item['hash'] ?? '' ),
					'title'         => sanitize_text_field( $legacy_item['title'] ?? '' ),
					'artboard'      => sanitize_text_field( $legacy_item['artboard'] ?? '' ),
					'animation'     => sanitize_text_field( $legacy_item['animation'] ?? '' ),
					'state_machine' => sanitize_text_field( $legacy_item['state_machine'] ?? '' ),
					'aliases'       => $aliases,
					'source_owner'  => 'legacy_background',
					'imported_at'   => sanitize_text_field( $legacy_item['imported_at'] ?? '' ),
				)
			);
		}

		$hero = self::normalized_legacy_hero( $sources['hero'] ?? false, $sources['hero_code'] ?? '' );
		if ( self::legacy_hero_is_meaningful( $hero ) && ! self::library_has_role_source( $library, 'hero', 'legacy_hero' ) ) {
			$id   = self::unique_id( 'rive-hero', $library );
			$hero_file = sanitize_text_field( $hero['file'] ?? '' );
			$use_external = ! preg_match( '#^ko-rive-hero/[A-Za-z0-9._-]+\.riv$#', ltrim( $hero_file, '/\\' ) );
			$library[ $id ] = array_merge(
				self::item_defaults(),
				array(
					'name'          => sanitize_text_field( $hero['title'] ?? __( 'Rive Hero', 'ko-rive-backgrounds-manager' ) ),
					'role'          => 'hero',
					'load_policy'   => 'preload',
					'link'          => esc_url_raw( $hero['link'] ?? '' ),
					'file'          => $use_external ? '' : $hero_file,
					'source_mode'   => $use_external ? 'external' : 'local',
					'external_url'  => $use_external ? esc_url_raw( $hero['link'] ?? '' ) : '',
					'hash'          => sanitize_text_field( $hero['hash'] ?? '' ),
					'title'         => sanitize_text_field( $hero['title'] ?? '' ),
					'artboard'      => sanitize_text_field( $hero['artboard'] ?? '' ),
					'animation'     => sanitize_text_field( $hero['animation'] ?? '' ),
					'state_machine' => sanitize_text_field( $hero['state_machine'] ?? '' ),
					'fit'           => 'layout',
					'height'        => '100svh',
					'min_height'    => '600px',
					'fallback_enabled'=> 1,
					'fallback_color'=> '#e5e2dc',
					'aliases'       => array( 'ko-rive-hero' ),
					'source_owner'  => 'legacy_hero',
					'imported_at'   => sanitize_text_field( $hero['imported_at'] ?? '' ),
				)
			);
		}

		if ( is_array( $sources['loader'] ) && self::legacy_loader_is_meaningful( $sources['loader'] ) && ! self::library_has_role_source( $library, 'loader', 'legacy_loader' ) ) {
			$loader = $sources['loader'];
			$id     = self::unique_id( 'rive-page-loader', $library );
			$library[ $id ] = array_merge(
				self::item_defaults(),
				array(
					'name'                    => __( 'Rive Page Loader', 'ko-rive-backgrounds-manager' ),
					'role'                    => 'loader',
					'load_policy'             => 'preload',
					'source_mode'             => 'external',
					'external_url'            => esc_url_raw( $loader['rive_url'] ?? '' ),
					'link'                    => esc_url_raw( $loader['rive_url'] ?? '' ),
					'attachment_id'           => absint( $loader['rive_attachment_id'] ?? 0 ),
					'artboard'                => sanitize_text_field( $loader['artboard'] ?? '' ),
					'state_machine'           => sanitize_text_field( $loader['state_machine'] ?? '' ),
					'fit'                     => self::sanitize_choice( $loader['fit'] ?? '', self::fits(), 'cover' ),
					'alignment'               => self::sanitize_choice( $loader['alignment'] ?? '', self::alignments(), 'center' ),
					'fallback_enabled'        => 1,
					'fallback_color'          => sanitize_hex_color( $loader['background_color'] ?? '' ) ?: '#ffffff',
					'loader_enabled'          => ! empty( $loader['enabled'] ) ? 1 : 0,
					'loader_display_mode'     => self::sanitize_choice( $loader['display_mode'] ?? '', array( 'everywhere', 'include_selected', 'exclude_selected' ), 'include_selected' ),
					'loader_page_ids'         => self::sanitize_id_array( $loader['page_ids'] ?? array() ),
					'loader_post_ids'         => self::sanitize_id_array( $loader['post_ids'] ?? array() ),
					'loader_category_ids'     => self::sanitize_id_array( $loader['category_ids'] ?? array() ),
					'loader_category_archives'=> ! empty( $loader['category_archives'] ) ? 1 : 0,
					'loader_posts_categories' => ! empty( $loader['posts_in_categories'] ) ? 1 : 0,
					'loader_front_page'       => ! empty( $loader['front_page'] ) ? 1 : 0,
					'loader_posts_page'       => ! empty( $loader['posts_page'] ) ? 1 : 0,
					'loader_search'           => ! empty( $loader['search_results'] ) ? 1 : 0,
					'loader_not_found'        => ! empty( $loader['not_found'] ) ? 1 : 0,
					'loader_frequency'        => self::sanitize_choice( $loader['frequency'] ?? '', array( 'every_load', 'session', 'daily' ), 'every_load' ),
					'loader_minimum'          => min( 10000, absint( $loader['minimum_duration'] ?? 500 ) ),
					'loader_maximum'          => min( 30000, max( 1000, absint( $loader['maximum_duration'] ?? 5000 ) ) ),
					'loader_fade'             => min( 3000, absint( $loader['fade_duration'] ?? 350 ) ),
					'loader_completion_event' => sanitize_text_field( $loader['completion_event'] ?? '' ),
					'loader_lock_scroll'      => ! empty( $loader['lock_scroll'] ) ? 1 : 0,
					'loader_reduced_motion'   => ! empty( $loader['respect_reduced_motion'] ) ? 1 : 0,
					'loader_aria_label'       => sanitize_text_field( $loader['aria_label'] ?? __( 'Loading page', 'ko-rive-backgrounds-manager' ) ),
					'source_owner'            => 'legacy_loader',
				)
			);
		}

		return $library;
	}

	private static function item_defaults() {
		return array(
			'name' => '', 'role' => 'background', 'load_policy' => 'lazy', 'source_mode' => 'local',
			'link' => '', 'external_url' => '', 'attachment_id' => 0, 'file' => '', 'hash' => '', 'title' => '',
			'artboard' => '', 'animation' => '', 'state_machine' => '', 'fit' => 'cover', 'alignment' => 'center',
			'fallback_enabled' => 0, 'fallback_color' => '#ffffff', 'poster_url' => '', 'height' => '100svh', 'min_height' => '600px',
			'aliases' => array(), 'source_owner' => 'unified', 'imported_at' => '',
			'loader_enabled' => 0, 'loader_priority' => 10, 'loader_display_mode' => 'include_selected',
			'loader_page_ids' => array(), 'loader_post_ids' => array(), 'loader_category_ids' => array(),
			'loader_category_archives' => 0, 'loader_posts_categories' => 0, 'loader_front_page' => 0,
			'loader_posts_page' => 0, 'loader_search' => 0, 'loader_not_found' => 0,
			'loader_frequency' => 'every_load', 'loader_grace' => 350, 'loader_minimum_visible' => 250,
			'loader_minimum' => 500, 'loader_maximum' => 5000,
			'loader_hold' => 150, 'loader_fade' => 350, 'loader_completion_event' => '', 'loader_lock_scroll' => 1,
			'loader_reduced_motion' => 1, 'loader_aria_label' => 'Loading page',
		);
	}

	private static function legacy_hero_is_meaningful( $hero ) {
		return is_array( $hero ) && ( ! empty( $hero['link'] ) || ( ! empty( $hero['file'] ) && 0 !== strpos( (string) $hero['file'], 'plugin://' ) ) );
	}

	private static function normalized_legacy_hero( $hero, $legacy_code = '' ) {
		$hero = is_array( $hero ) ? $hero : array();
		$parsed = self::parse_legacy_hero_code( $legacy_code );
		$hero_link = esc_url_raw( $hero['link'] ?? '' );
		$parsed_link = esc_url_raw( $parsed['link'] ?? '' );
		if ( '' === $hero_link && '' !== $parsed_link ) {
			$hero['link'] = $parsed_link;
			$hero_link = $parsed_link;
		}
		if ( '' !== $parsed_link && $hero_link === $parsed_link ) {
			if ( empty( $hero['artboard'] ) ) {
				$hero['artboard'] = $parsed['artboard'];
			}
			if ( empty( $hero['animation'] ) && empty( $hero['state_machine'] ) ) {
				$hero['animation'] = $parsed['animation'];
				$hero['state_machine'] = $parsed['state_machine'];
			}
		}
		return $hero;
	}

	private static function parse_legacy_hero_code( $code ) {
		$result = array( 'link' => '', 'artboard' => '', 'animation' => '', 'state_machine' => '' );
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return $result;
		}
		$decoded = json_decode( $code, true );
		if ( is_array( $decoded ) ) {
			$result['link'] = self::first_legacy_value( $decoded, array( 'src', 'fileUrl', 'riveUrl' ) );
			$result['artboard'] = self::first_legacy_value( $decoded, array( 'artboard' ) );
			$result['animation'] = self::first_legacy_value( $decoded, array( 'animation', 'animations' ) );
			$result['state_machine'] = self::first_legacy_value( $decoded, array( 'stateMachine', 'stateMachines', 'state_machine' ) );
		} else {
			$result['link'] = self::match_legacy_code_value( $code, array( 'fileUrl', 'riveUrl', 'src' ), true );
			$result['artboard'] = self::match_legacy_code_value( $code, array( 'artboard' ) );
			$result['animation'] = self::match_legacy_code_value( $code, array( 'animation', 'animations' ) );
			$result['state_machine'] = self::match_legacy_code_value( $code, array( 'stateMachine', 'stateMachines', 'state_machine' ) );
		}
		foreach ( $result as $key => $value ) {
			$result[ $key ] = 'link' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value );
		}
		return $result;
	}

	private static function first_legacy_value( $values, $keys ) {
		foreach ( $keys as $key ) {
			if ( ! isset( $values[ $key ] ) ) {
				continue;
			}
			$value = is_array( $values[ $key ] ) ? reset( $values[ $key ] ) : $values[ $key ];
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	private static function match_legacy_code_value( $code, $keys, $require_rive_url = false ) {
		foreach ( $keys as $key ) {
			$pattern = '#(?:["\']?' . preg_quote( $key, '#' ) . '["\']?)\s*[:=]\s*(?:\[\s*)?["\']([^"\']+)["\']#i';
			if ( ! preg_match_all( $pattern, $code, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $value ) {
				if ( ! $require_rive_url || self::is_supported_rive_link( $value ) ) {
					return $value;
				}
			}
		}
		return '';
	}

	private static function is_supported_rive_link( $link ) {
		$parts = wp_parse_url( trim( (string) $link ) );
		$host = strtolower( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '';
		if ( 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return false;
		}
		return (bool) preg_match( '#\.riv$#i', $path ) || ( in_array( $host, array( 'rive.app', 'www.rive.app' ), true ) && (bool) preg_match( '#^/s/[A-Za-z0-9_-]+(?:/|$)#', $path ) );
	}

	private static function legacy_loader_is_meaningful( $loader ) {
		return ! empty( $loader['rive_url'] ) || ! empty( $loader['rive_attachment_id'] );
	}

	private static function legacy_background_class( $key ) {
		$key = sanitize_title( (string) $key );
		return 0 === strpos( $key, 'rive-bg-' ) ? $key : 'rive-bg-' . $key;
	}

	private static function unique_id( $requested, $library ) {
		$base = sanitize_title( $requested );
		$id = $base;
		$index = 2;
		while ( isset( $library[ $id ] ) ) {
			$id = $base . '-' . $index;
			$index++;
		}
		return $id;
	}

	private static function library_has_alias( $library, $alias ) {
		$alias = sanitize_title( $alias );
		foreach ( $library as $id => $item ) {
			if ( $id === $alias || in_array( $alias, (array) ( $item['aliases'] ?? array() ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function library_has_role_source( $library, $role, $source ) {
		foreach ( $library as $item ) {
			if ( is_array( $item ) && $role === ( $item['role'] ?? '' ) && $source === ( $item['source_owner'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function find_item( $requested ) {
		$requested = sanitize_title( $requested );
		$library = self::library();
		if ( isset( $library[ $requested ] ) && is_array( $library[ $requested ] ) ) {
			return array( 'id' => $requested, 'item' => array_merge( self::item_defaults(), $library[ $requested ] ) );
		}
		foreach ( $library as $id => $item ) {
			if ( is_array( $item ) && in_array( $requested, (array) ( $item['aliases'] ?? array() ), true ) ) {
				return array( 'id' => $id, 'item' => array_merge( self::item_defaults(), $item ) );
			}
		}
		return null;
	}

	public function handle_save_item() {
		$this->require_admin_request();
		$original_id = isset( $_POST['original_id'] ) ? sanitize_title( wp_unslash( $_POST['original_id'] ) ) : '';
		$id          = isset( $_POST['item_id'] ) ? sanitize_title( wp_unslash( $_POST['item_id'] ) ) : '';
		$name        = isset( $_POST['item_name'] ) ? sanitize_text_field( wp_unslash( $_POST['item_name'] ) ) : '';
		$link        = isset( $_POST['rive_link'] ) ? esc_url_raw( trim( wp_unslash( $_POST['rive_link'] ) ) ) : '';
		$upload      = isset( $_FILES['rive_file'] ) && is_array( $_FILES['rive_file'] ) ? $_FILES['rive_file'] : array();
		$library     = self::library();
		$result      = self::save_item( $library, $original_id, $id, $name, $link, $_POST, $upload );
		$args        = array();
		if ( is_wp_error( $result ) ) {
			$args['ko_rive_notice']  = 'error';
			$args['ko_rive_message'] = $result->get_error_message();
			if ( $original_id ) {
				$args['edit'] = $original_id;
			}
		} else {
			$args['ko_rive_notice'] = 'saved';
		}
		wp_safe_redirect( self::admin_page_url( $args ) );
		exit;
	}

	private static function save_item( $library, $original_id, $id, $name, $link, $raw, $upload = array() ) {
		if ( '' === $name ) {
			return new WP_Error( 'ko_rive_missing_name', __( 'Enter a Library name.', 'ko-rive-backgrounds-manager' ) );
		}
		if ( '' === $id ) {
			return new WP_Error( 'ko_rive_missing_id', __( 'Assign a unique class and shortcode ID.', 'ko-rive-backgrounds-manager' ) );
		}
		if ( isset( $library[ $id ] ) && $id !== $original_id ) {
			return new WP_Error( 'ko_rive_duplicate_id', __( 'That class and shortcode ID is already assigned.', 'ko-rive-backgrounds-manager' ) );
		}
		$old = $original_id && isset( $library[ $original_id ] ) ? array_merge( self::item_defaults(), $library[ $original_id ] ) : self::item_defaults();
		$upload = is_array( $upload ) ? $upload : array();
		$upload_error = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
		if ( ! in_array( $upload_error, array( UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE ), true ) ) {
			return new WP_Error( 'ko_rive_upload_failed', __( 'The .riv upload failed. Check the server upload limit and try again.', 'ko-rive-backgrounds-manager' ) );
		}
		$has_upload = UPLOAD_ERR_OK === $upload_error;
		if ( ! $has_upload && '' === $link && '' === self::item_url( $old ) ) {
			return new WP_Error( 'ko_rive_missing_source', __( 'Enter a Rive URL or upload a .riv file.', 'ko-rive-backgrounds-manager' ) );
		}

		$role = self::sanitize_choice( $raw['item_role'] ?? '', array( 'background', 'hero', 'loader' ), 'background' );
		$source_mode = self::sanitize_choice( $raw['source_mode'] ?? '', array( 'local', 'external' ), 'local' );
		$fallback_color = trim( (string) ( $raw['fallback_color'] ?? '' ) );
		if ( '' !== $fallback_color && ! sanitize_hex_color( $fallback_color ) ) {
			return new WP_Error( 'ko_rive_invalid_fallback_color', __( 'Enter a valid fallback hex color, such as #e5e2dc or #fff.', 'ko-rive-backgrounds-manager' ) );
		}
		$item = array_merge( $old, self::sanitize_item_fields( $raw ) );
		$item['name'] = $name;
		$item['role'] = $role;
		$item['source_mode'] = $has_upload ? 'local' : $source_mode;
		if ( 'loader' === $role ) {
			$item['fallback_enabled'] = 1;
		}

		$source_changed = $has_upload || ( '' !== $link && ( $link !== (string) $old['link'] || $source_mode !== (string) $old['source_mode'] || '' === self::item_url( $old ) ) );
		if ( $source_changed ) {
			$source = $has_upload ? self::prepare_uploaded_source( $id, $upload ) : self::prepare_source( $id, $link, $source_mode );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$item = array_merge( $item, $source );
			$item['link'] = $has_upload ? '' : $link;
			$item['source_mode'] = $has_upload ? 'local' : $source_mode;
			$item['source_owner'] = 'unified';
			$item['imported_at'] = current_time( 'mysql' );
			if ( ! $has_upload && '' === $item['artboard'] ) {
				$item['artboard'] = $source['artboard'];
			}
			if ( ! $has_upload && '' === $item['animation'] && '' === $item['state_machine'] ) {
				$item['animation'] = $source['animation'];
				$item['state_machine'] = $source['state_machine'];
			}
		}

		$aliases = array_map( 'sanitize_title', (array) $old['aliases'] );
		if ( $original_id && $original_id !== $id ) {
			$aliases[] = $original_id;
		}
		$item['aliases'] = array_values( array_unique( array_filter( $aliases ) ) );
		if ( $item['loader_maximum'] <= $item['loader_grace'] ) {
			$item['loader_maximum'] = min( 30000, $item['loader_grace'] + 1000 );
		}

		if ( $original_id && $original_id !== $id ) {
			unset( $library[ $original_id ] );
		}
		$library[ $id ] = $item;
		update_option( self::LIBRARY_OPTION, $library, false );

		if ( $source_changed && 'unified' === ( $old['source_owner'] ?? '' ) && ! empty( $old['file'] ) && $old['file'] !== $item['file'] ) {
			self::delete_unified_file( $old['file'] );
		}
		return true;
	}

	private static function sanitize_item_fields( $raw ) {
		$color = sanitize_hex_color( $raw['fallback_color'] ?? '' );
		return array(
			'load_policy' => self::sanitize_choice( $raw['load_policy'] ?? '', array( 'preload', 'lazy' ), 'lazy' ),
			'artboard' => sanitize_text_field( wp_unslash( $raw['artboard'] ?? '' ) ),
			'animation' => sanitize_text_field( wp_unslash( $raw['animation'] ?? '' ) ),
			'state_machine' => sanitize_text_field( wp_unslash( $raw['state_machine'] ?? '' ) ),
			'fit' => self::sanitize_choice( $raw['fit'] ?? '', self::fits(), 'cover' ),
			'alignment' => self::sanitize_choice( $raw['alignment'] ?? '', self::alignments(), 'center' ),
			'fallback_enabled' => ! empty( $raw['fallback_enabled'] ) ? 1 : 0,
			'fallback_color' => $color ?: '#ffffff',
			'poster_url' => esc_url_raw( trim( wp_unslash( $raw['poster_url'] ?? '' ) ) ),
			'height' => self::sanitize_dimension( $raw['height'] ?? '', '100svh' ),
			'min_height' => self::sanitize_dimension( $raw['min_height'] ?? '', '600px' ),
			'loader_enabled' => ! empty( $raw['loader_enabled'] ) ? 1 : 0,
			'loader_priority' => max( 1, min( 999, absint( $raw['loader_priority'] ?? 10 ) ) ),
			'loader_display_mode' => self::sanitize_choice( $raw['loader_display_mode'] ?? '', array( 'everywhere', 'include_selected', 'exclude_selected' ), 'include_selected' ),
			'loader_page_ids' => self::sanitize_id_array( $raw['loader_page_ids'] ?? array() ),
			'loader_post_ids' => self::sanitize_id_array( $raw['loader_post_ids'] ?? array() ),
			'loader_category_ids' => self::sanitize_id_array( $raw['loader_category_ids'] ?? array() ),
			'loader_category_archives' => ! empty( $raw['loader_category_archives'] ) ? 1 : 0,
			'loader_posts_categories' => ! empty( $raw['loader_posts_categories'] ) ? 1 : 0,
			'loader_front_page' => ! empty( $raw['loader_front_page'] ) ? 1 : 0,
			'loader_posts_page' => ! empty( $raw['loader_posts_page'] ) ? 1 : 0,
			'loader_search' => ! empty( $raw['loader_search'] ) ? 1 : 0,
			'loader_not_found' => ! empty( $raw['loader_not_found'] ) ? 1 : 0,
			'loader_frequency' => self::sanitize_choice( $raw['loader_frequency'] ?? '', array( 'every_load', 'session', 'daily' ), 'every_load' ),
			'loader_grace' => min( 3000, absint( $raw['loader_grace'] ?? 350 ) ),
			'loader_minimum_visible' => min( 3000, absint( $raw['loader_minimum_visible'] ?? 250 ) ),
			'loader_maximum' => min( 30000, max( 1000, absint( $raw['loader_maximum'] ?? 5000 ) ) ),
			'loader_hold' => min( 3000, absint( $raw['loader_hold'] ?? 150 ) ),
			'loader_fade' => min( 3000, absint( $raw['loader_fade'] ?? 350 ) ),
			'loader_completion_event' => sanitize_text_field( wp_unslash( $raw['loader_completion_event'] ?? '' ) ),
			'loader_lock_scroll' => ! empty( $raw['loader_lock_scroll'] ) ? 1 : 0,
			'loader_reduced_motion' => ! empty( $raw['loader_reduced_motion'] ) ? 1 : 0,
			'loader_aria_label' => sanitize_text_field( wp_unslash( $raw['loader_aria_label'] ?? 'Loading page' ) ),
		);
	}

	private static function prepare_source( $id, $link, $source_mode ) {
		$metadata = self::resolve_rive_link( $link );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		if ( 'external' === $source_mode ) {
			return array(
				'external_url' => $metadata['file_url'], 'file' => '', 'hash' => '', 'attachment_id' => 0,
				'title' => $metadata['title'], 'artboard' => $metadata['artboard'],
				'animation' => $metadata['animation'], 'state_machine' => $metadata['state_machine'],
			);
		}

		$response = wp_safe_remote_get( $metadata['file_url'], array( 'timeout' => 30, 'redirection' => 3, 'limit_response_size' => 10 * 1024 * 1024, 'user-agent' => 'KO Rive Manager/' . self::VERSION . '; ' . home_url( '/' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'ko_rive_download_failed', __( 'The Rive file could not be downloaded. For a protected staging URL, choose “Use the URL directly.”', 'ko-rive-backgrounds-manager' ) );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) < 8 || 'RIVE' !== substr( $body, 0, 4 ) ) {
			return new WP_Error( 'ko_rive_invalid_file', __( 'The downloaded file is not a valid Rive animation.', 'ko-rive-backgrounds-manager' ) );
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'ko_rive_uploads_error', sprintf( __( 'WordPress uploads are unavailable: %s', 'ko-rive-backgrounds-manager' ), $uploads['error'] ) );
		}
		$directory = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIRECTORY;
		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'ko_rive_directory_error', __( 'WordPress could not create the Rive storage directory.', 'ko-rive-backgrounds-manager' ) );
		}
		$hash = hash( 'sha256', $body );
		$filename = sanitize_file_name( $id . '-' . substr( $hash, 0, 12 ) . '.riv' );
		$target = trailingslashit( $directory ) . $filename;
		if ( strlen( $body ) !== file_put_contents( $target, $body, LOCK_EX ) ) {
			return new WP_Error( 'ko_rive_write_error', __( 'WordPress could not save the imported Rive animation.', 'ko-rive-backgrounds-manager' ) );
		}
		return array(
			'external_url' => '', 'file' => self::UPLOAD_DIRECTORY . '/' . $filename, 'hash' => $hash, 'attachment_id' => 0,
			'title' => $metadata['title'], 'artboard' => $metadata['artboard'],
			'animation' => $metadata['animation'], 'state_machine' => $metadata['state_machine'],
		);
	}

	private static function prepare_uploaded_source( $id, $upload ) {
		$name = sanitize_file_name( wp_unslash( $upload['name'] ?? '' ) );
		$tmp_name = isset( $upload['tmp_name'] ) ? (string) $upload['tmp_name'] : '';
		$size = isset( $upload['size'] ) ? absint( $upload['size'] ) : 0;
		if ( 'riv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'ko_rive_upload_extension', __( 'Choose a file with a .riv extension.', 'ko-rive-backgrounds-manager' ) );
		}
		if ( $size < 8 || $size > 10 * 1024 * 1024 ) {
			return new WP_Error( 'ko_rive_upload_size', __( 'The .riv file must be between 8 bytes and 10 MB.', 'ko-rive-backgrounds-manager' ) );
		}
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'ko_rive_upload_untrusted', __( 'WordPress could not verify the uploaded .riv file.', 'ko-rive-backgrounds-manager' ) );
		}
		$body = file_get_contents( $tmp_name );
		if ( ! is_string( $body ) || strlen( $body ) !== $size || 'RIVE' !== substr( $body, 0, 4 ) ) {
			return new WP_Error( 'ko_rive_upload_invalid', __( 'The uploaded file is not a valid Rive animation.', 'ko-rive-backgrounds-manager' ) );
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'ko_rive_uploads_error', sprintf( __( 'WordPress uploads are unavailable: %s', 'ko-rive-backgrounds-manager' ), $uploads['error'] ) );
		}
		$directory = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIRECTORY;
		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'ko_rive_directory_error', __( 'WordPress could not create the Rive storage directory.', 'ko-rive-backgrounds-manager' ) );
		}
		$hash = hash( 'sha256', $body );
		$filename = sanitize_file_name( $id . '-' . substr( $hash, 0, 12 ) . '.riv' );
		$target = trailingslashit( $directory ) . $filename;
		if ( strlen( $body ) !== file_put_contents( $target, $body, LOCK_EX ) ) {
			return new WP_Error( 'ko_rive_write_error', __( 'WordPress could not save the uploaded Rive animation.', 'ko-rive-backgrounds-manager' ) );
		}
		return array(
			'external_url' => '', 'file' => self::UPLOAD_DIRECTORY . '/' . $filename, 'hash' => $hash, 'attachment_id' => 0,
			'title' => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
		);
	}

	private static function resolve_rive_link( $link ) {
		$link = trim( (string) $link );
		$parts = wp_parse_url( $link );
		$host = strtolower( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '';
		if ( 'https' === ( $parts['scheme'] ?? '' ) && preg_match( '#\.riv$#i', $path ) ) {
			return array(
				'file_url' => esc_url_raw( $link ), 'title' => sanitize_text_field( pathinfo( basename( $path ), PATHINFO_FILENAME ) ),
				'artboard' => '', 'animation' => '', 'state_machine' => '',
			);
		}
		if ( 'https' !== ( $parts['scheme'] ?? '' ) || ! in_array( $host, array( 'rive.app', 'www.rive.app' ), true ) || ! preg_match( '#^/s/[A-Za-z0-9_-]+(?:/|$)#', $path ) ) {
			return new WP_Error( 'ko_rive_bad_link', __( 'Paste an HTTPS .riv URL or a public Rive share link beginning with https://rive.app/s/.', 'ko-rive-backgrounds-manager' ) );
		}
		$response = wp_safe_remote_get( $link, array( 'timeout' => 20, 'redirection' => 3, 'limit_response_size' => 2 * 1024 * 1024, 'user-agent' => 'KO Rive Manager/' . self::VERSION . '; ' . home_url( '/' ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'ko_rive_share_failed', __( 'The Rive share page could not be reached. Confirm that it is public.', 'ko-rive-backgrounds-manager' ) );
		}
		$html = wp_remote_retrieve_body( $response );
		if ( ! is_string( $html ) || ! preg_match( '#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $match ) ) {
			return new WP_Error( 'ko_rive_metadata_missing', __( 'The Rive link did not contain importable share metadata.', 'ko-rive-backgrounds-manager' ) );
		}
		$data = json_decode( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
		$embed = $data['props']['pageProps']['embedUrl'] ?? null;
		if ( ! is_array( $embed ) || empty( $embed['fileUrl'] ) ) {
			return new WP_Error( 'ko_rive_metadata_invalid', __( 'The Rive metadata did not include an animation file.', 'ko-rive-backgrounds-manager' ) );
		}
		$file_url = esc_url_raw( (string) $embed['fileUrl'] );
		$file_parts = wp_parse_url( $file_url );
		if ( 'https' !== ( $file_parts['scheme'] ?? '' ) || 'public.rive.app' !== strtolower( $file_parts['host'] ?? '' ) || ! preg_match( '#\.riv$#i', $file_parts['path'] ?? '' ) ) {
			return new WP_Error( 'ko_rive_asset_rejected', __( 'The share page returned an unsupported animation URL.', 'ko-rive-backgrounds-manager' ) );
		}
		$playback = sanitize_text_field( $embed['animation'] ?? '' );
		$is_state = ! empty( $embed['isStateMachine'] );
		return array(
			'file_url' => $file_url, 'title' => sanitize_text_field( $embed['title'] ?? __( 'Imported Rive', 'ko-rive-backgrounds-manager' ) ),
			'artboard' => sanitize_text_field( $embed['artboard'] ?? '' ),
			'animation' => $is_state ? '' : $playback, 'state_machine' => $is_state ? $playback : '',
		);
	}

	public function handle_delete_item() {
		$this->require_admin_request();
		$id = isset( $_POST['item_id'] ) ? sanitize_title( wp_unslash( $_POST['item_id'] ) ) : '';
		$library = self::library();
		if ( ! isset( $library[ $id ] ) ) {
			$this->redirect_notice( 'error', __( 'That Library item no longer exists.', 'ko-rive-backgrounds-manager' ) );
		}
		$item = $library[ $id ];
		unset( $library[ $id ] );
		update_option( self::LIBRARY_OPTION, $library, false );
		if ( 'unified' === ( $item['source_owner'] ?? '' ) ) {
			self::delete_unified_file( $item['file'] ?? '' );
		}
		$this->redirect_notice( 'deleted' );
	}

	public function handle_reimport_legacy() {
		$this->require_admin_request();
		$sources = self::legacy_sources();
		$library = self::build_legacy_library( $sources, self::library() );
		$repaired = 0;
		$library = self::repair_library_from_legacy( $library, $sources, $repaired );
		update_option( self::LIBRARY_OPTION, $library, false );
		$this->redirect_notice( 'reimported' );
	}

	private function require_admin_request() {
		if ( ! current_user_can( self::MANAGE_CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the Rive Library.', 'ko-rive-backgrounds-manager' ) );
		}
		check_admin_referer( 'ko_rive_manage', 'ko_rive_nonce' );
	}

	private function redirect_notice( $notice, $message = '' ) {
		$args = array( 'ko_rive_notice' => $notice );
		if ( $message ) {
			$args['ko_rive_message'] = $message;
		}
		wp_safe_redirect( self::admin_page_url( $args ) );
		exit;
	}

	public function render_admin_page() {
		if ( ! current_user_can( self::MANAGE_CAPABILITY ) ) {
			return;
		}
		$library = self::library();
		$edit_id = isset( $_GET['edit'] ) ? sanitize_title( wp_unslash( $_GET['edit'] ) ) : '';
		$editing = $edit_id && isset( $library[ $edit_id ] ) ? array_merge( self::item_defaults(), $library[ $edit_id ] ) : self::item_defaults();
		$notice = isset( $_GET['ko_rive_notice'] ) ? sanitize_key( wp_unslash( $_GET['ko_rive_notice'] ) ) : '';
		$notices = array(
			'saved' => __( 'Rive Library item saved.', 'ko-rive-backgrounds-manager' ),
			'deleted' => __( 'Rive Library item removed. Original legacy data was not changed.', 'ko-rive-backgrounds-manager' ),
			'reimported' => __( 'Missing items and missing metadata were copied from the original plugins. Existing Library values were not overwritten.', 'ko-rive-backgrounds-manager' ),
		);
		$notice_text = 'error' === $notice ? sanitize_text_field( wp_unslash( $_GET['ko_rive_message'] ?? '' ) ) : ( $notices[ $notice ] ?? '' );
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
		$categories = get_categories( array( 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		?>
		<div class="wrap ko-rbm-admin">
			<section class="ko-rbm-hero">
				<h1><?php esc_html_e( 'KO – Rive Manager', 'ko-rive-backgrounds-manager' ); ?></h1>
				<p><?php esc_html_e( 'One Library and one shared Rive runtime for reusable backgrounds, heroes, and page loaders.', 'ko-rive-backgrounds-manager' ); ?></p>
			</section>
			<?php if ( $notice_text ) : ?><div class="notice notice-<?php echo 'error' === $notice ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice_text ); ?></p></div><?php endif; ?>
			<div class="ko-rbm-safety">
				<div><strong><?php esc_html_e( 'Non-destructive migration', 'ko-rive-backgrounds-manager' ); ?></strong><span><?php esc_html_e( 'Your original Background Manager, Hero, and Page Loader settings and files remain untouched for rollback.', 'ko-rive-backgrounds-manager' ); ?></span></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ko_rive_manage', 'ko_rive_nonce' ); ?><input type="hidden" name="action" value="ko_rive_reimport_legacy"><button class="button" type="submit"><?php esc_html_e( 'Re-import / Repair Legacy Items', 'ko-rive-backgrounds-manager' ); ?></button></form>
			</div>
			<div class="ko-rbm-layout">
				<section class="ko-rbm-card ko-rbm-library-card">
					<h2><?php esc_html_e( 'Rive Library', 'ko-rive-backgrounds-manager' ); ?></h2>
					<?php if ( empty( $library ) ) : ?><div class="ko-rbm-empty"><strong><?php esc_html_e( 'No Rive files yet.', 'ko-rive-backgrounds-manager' ); ?></strong><br><?php esc_html_e( 'Add the first Library item using the editor.', 'ko-rive-backgrounds-manager' ); ?></div><?php else : ?>
					<div class="ko-rbm-library">
					<?php foreach ( $library as $id => $item ) : $item = array_merge( self::item_defaults(), $item ); $shortcode = '[' . self::SHORTCODE . ' id="' . $id . '"]'; ?>
						<article class="ko-rbm-item">
							<div class="ko-rbm-item-heading"><div><h3><?php echo esc_html( $item['name'] ?: $id ); ?></h3><p><?php echo esc_html( $item['title'] ?: __( 'Rive animation', 'ko-rive-backgrounds-manager' ) ); ?></p></div><div class="ko-rbm-badges"><span><?php echo esc_html( self::role_label( $item['role'] ) ); ?></span><span class="is-load"><?php echo esc_html( 'preload' === $item['load_policy'] ? __( 'Preload', 'ko-rive-backgrounds-manager' ) : __( 'Lazy Load', 'ko-rive-backgrounds-manager' ) ); ?></span><?php if ( 'loader' === $item['role'] ) : ?><span class="<?php echo $item['loader_enabled'] ? 'is-on' : 'is-off'; ?>"><?php echo esc_html( $item['loader_enabled'] ? __( 'Automatic On', 'ko-rive-backgrounds-manager' ) : __( 'Automatic Off', 'ko-rive-backgrounds-manager' ) ); ?></span><?php endif; ?></div></div>
							<p class="ko-rbm-meta"><strong><?php esc_html_e( 'Artboard:', 'ko-rive-backgrounds-manager' ); ?></strong> <?php echo esc_html( $item['artboard'] ?: __( 'Default', 'ko-rive-backgrounds-manager' ) ); ?> &middot; <strong><?php esc_html_e( 'Playback:', 'ko-rive-backgrounds-manager' ); ?></strong> <?php echo esc_html( $item['state_machine'] ?: ( $item['animation'] ?: __( 'Default', 'ko-rive-backgrounds-manager' ) ) ); ?></p>
							<?php if ( 'loader' === $item['role'] ) : ?><p class="ko-rbm-loader-status"><strong><?php esc_html_e( 'Automatic display:', 'ko-rive-backgrounds-manager' ); ?></strong> <?php echo esc_html( self::loader_target_label( $item ) ); ?></p><?php endif; ?>
							<div class="ko-rbm-uses"><div><strong><?php esc_html_e( 'Class:', 'ko-rive-backgrounds-manager' ); ?></strong><code><?php echo esc_html( $id ); ?></code><button type="button" class="button ko-rbm-copy" data-copy="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'ko-rive-backgrounds-manager' ); ?></button></div><div><strong><?php esc_html_e( 'Shortcode:', 'ko-rive-backgrounds-manager' ); ?></strong><code><?php echo esc_html( $shortcode ); ?></code><button type="button" class="button ko-rbm-copy" data-copy="<?php echo esc_attr( $shortcode ); ?>"><?php esc_html_e( 'Copy', 'ko-rive-backgrounds-manager' ); ?></button></div></div>
							<div class="ko-rbm-item-actions"><a class="button" href="<?php echo esc_url( self::admin_page_url( array( 'edit' => $id ) ) ); ?>"><?php esc_html_e( 'Edit', 'ko-rive-backgrounds-manager' ); ?></a><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ko-rbm-delete"><?php wp_nonce_field( 'ko_rive_manage', 'ko_rive_nonce' ); ?><input type="hidden" name="action" value="ko_rive_delete_item"><input type="hidden" name="item_id" value="<?php echo esc_attr( $id ); ?>"><button type="submit" class="button ko-rbm-delete-button" data-confirm="<?php esc_attr_e( 'Remove this item from the unified Library? Original legacy data remains available.', 'ko-rive-backgrounds-manager' ); ?>"><?php esc_html_e( 'Delete', 'ko-rive-backgrounds-manager' ); ?></button></form></div>
						</article>
					<?php endforeach; ?>
					</div><?php endif; ?>
				</section>
				<section class="ko-rbm-card ko-rbm-editor-card">
					<h2><?php echo esc_html( $edit_id ? __( 'Edit Library Item', 'ko-rive-backgrounds-manager' ) : __( 'Add a Rive File', 'ko-rive-backgrounds-manager' ) ); ?></h2>
					<p><?php esc_html_e( 'The assigned class is also the exact identifier used in this item’s unique shortcode.', 'ko-rive-backgrounds-manager' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ko-rbm-editor-form" enctype="multipart/form-data">
						<?php wp_nonce_field( 'ko_rive_manage', 'ko_rive_nonce' ); ?><input type="hidden" name="action" value="ko_rive_save_item"><input type="hidden" name="original_id" value="<?php echo esc_attr( $edit_id ); ?>">
						<label for="ko-rbm-name"><?php esc_html_e( 'Library name', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-name" name="item_name" type="text" value="<?php echo esc_attr( $editing['name'] ); ?>" placeholder="Yellow Navigation" required>
						<label for="ko-rbm-id"><?php esc_html_e( 'Class / shortcode ID', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-id" name="item_id" type="text" pattern="[a-z0-9-]+" value="<?php echo esc_attr( $edit_id ); ?>" placeholder="yellow-navigation" required><p class="description">[ko_rive id="<span data-ko-rive-id-preview><?php echo esc_html( $edit_id ?: 'yellow-navigation' ); ?></span>"]</p>
						<div class="ko-rbm-fields-2"><div><label for="ko-rbm-role"><?php esc_html_e( 'Use as', 'ko-rive-backgrounds-manager' ); ?></label><select id="ko-rbm-role" name="item_role"><option value="background" <?php selected( $editing['role'], 'background' ); ?>><?php esc_html_e( 'Background', 'ko-rive-backgrounds-manager' ); ?></option><option value="hero" <?php selected( $editing['role'], 'hero' ); ?>><?php esc_html_e( 'Hero', 'ko-rive-backgrounds-manager' ); ?></option><option value="loader" <?php selected( $editing['role'], 'loader' ); ?>><?php esc_html_e( 'Page Loader', 'ko-rive-backgrounds-manager' ); ?></option></select></div><div><label for="ko-rbm-load"><?php esc_html_e( 'Loading', 'ko-rive-backgrounds-manager' ); ?></label><select id="ko-rbm-load" name="load_policy"><option value="preload" <?php selected( $editing['load_policy'], 'preload' ); ?>><?php esc_html_e( 'Preload', 'ko-rive-backgrounds-manager' ); ?></option><option value="lazy" <?php selected( $editing['load_policy'], 'lazy' ); ?>><?php esc_html_e( 'Lazy Load', 'ko-rive-backgrounds-manager' ); ?></option></select></div></div>
						<label for="ko-rbm-link"><?php esc_html_e( 'Rive URL', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-link" name="rive_link" type="url" value="<?php echo esc_attr( $editing['link'] ?: $editing['external_url'] ); ?>" placeholder="https://rive.app/s/…/ or https://example.com/file.riv"><p class="description"><?php esc_html_e( 'Paste a Rive share link or direct .riv URL, or upload a file below. Leave unchanged while editing to keep the current source.', 'ko-rive-backgrounds-manager' ); ?></p>
						<label for="ko-rbm-source-mode"><?php esc_html_e( 'File handling', 'ko-rive-backgrounds-manager' ); ?></label><select id="ko-rbm-source-mode" name="source_mode"><option value="local" <?php selected( $editing['source_mode'], 'local' ); ?>><?php esc_html_e( 'Download and store a local copy', 'ko-rive-backgrounds-manager' ); ?></option><option value="external" <?php selected( $editing['source_mode'], 'external' ); ?>><?php esc_html_e( 'Use the URL directly', 'ko-rive-backgrounds-manager' ); ?></option></select><p class="description"><?php esc_html_e( 'Use the URL directly for an existing Media Library file or a password-protected staging URL.', 'ko-rive-backgrounds-manager' ); ?></p>
						<label for="ko-rbm-upload"><?php esc_html_e( 'Upload a .riv file', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-upload" name="rive_file" type="file" accept=".riv,application/octet-stream"><p class="description"><?php esc_html_e( 'Maximum 10 MB. An uploaded file takes precedence over the URL for this save and is stored locally.', 'ko-rive-backgrounds-manager' ); ?></p>
						<div class="ko-rbm-fields-2"><div><label for="ko-rbm-artboard"><?php esc_html_e( 'Artboard', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-artboard" name="artboard" type="text" value="<?php echo esc_attr( $editing['artboard'] ); ?>"></div><div><label for="ko-rbm-animation"><?php esc_html_e( 'Animation', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-animation" name="animation" type="text" value="<?php echo esc_attr( $editing['animation'] ); ?>"></div></div>
						<label for="ko-rbm-state"><?php esc_html_e( 'State machine', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-state" name="state_machine" type="text" value="<?php echo esc_attr( $editing['state_machine'] ); ?>">
						<div class="ko-rbm-fields-2"><div><label for="ko-rbm-fit"><?php esc_html_e( 'Fit', 'ko-rive-backgrounds-manager' ); ?></label><?php self::render_select( 'fit', $editing['fit'], array( 'layout' => 'Layout', 'cover' => 'Cover', 'contain' => 'Contain', 'fill' => 'Fill', 'fitWidth' => 'Fit Width', 'fitHeight' => 'Fit Height', 'scaleDown' => 'Scale Down', 'none' => 'None' ), 'ko-rbm-fit' ); ?></div><div><label for="ko-rbm-alignment"><?php esc_html_e( 'Alignment', 'ko-rive-backgrounds-manager' ); ?></label><?php self::render_select( 'alignment', $editing['alignment'], array( 'center' => 'Center', 'topLeft' => 'Top Left', 'topCenter' => 'Top Center', 'topRight' => 'Top Right', 'centerLeft' => 'Center Left', 'centerRight' => 'Center Right', 'bottomLeft' => 'Bottom Left', 'bottomCenter' => 'Bottom Center', 'bottomRight' => 'Bottom Right' ), 'ko-rbm-alignment' ); ?></div></div>
						<div class="ko-rbm-fields-2"><div><label for="ko-rbm-color"><?php esc_html_e( 'Fallback color', 'ko-rive-backgrounds-manager' ); ?></label><div class="ko-rbm-color-control"><span class="ko-rbm-color-swatch" data-ko-rive-color-swatch aria-hidden="true"></span><input id="ko-rbm-color" name="fallback_color" type="text" class="ko-rbm-color-field" pattern="#[0-9A-Fa-f]{3}(?:[0-9A-Fa-f]{3})?" value="<?php echo esc_attr( $editing['fallback_color'] ); ?>" placeholder="#e5e2dc" spellcheck="false"></div><p class="description"><?php esc_html_e( 'Enter a 3- or 6-digit hex value, including the #.', 'ko-rive-backgrounds-manager' ); ?></p><label class="ko-rbm-check"><input type="checkbox" name="fallback_enabled" value="1" <?php checked( $editing['fallback_enabled'] ); ?>> <?php esc_html_e( 'Paint this color before Rive is ready', 'ko-rive-backgrounds-manager' ); ?></label></div><div><label for="ko-rbm-poster"><?php esc_html_e( 'Poster / first-frame URL', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-poster" name="poster_url" type="url" value="<?php echo esc_attr( $editing['poster_url'] ); ?>"></div></div>
						<div data-role-panel="hero"><h3><?php esc_html_e( 'Hero layout', 'ko-rive-backgrounds-manager' ); ?></h3><div class="ko-rbm-fields-2"><div><label for="ko-rbm-height"><?php esc_html_e( 'Height', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-height" name="height" type="text" value="<?php echo esc_attr( $editing['height'] ); ?>"></div><div><label for="ko-rbm-min-height"><?php esc_html_e( 'Minimum height', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-min-height" name="min_height" type="text" value="<?php echo esc_attr( $editing['min_height'] ); ?>"></div></div></div>
						<div data-role-panel="loader"><h3><?php esc_html_e( 'Page Loader rules', 'ko-rive-backgrounds-manager' ); ?></h3><p class="description"><?php esc_html_e( 'When multiple loaders match, the lowest priority number runs. The original Page Loader plugin takes precedence while it remains active. To run on the homepage, enable the loader and choose Everywhere or select Front page below.', 'ko-rive-backgrounds-manager' ); ?></p>
							<label class="ko-rbm-check"><input type="checkbox" name="loader_enabled" value="1" <?php checked( $editing['loader_enabled'] ); ?>> <?php esc_html_e( 'Enable this automatic page loader', 'ko-rive-backgrounds-manager' ); ?></label>
							<div class="ko-rbm-fields-2"><div><label for="ko-rbm-priority"><?php esc_html_e( 'Priority', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-priority" name="loader_priority" type="number" min="1" max="999" value="<?php echo absint( $editing['loader_priority'] ); ?>"></div><div><label for="ko-rbm-display"><?php esc_html_e( 'Display', 'ko-rive-backgrounds-manager' ); ?></label><?php self::render_select( 'loader_display_mode', $editing['loader_display_mode'], array( 'everywhere' => 'Everywhere', 'include_selected' => 'Only selected locations', 'exclude_selected' => 'Everywhere except selected locations' ), 'ko-rbm-display' ); ?></div></div>
							<?php self::render_target_select( 'loader_page_ids', __( 'Pages', 'ko-rive-backgrounds-manager' ), $pages, $editing['loader_page_ids'], 'ID', 'post_title' ); self::render_target_select( 'loader_post_ids', __( 'Posts', 'ko-rive-backgrounds-manager' ), $posts, $editing['loader_post_ids'], 'ID', 'post_title' ); self::render_target_select( 'loader_category_ids', __( 'Categories', 'ko-rive-backgrounds-manager' ), $categories, $editing['loader_category_ids'], 'term_id', 'name' ); ?>
							<div class="ko-rbm-check-grid"><label><input type="checkbox" name="loader_front_page" value="1" <?php checked( $editing['loader_front_page'] ); ?>> <?php esc_html_e( 'Front page', 'ko-rive-backgrounds-manager' ); ?></label><label><input type="checkbox" name="loader_posts_page" value="1" <?php checked( $editing['loader_posts_page'] ); ?>> <?php esc_html_e( 'Posts page', 'ko-rive-backgrounds-manager' ); ?></label><label><input type="checkbox" name="loader_search" value="1" <?php checked( $editing['loader_search'] ); ?>> <?php esc_html_e( 'Search results', 'ko-rive-backgrounds-manager' ); ?></label><label><input type="checkbox" name="loader_not_found" value="1" <?php checked( $editing['loader_not_found'] ); ?>> <?php esc_html_e( '404 pages', 'ko-rive-backgrounds-manager' ); ?></label><label><input type="checkbox" name="loader_category_archives" value="1" <?php checked( $editing['loader_category_archives'] ); ?>> <?php esc_html_e( 'Selected category archives', 'ko-rive-backgrounds-manager' ); ?></label><label><input type="checkbox" name="loader_posts_categories" value="1" <?php checked( $editing['loader_posts_categories'] ); ?>> <?php esc_html_e( 'Posts in selected categories', 'ko-rive-backgrounds-manager' ); ?></label></div>
							<div class="ko-rbm-fields-2"><div><label for="ko-rbm-frequency"><?php esc_html_e( 'Frequency', 'ko-rive-backgrounds-manager' ); ?></label><?php self::render_select( 'loader_frequency', $editing['loader_frequency'], array( 'every_load' => 'Every matching load', 'session' => 'Once per session', 'daily' => 'Once every 24 hours' ), 'ko-rbm-frequency' ); ?></div><div><label for="ko-rbm-event"><?php esc_html_e( 'Completion event (legacy)', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-event" name="loader_completion_event" type="text" value="<?php echo esc_attr( $editing['loader_completion_event'] ); ?>"></div></div>
							<div class="ko-rbm-fields-4"><div><label><?php esc_html_e( 'Grace ms', 'ko-rive-backgrounds-manager' ); ?></label><input name="loader_grace" type="number" min="0" max="3000" value="<?php echo absint( $editing['loader_grace'] ); ?>"></div><div><label><?php esc_html_e( 'Minimum visible ms', 'ko-rive-backgrounds-manager' ); ?></label><input name="loader_minimum_visible" type="number" min="0" max="3000" value="<?php echo absint( $editing['loader_minimum_visible'] ); ?>"></div><div><label><?php esc_html_e( 'Maximum wait ms', 'ko-rive-backgrounds-manager' ); ?></label><input name="loader_maximum" type="number" min="1000" max="30000" value="<?php echo absint( $editing['loader_maximum'] ); ?>"></div><div><label><?php esc_html_e( 'Exit pause ms', 'ko-rive-backgrounds-manager' ); ?></label><input name="loader_hold" type="number" min="0" max="3000" value="<?php echo absint( $editing['loader_hold'] ); ?>"></div><div><label><?php esc_html_e( 'Fade ms', 'ko-rive-backgrounds-manager' ); ?></label><input name="loader_fade" type="number" min="0" max="3000" value="<?php echo absint( $editing['loader_fade'] ); ?>"></div></div><p class="description"><?php esc_html_e( 'Grace suppresses the loader on fast pages. If shown, it remains until visible viewport media settles, subject to Maximum wait. Completion event no longer delays the adaptive exit.', 'ko-rive-backgrounds-manager' ); ?></p>
							<label for="ko-rbm-aria"><?php esc_html_e( 'Accessibility label', 'ko-rive-backgrounds-manager' ); ?></label><input id="ko-rbm-aria" name="loader_aria_label" type="text" value="<?php echo esc_attr( $editing['loader_aria_label'] ); ?>"><div class="ko-rbm-check-grid"><label><input type="checkbox" name="loader_lock_scroll" value="1" <?php checked( $editing['loader_lock_scroll'] ); ?>> <?php esc_html_e( 'Lock scrolling', 'ko-rive-backgrounds-manager' ); ?></label><label><input type="checkbox" name="loader_reduced_motion" value="1" <?php checked( $editing['loader_reduced_motion'] ); ?>> <?php esc_html_e( 'Respect reduced motion', 'ko-rive-backgrounds-manager' ); ?></label></div>
						</div>
						<div class="ko-rbm-form-actions"><button class="button button-primary" type="submit"><?php echo esc_html( $edit_id ? __( 'Save Changes', 'ko-rive-backgrounds-manager' ) : __( 'Add to Library', 'ko-rive-backgrounds-manager' ) ); ?></button><?php if ( $edit_id ) : ?><a class="button" href="<?php echo esc_url( self::admin_page_url() ); ?>"><?php esc_html_e( 'Cancel', 'ko-rive-backgrounds-manager' ); ?></a><?php endif; ?></div>
					</form>
				</section>
			</div><footer>KO – Rive Manager v<?php echo esc_html( self::VERSION ); ?></footer>
		</div>
		<?php
	}

	private static function render_select( $name, $selected_value, $options, $id ) {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected_value, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function render_target_select( $name, $label, $objects, $selected_values, $id_key, $label_key ) {
		echo '<label for="ko-rbm-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select class="ko-rbm-multiselect" id="ko-rbm-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '[]" multiple size="5">';
		foreach ( $objects as $object ) {
			$value = absint( $object->{$id_key} );
			$text = (string) $object->{$label_key};
			echo '<option value="' . $value . '" ' . selected( in_array( $value, array_map( 'intval', (array) $selected_values ), true ), true, false ) . '>' . esc_html( $text ?: sprintf( __( 'Untitled #%d', 'ko-rive-backgrounds-manager' ), $value ) ) . '</option>';
		}
		echo '</select>';
	}

	private static function role_label( $role ) {
		$labels = array( 'background' => __( 'Background', 'ko-rive-backgrounds-manager' ), 'hero' => __( 'Hero', 'ko-rive-backgrounds-manager' ), 'loader' => __( 'Page Loader', 'ko-rive-backgrounds-manager' ) );
		return $labels[ $role ] ?? $labels['background'];
	}

	private static function loader_target_label( $item ) {
		if ( empty( $item['loader_enabled'] ) ) {
			return __( 'Off — edit this item to enable it.', 'ko-rive-backgrounds-manager' );
		}
		if ( 'everywhere' === $item['loader_display_mode'] ) {
			return __( 'Everywhere on the front end.', 'ko-rive-backgrounds-manager' );
		}
		$targets = count( (array) $item['loader_page_ids'] ) + count( (array) $item['loader_post_ids'] );
		if ( ! empty( $item['loader_category_archives'] ) ) {
			$targets += count( (array) $item['loader_category_ids'] );
		}
		if ( ! empty( $item['loader_posts_categories'] ) && ! empty( $item['loader_category_ids'] ) ) {
			$targets++;
		}
		foreach ( array( 'loader_front_page', 'loader_posts_page', 'loader_search', 'loader_not_found' ) as $flag ) {
			$targets += ! empty( $item[ $flag ] ) ? 1 : 0;
		}
		if ( 'include_selected' === $item['loader_display_mode'] && 0 === $targets ) {
			return __( 'On, but no locations are selected.', 'ko-rive-backgrounds-manager' );
		}
		return 'exclude_selected' === $item['loader_display_mode']
			? sprintf( __( 'Everywhere except %d selected location(s).', 'ko-rive-backgrounds-manager' ), $targets )
			: sprintf( __( 'Only %d selected location(s).', 'ko-rive-backgrounds-manager' ), $targets );
	}

	public function render_shortcode( $attributes, $content = null ) {
		$attributes = shortcode_atts( array( 'id' => '', 'class' => '', 'height' => '', 'min_height' => '' ), $attributes, self::SHORTCODE );
		$found = self::find_item( $attributes['id'] );
		if ( ! $found || '' === self::item_url( $found['item'] ) ) {
			return '';
		}
		$this->enqueue_frontend_assets();
		return $this->render_item_markup( $found['id'], $found['item'], $attributes, $content );
	}

	public function render_legacy_background_shortcode( $attributes, $content = null ) {
		$attributes = shortcode_atts( array( 'id' => '', 'class' => '', 'height' => '' ), $attributes, self::LEGACY_SHORTCODE );
		return $this->render_shortcode( $attributes, $content );
	}

	public function render_legacy_hero_shortcode( $attributes ) {
		foreach ( self::library() as $id => $item ) {
			if ( is_array( $item ) && 'hero' === ( $item['role'] ?? '' ) ) {
				$attributes['id'] = $id;
				return $this->render_shortcode( $attributes );
			}
		}
		return '';
	}

	private function render_item_markup( $id, $item, $attributes = array(), $content = null ) {
		$role = $item['role'];
		$classes = array( 'ko-rive-item', $id, 'ko-rive-' . $role );
		foreach ( preg_split( '/\s+/', (string) ( $attributes['class'] ?? '' ) ) as $class ) {
			$class = sanitize_html_class( $class ); if ( $class ) { $classes[] = $class; }
		}
		$style = ! empty( $item['fallback_enabled'] ) ? '--ko-rive-fallback:' . $item['fallback_color'] . ';' : '';
		if ( 'hero' === $role ) {
			$height = ! empty( $attributes['height'] ) ? self::sanitize_dimension( $attributes['height'], $item['height'] ) : $item['height'];
			$min = ! empty( $attributes['min_height'] ) ? self::sanitize_dimension( $attributes['min_height'], $item['min_height'] ) : $item['min_height'];
			$style .= '--ko-rive-height:' . $height . ';--ko-rive-min-height:' . $min . ';';
		}
		if ( 'background' === $role && ! empty( $attributes['height'] ) ) {
			$style .= 'min-height:' . self::sanitize_dimension( $attributes['height'], '320px' ) . ';';
		}
		$poster = $item['poster_url'] ? '<img class="ko-rive-poster" src="' . esc_url( $item['poster_url'] ) . '" alt="" aria-hidden="true">' : '';
		$inner = null === $content ? '' : '<div class="ko-rive-content">' . do_shortcode( $content ) . '</div>';
		$loader_attrs = 'loader' === $role ? ' role="status" aria-live="polite" aria-label="' . esc_attr( $item['loader_aria_label'] ) . '"' : ' role="img" aria-label="' . esc_attr( $item['name'] ) . '"';
		return '<div class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '" data-ko-rive-id="' . esc_attr( $id ) . '" style="' . esc_attr( $style ) . '"' . $loader_attrs . '>' . $poster . $inner . '</div>';
	}

	public function enqueue_frontend_assets() {
		if ( $this->assets_enqueued || empty( self::library() ) ) {
			return;
		}
		$this->assets_enqueued = true;
		$base = plugin_dir_url( __FILE__ );
		wp_enqueue_style( 'ko-rive-manager', $base . 'assets/css/backgrounds.css', array(), self::VERSION );
		wp_enqueue_script( 'ko-rive-manager', $base . 'assets/js/backgrounds.js', array(), self::VERSION, true );
		wp_add_inline_script( 'ko-rive-manager', 'window.KORiveManager=' . wp_json_encode( $this->frontend_config() ) . ';', 'before' );
	}

	private function frontend_config() {
		$items = array(); $classes = array();
		foreach ( self::library() as $id => $raw ) {
			if ( ! is_array( $raw ) ) { continue; }
			$item = array_merge( self::item_defaults(), $raw );
			$url = self::item_url( $item );
			if ( '' === $url ) { continue; }
			$items[ $id ] = array(
				'id' => $id, 'name' => $item['name'], 'role' => $item['role'], 'loadPolicy' => $item['load_policy'],
				'src' => $url, 'artboard' => $item['artboard'], 'animation' => $item['animation'], 'stateMachine' => $item['state_machine'],
				'fit' => $item['fit'], 'alignment' => $item['alignment'], 'fallbackColor' => $item['fallback_enabled'] ? $item['fallback_color'] : '',
				'posterUrl' => $item['poster_url'], 'height' => $item['height'], 'minHeight' => $item['min_height'],
				'loader' => array(
					'enabled' => (bool) $item['loader_enabled'], 'priority' => (int) $item['loader_priority'],
					'displayMode' => $item['loader_display_mode'], 'targetUrls' => self::loader_target_urls( $item ),
					'frequency' => $item['loader_frequency'], 'graceDuration' => $item['loader_grace'],
					'minimumVisibleDuration' => $item['loader_minimum_visible'], 'maximumDuration' => $item['loader_maximum'],
					'exitHoldDuration' => $item['loader_hold'], 'fadeDuration' => $item['loader_fade'], 'completionEvent' => $item['loader_completion_event'],
					'lockScroll' => (bool) $item['loader_lock_scroll'], 'respectReducedMotion' => (bool) $item['loader_reduced_motion'],
					'ariaLabel' => $item['loader_aria_label'], 'storageKey' => 'koRiveLoaderSeen-' . $id,
				),
			);
			$classes[ $id ] = $id;
		}
		return array(
			'items' => $items, 'classes' => $classes,
			'automaticLoaders' => ! self::standalone_loader_plugin_active(),
			'navigationHandoffKey' => self::NAVIGATION_HANDOFF_KEY,
			'runtimeUrl' => add_query_arg( 'ver', self::RUNTIME_VERSION, plugin_dir_url( __FILE__ ) . 'assets/vendor/rive/rive.js' ),
			'wasmUrl' => add_query_arg( 'ver', self::RUNTIME_VERSION, plugin_dir_url( __FILE__ ) . 'assets/vendor/rive/rive.wasm' ),
		);
	}

	public function render_preload_links() {
		$urls = array();
		foreach ( self::library() as $item ) {
			if ( is_array( $item ) && 'preload' === ( $item['load_policy'] ?? '' ) ) {
				$url = self::item_url( $item ); if ( $url ) { $urls[] = $url; }
			}
		}
		if ( empty( $urls ) ) { return; }
		$base = plugin_dir_url( __FILE__ );
		echo '<link rel="preload" href="' . esc_url( add_query_arg( 'ver', self::RUNTIME_VERSION, $base . 'assets/vendor/rive/rive.js' ) ) . '" as="script">' . "\n";
		echo '<link rel="preload" href="' . esc_url( add_query_arg( 'ver', self::RUNTIME_VERSION, $base . 'assets/vendor/rive/rive.wasm' ) ) . '" as="fetch" crossorigin="anonymous">' . "\n";
		foreach ( array_unique( $urls ) as $url ) { echo '<link rel="preload" href="' . esc_url( $url ) . '" as="fetch" crossorigin="anonymous">' . "\n"; }
	}

	public function render_loader_bootstrap() {
		if ( self::standalone_loader_plugin_active() ) { return; }
		$loaders = array();
		foreach ( self::library() as $id => $raw ) {
			$item = is_array( $raw ) ? array_merge( self::item_defaults(), $raw ) : array();
			if ( ! $item || 'loader' !== $item['role'] || ! $item['loader_enabled'] || ! self::item_url( $item ) ) { continue; }
			$loaders[] = array(
				'id' => $id,
				'priority' => (int) $item['loader_priority'],
				'displayMode' => $item['loader_display_mode'],
				'targetUrls' => self::loader_target_urls( $item ),
				'serverMatched' => (bool) $this->loader_matches_location( $item ),
				'frequency' => $item['loader_frequency'],
				'storageKey' => 'koRiveLoaderSeen-' . $id,
				'respectReducedMotion' => (bool) $item['loader_reduced_motion'],
				'lockScroll' => (bool) $item['loader_lock_scroll'],
				'fallbackColor' => $item['fallback_enabled'] ? $item['fallback_color'] : '#ffffff',
				'graceDuration' => (int) $item['loader_grace'],
				'maximumDuration' => (int) $item['loader_maximum'],
			);
		}
		if ( ! $loaders ) { return; }
		usort( $loaders, function( $a, $b ) { return $a['priority'] <=> $b['priority']; } );
		$handoff_key = self::NAVIGATION_HANDOFF_KEY;
		?>
		<style id="ko-rive-loader-bootstrap-css">html.ko-rive-loader-pending::before{content:"";position:fixed;inset:0;z-index:2147483001;display:block;background-color:var(--ko-rive-loader-bootstrap-bg,#fff);background-image:var(--ko-rive-loader-bootstrap-frame,none);background-position:center;background-repeat:no-repeat;background-size:100% 100%;pointer-events:auto}html.ko-rive-loader-pending-lock{overflow:hidden!important;overscroll-behavior:none;scrollbar-gutter:stable}html.ko-rive-loader-pending-lock body{overflow:hidden!important;overscroll-behavior:none}</style>
		<script id="ko-rive-loader-bootstrap-js">
		(function(){
			var items=<?php echo wp_json_encode( $loaders ); ?>,handoffKey=<?php echo wp_json_encode( $handoff_key ); ?>,current,match=null,handoff=null,i,item,targets,selected,storage,previous,root=document.documentElement,startedAt=Date.now(),revealedAt=0,revealAfter=0,frameDataUrl='',released=false,revealTimer,safetyTimer;
			function normalized(value){var parsed,path;try{parsed=new URL(value,window.location.href);}catch(error){return '';}if(!/^https?:$/.test(parsed.protocol)||parsed.origin!==window.location.origin){return '';}path=parsed.pathname.replace(/\/+$/,'')||'/';return parsed.origin+path;}
			function comparable(value){var parsed;try{parsed=new URL(value,window.location.href);}catch(error){return '';}if(!/^https?:$/.test(parsed.protocol)||parsed.origin!==window.location.origin){return '';}return parsed.origin+parsed.pathname+parsed.search;}
			function validFrame(value){return typeof value==='string'&&value.length<=350000&&/^data:image\/(?:webp|png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(value);}
			function readHandoff(){var value=null;try{value=JSON.parse(window.sessionStorage.getItem(handoffKey)||'null');window.sessionStorage.removeItem(handoffKey);}catch(error){}if(!value||value.version!==1||!value.id||value.destination!==comparable(window.location.href)||(parseInt(value.expiresAt,10)||0)<Date.now()){return null;}return value;}
			function seen(value){try{if(value.frequency==='session'){storage=window.sessionStorage;return !!storage.getItem(value.storageKey);}if(value.frequency==='daily'){storage=window.localStorage;previous=parseInt(storage.getItem(value.storageKey),10)||0;return Date.now()-previous<86400000;}}catch(error){}return false;}
			current=normalized(window.location.href);handoff=readHandoff();
			for(i=0;i<items.length;i++){item=items[i];targets=(item.targetUrls||[]).map(normalized).filter(Boolean);selected=targets.indexOf(current)!==-1;if(item.displayMode==='everywhere'||(item.displayMode==='include_selected'&&(selected||item.serverMatched))||(item.displayMode==='exclude_selected'&&!selected&&item.serverMatched)){if((handoff&&handoff.id===item.id)||(!seen(item)&&!(item.respectReducedMotion&&window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches))){match=item;break;}}}
			if(!match){return;}if(!handoff||handoff.id!==match.id){handoff=null;}
			if(handoff){startedAt=parseInt(handoff.startedAt,10)||startedAt;revealedAt=parseInt(handoff.revealedAt,10)||0;revealAfter=Math.max(0,parseInt(handoff.revealAfter,10)||0);if(validFrame(handoff.frameDataUrl)&&(parseInt(handoff.frameCapturedAt,10)||0)>=startedAt){frameDataUrl=handoff.frameDataUrl;}}else{revealAfter=Math.max(0,parseInt(match.graceDuration,10)||0);}
			root.style.setProperty('--ko-rive-loader-bootstrap-bg',match.fallbackColor||'#ffffff');if(frameDataUrl){root.style.setProperty('--ko-rive-loader-bootstrap-frame','url("'+frameDataUrl+'")');}root.dataset.koRiveLoaderPending=match.id;
			function reveal(id){if(released||(id&&id!==match.id)){return;}if(!revealedAt){revealedAt=Date.now();}root.classList.add('ko-rive-loader-pending');if(match.lockScroll){root.classList.add('ko-rive-loader-pending-lock');}}
			function release(id){if(released||(id&&root.dataset.koRiveLoaderPending&&root.dataset.koRiveLoaderPending!==id)){return;}released=true;window.clearTimeout(revealTimer);window.clearTimeout(safetyTimer);root.classList.remove('ko-rive-loader-pending','ko-rive-loader-pending-lock');root.style.removeProperty('--ko-rive-loader-bootstrap-bg');root.style.removeProperty('--ko-rive-loader-bootstrap-frame');delete root.dataset.koRiveLoaderPending;}
			window.KORiveLoaderBootstrap={id:match.id,startedAt:startedAt,frameDataUrl:frameDataUrl,get revealedAt(){return revealedAt;},isRevealed:function(){return revealedAt>0&&!released;},reveal:reveal,release:release};
			if(revealedAt){reveal(match.id);}else{revealTimer=window.setTimeout(function(){reveal(match.id);},Math.max(0,revealAfter-(Date.now()-startedAt)));}
			safetyTimer=window.setTimeout(function(){release(match.id);},Math.max(15000,(parseInt(match.maximumDuration,10)||5000)+10000));
		}());
		</script>
		<?php
	}

	public function render_automatic_loader() {
		if ( $this->loader_rendered || self::standalone_loader_plugin_active() ) { return; }
		$match = $this->matching_loader();
		if ( ! $match ) { return; }
		$this->loader_rendered = true;
		$this->enqueue_frontend_assets();
		echo $this->render_item_markup( $match['id'], $match['item'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$frequency = $match['item']['loader_frequency']; $storage_key = 'koRiveLoaderSeen-' . $match['id'];
		?>
		<script>(function(){var e=document.querySelector('[data-ko-rive-id="<?php echo esc_js( $match['id'] ); ?>"]'),f=<?php echo wp_json_encode( $frequency ); ?>,k=<?php echo wp_json_encode( $storage_key ); ?>,n=Date.now(),s;if(!e)return;try{if(f==='session'){s=sessionStorage;if(s.getItem(k)){e.style.display='none';e.dataset.koRiveSkip='1';}else{s.setItem(k,String(n));e.dataset.koRiveMarked='1';}}else if(f==='daily'){s=localStorage;var t=parseInt(s.getItem(k),10)||0;if(n-t<86400000){e.style.display='none';e.dataset.koRiveSkip='1';}else{s.setItem(k,String(n));e.dataset.koRiveMarked='1';}}}catch(x){}})();</script><noscript><style>.ko-rive-loader{display:none!important}</style></noscript>
		<?php
	}

	public function render_automatic_loader_fallback() {
		if ( ! $this->loader_rendered ) { $this->render_automatic_loader(); }
	}

	private function matching_loader() {
		$loaders = array();
		foreach ( self::library() as $id => $raw ) {
			$item = is_array( $raw ) ? array_merge( self::item_defaults(), $raw ) : array();
			if ( $item && 'loader' === $item['role'] && $item['loader_enabled'] && self::item_url( $item ) && $this->loader_matches_location( $item ) ) {
				$loaders[] = array( 'id' => $id, 'item' => $item );
			}
		}
		usort( $loaders, function( $a, $b ) { return (int) $a['item']['loader_priority'] <=> (int) $b['item']['loader_priority']; } );
		return $loaders ? $loaders[0] : null;
	}

	private function loader_matches_location( $item ) {
		if ( 'everywhere' === $item['loader_display_mode'] ) { return true; }
		$matches = false;
		$queried_id = absint( get_queried_object_id() );
		$queried_type = $queried_id ? get_post_type( $queried_id ) : '';
		$current_path = self::normalized_url_path( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
		$target_paths = array_map( array( __CLASS__, 'normalized_url_path' ), self::loader_target_urls( $item ) );
		if ( $current_path && in_array( $current_path, array_filter( $target_paths ), true ) ) { $matches = true; }
		if ( $item['loader_front_page'] && is_front_page() ) { $matches = true; }
		if ( $item['loader_posts_page'] && is_home() ) { $matches = true; }
		if ( $item['loader_search'] && is_search() ) { $matches = true; }
		if ( $item['loader_not_found'] && is_404() ) { $matches = true; }
		if ( 'page' === $queried_type && in_array( $queried_id, array_map( 'intval', (array) $item['loader_page_ids'] ), true ) ) { $matches = true; }
		if ( 'post' === $queried_type && in_array( $queried_id, array_map( 'intval', (array) $item['loader_post_ids'] ), true ) ) { $matches = true; }
		if ( $item['loader_category_ids'] ) {
			if ( $item['loader_category_archives'] && is_category( $item['loader_category_ids'] ) ) { $matches = true; }
			if ( $item['loader_posts_categories'] && 'post' === $queried_type && has_category( $item['loader_category_ids'], $queried_id ) ) { $matches = true; }
		}
		return 'exclude_selected' === $item['loader_display_mode'] ? ! $matches : $matches;
	}

	private static function normalized_url_path( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '/';
		$path = rtrim( $path, '/' );
		return '' === $path ? '/' : $path;
	}

	private static function standalone_loader_plugin_active() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $plugin_file ) {
			$plugin_file = str_replace( '\\', '/', (string) $plugin_file );
			if ( 'ko-rive-page-loader/ko-rive-page-loader.php' === $plugin_file ) {
				return true;
			}
		}
		return false;
	}

	private static function loader_target_urls( $item ) {
		$urls = array();
		foreach ( array_merge( (array) $item['loader_page_ids'], (array) $item['loader_post_ids'] ) as $post_id ) {
			$url = get_permalink( absint( $post_id ) );
			if ( $url ) { $urls[] = esc_url_raw( $url ); }
		}
		if ( ! empty( $item['loader_category_archives'] ) ) {
			foreach ( (array) $item['loader_category_ids'] as $category_id ) {
				$url = get_category_link( absint( $category_id ) );
				if ( ! is_wp_error( $url ) && $url ) { $urls[] = esc_url_raw( $url ); }
			}
		}
		if ( ! empty( $item['loader_front_page'] ) ) {
			$urls[] = home_url( '/' );
		}
		if ( ! empty( $item['loader_posts_page'] ) ) {
			$posts_page_id = absint( get_option( 'page_for_posts', 0 ) );
			$url = $posts_page_id ? get_permalink( $posts_page_id ) : '';
			if ( $url ) { $urls[] = esc_url_raw( $url ); }
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private static function item_url( $item ) {
		if ( ! empty( $item['attachment_id'] ) ) {
			$url = wp_get_attachment_url( absint( $item['attachment_id'] ) ); if ( $url ) { return esc_url_raw( $url ); }
		}
		if ( ! empty( $item['external_url'] ) ) { return esc_url_raw( $item['external_url'] ); }
		$relative = ltrim( (string) ( $item['file'] ?? '' ), '/\\' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) || ! preg_match( '#^(?:ko-rive-backgrounds-manager|ko-rive-hero)/[A-Za-z0-9._-]+\.riv$#', $relative ) ) { return ''; }
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || ! is_readable( trailingslashit( $uploads['basedir'] ) . $relative ) ) { return ''; }
		$url = trailingslashit( $uploads['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
		return ! empty( $item['hash'] ) ? add_query_arg( 'v', substr( $item['hash'], 0, 12 ), $url ) : $url;
	}

	private static function delete_unified_file( $relative ) {
		$relative = ltrim( (string) $relative, '/\\' );
		if ( ! preg_match( '#^ko-rive-backgrounds-manager/[A-Za-z0-9._-]+\.riv$#', $relative ) ) { return; }
		$uploads = wp_upload_dir(); if ( ! empty( $uploads['error'] ) ) { return; }
		$path = trailingslashit( $uploads['basedir'] ) . $relative; if ( is_file( $path ) ) { wp_delete_file( $path ); }
	}

	private static function fits() { return array( 'layout', 'cover', 'contain', 'fill', 'fitWidth', 'fitHeight', 'scaleDown', 'none' ); }
	private static function alignments() { return array( 'center', 'topLeft', 'topCenter', 'topRight', 'centerLeft', 'centerRight', 'bottomLeft', 'bottomCenter', 'bottomRight' ); }
	private static function sanitize_choice( $value, $allowed, $fallback ) { return in_array( (string) $value, $allowed, true ) ? (string) $value : $fallback; }
	private static function sanitize_id_array( $values ) { return is_array( $values ) ? array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) ) : array(); }
	private static function sanitize_dimension( $value, $fallback ) { $value = trim( (string) $value ); return preg_match( '/^(?:0|\d+(?:\.\d+)?(?:px|vh|svh|dvh|lvh|vw|rem|em|%))$/i', $value ) ? $value : $fallback; }
}

register_activation_hook( __FILE__, array( 'KO_Rive_Backgrounds_Manager', 'activate' ) );
new KO_Rive_Backgrounds_Manager();
