<?php
/*
Plugin Name: Performance Cache - PROISP
Description: Make your website faster by saving a cached copy of it.
Version: 1.0.0
Author: group.one
Author URI: https://group.one
License: http://www.apache.org/licenses/LICENSE-2.0
Text Domain: vcaching
Network: true

This plugin is a modified version of the WordPress plugin "Varnish Caching" by Razvan Stanga.
Copyright 2017: Razvan Stanga (email: varnish-caching@razvi.ro)
*/

if ( ! defined( 'PROISP_VCaching_CLUSTER_ID' ) ) {
	$onecom_cluster_id = isset( $_SERVER['ONECOM_CLUSTER_ID'] ) ? sanitize_text_field( $_SERVER['ONECOM_CLUSTER_ID'] ) : '';
	define( 'PROISP_VCaching_CLUSTER_ID', $onecom_cluster_id );
}

if ( ! defined( 'PROISP_VCaching_WEBCONFIG_ID' ) ) {
	$onecom_webconfig_id = isset( $_SERVER['ONECOM_CLUSTER_ID'] ) ? sanitize_text_field( $_SERVER['ONECOM_WEBCONFIG_ID'] ) : '';
	define( 'PROISP_VCaching_WEBCONFIG_ID', $onecom_webconfig_id );
}


#[\AllowDynamicProperties]
class PROISP_VCaching {

	protected $blogId;
	public $plugin                 = 'proisp-vcache';
	protected $prefix              = 'proisp_varnish_caching_';
	protected $purgeUrls           = array();
	protected $varnishIp           = null;
	protected $varnishHost         = null;
	protected $dynamicHost         = null;
	protected $ipsToHosts          = array();
	protected $statsJsons          = array();
	protected $purgeKey            = null;
	public $purgeCache             = 'proisp_purge_varnish_cache';
	protected $postTypes           = array( 'page', 'post' );
	protected $override            = 0;
	protected $customFields        = array();
	protected $noticeMessage       = '';
	protected $truncateNotice      = false;
	protected $truncateNoticeShown = false;
	protected $truncateCount       = 0;
	protected $debug               = 0;
	protected $vclGeneratorTab     = true;
	protected $purgeOnMenuSave     = false;
	protected $useSsl              = false;
	protected $uncacheable_cookies = array(
		'woocommerce_cart_hash',
		'woocommerce_items_in_cart',
		'comment_author',
		'comment_author_email_',
		'wordpress_logged_in_',
		'wp-postpass_',
	);

	public function __construct() {
		global $blog_id;
		defined( $this->plugin ) || define( $this->plugin, true );

		$this->blogId = $blog_id;
		add_action( 'init', array( &$this, 'init' ) );
		add_action( 'activity_box_end', array( $this, 'varnish_glance' ), 100 );
	}

	public function init() {
		/** load english en_US tranlsations [as] if any unsupported language en is selected in WP-Admin
		 *  Eg: If en_NZ selected, en_US will be loaded
		 * */

		if ( strpos( get_locale(), 'en_' ) === 0 ) {
			$mo_path = WP_PLUGIN_DIR . '/' . plugin_basename( dirname( __FILE__ ) ) . '/languages/' . $this->plugin . '-en_US.mo';
			load_textdomain( $this->plugin, $mo_path );
		} elseif ( strpos( get_locale(), 'pt_BR' ) === 0 ) {
			$mo_path = WP_PLUGIN_DIR . '/' . plugin_basename( dirname( __FILE__ ) ) . '/languages/' . $this->plugin . '-pt_PT.mo';
			load_textdomain( $this->plugin, $mo_path );
		} else {
			load_plugin_textdomain( $this->plugin, false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
		}

		$this->customFields = array(
			array(
				'name'        => 'ttl',
				'title'       => 'TTL',
				'description' => __( 'Not required. If filled in overrides default TTL of %s seconds. 0 means no caching.', 'proisp-vcache' ),
				'type'        => 'text',
				'scope'       => array( 'post', 'page' ),
				'capability'  => 'manage_options',
			),
		);

		$this->setup_ips_to_hosts();
		$this->purgeKey = ( $purgeKey = trim( get_option( $this->prefix . 'purge_key' ) ) ) ? $purgeKey : null;
		// $this->admin_menu();

		add_action( 'wp', array( $this, 'buffer_start' ), 1000000 );
		add_action( 'shutdown', array( $this, 'buffer_end' ), 1000000 );

		$this->truncateNotice = get_option( $this->prefix . 'truncate_notice' );
		$this->debug          = get_option( $this->prefix . 'debug' );

		// send headers to varnish
		add_action( 'send_headers', array( $this, 'send_headers' ), 1000000 );

		// logged in cookie
		add_action( 'wp_login', array( $this, 'wp_login' ), 1000000 );
		add_action( 'wp_logout', array( $this, 'wp_logout' ), 1000000 );

		// register events to purge post
		foreach ( $this->get_register_events() as $event ) {
			add_action( $event, array( $this, 'purge_post' ), 10, 2 );
		}

		// purge all cache from admin bar
		if ( $this->check_if_purgeable() ) {

			$purge_cache = isset( $_GET[ $this->purgeCache ] ) ? sanitize_text_field( $_GET[ $this->purgeCache ] ) : false;

			add_action( 'admin_bar_menu', array( $this, 'proisp_purge_varnish_cache_all_adminbar' ), 100 );
			if ( $purge_cache && $purge_cache == 1 && check_admin_referer( $this->plugin ) ) {
				if ( get_option( 'permalink_structure' ) == '' && current_user_can( 'manage_options' ) ) {
					add_action( 'admin_notices', array( $this, 'pretty_permalinks_message' ) );
				}
				if ( $this->varnishIp == null ) {
					add_action( 'admin_notices', array( $this, 'purge_message_no_ips' ) );
				} else {
					$this->purge_cache();
				}
			} elseif ( isset( $purge_cache ) && $purge_cache == 'cdn' && check_admin_referer( $this->plugin ) ) {
				if ( get_option( 'permalink_structure' ) == '' && current_user_can( 'manage_options' ) ) {
					add_action( 'admin_notices', array( $this, 'pretty_permalinks_message' ) );
				}
				if ( $this->varnishIp == null ) {
					add_action( 'admin_notices', array( $this, 'purge_message_no_ips' ) );
				} else {
					$purge_id     = time();
					$updated_data = array( 'vcache_purge_id' => $purge_id );
					$this->proisp_vcaching_json_update_option( 'onecom_vcache_info', $updated_data );
					// Purge cache needed after purge CDN
					$this->purge_cache();
				}
			}
		}

		// purge post/page cache from post/page actions
		if ( $this->check_if_purgeable() ) {

			$get_action    = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : false;
			$post_id       = isset( $_GET['post_id'] ) ? sanitize_text_field( $_GET['post_id'] ) : false;
			$vcaching_note = isset( $_GET['vcaching_note'] ) ? sanitize_text_field( $_GET['vcaching_note'] ) : false;

			add_filter(
				'post_row_actions',
				array(
					&$this,
					'post_row_actions',
				),
				0,
				2
			);
			add_filter(
				'page_row_actions',
				array(
					&$this,
					'page_row_actions',
				),
				0,
				2
			);
			if ( $get_action && $post_id && ( $get_action == 'purge_post' || $get_action == 'purge_page' ) && check_admin_referer( $this->plugin ) ) {
				$this->purge_post( $post_id );
				// [28-May-2019] Removing $_SESSION usage
				// $_SESSION['vcaching_note'] = $this->noticeMessage;
				$referer = str_replace( 'proisp_purge_varnish_cache=1', '', wp_get_referer() );
				wp_redirect( $referer . ( strpos( $referer, '?' ) ? '&' : '?' ) . 'vcaching_note=' . $get_action );
			}
			if ( $vcaching_note && ( $vcaching_note == 'purge_post' || $vcaching_note == 'purge_page' ) ) {
				add_action( 'admin_notices', array( $this, 'purge_post_page' ) );
			}
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'override_homepage_ttl' ), 1000 );
		$this->useSsl = get_option( $this->prefix . 'ssl' );
	}


	/**
	 * Update WordPress option data as a json
	 * option_name - WordPress option meta name
	 * data - Pass array as a key => value
	 * proisp_vcaching_json_update_option($option_name, array)
	 */
	public function proisp_vcaching_json_update_option( $option_name, $data ) {

		// return if no option_name and data
		if ( empty( $option_name ) || empty( $data ) ) {
			return false;
		}

		// If exising data exists, merge else update as a fresh data
		$option_data = get_site_option( $option_name );
		if ( $option_data && ! empty( $data ) ) {
			$existing_data = json_decode( $option_data, true );
			$new_array     = array_merge( $existing_data, $data );
			return update_site_option( $option_name, json_encode( $new_array ) );
		} else {
			return update_site_option( $option_name, json_encode( $data ) );
		}
	}

	public function proisp_vcaching_json_delete_option( $option_name, $key ) {

		// return if no option_name and key
		if ( empty( $option_name ) || empty( $key ) ) {
			return false;
		}

		// If not a valid JSON, or key does not exist, return
		$result = json_decode( get_site_option( $option_name ), true );
		// Number can also be treated as valid json, so also check if array
		if ( json_last_error() == JSON_ERROR_NONE && is_array( $result ) && key_exists( $key, $result ) ) {
			unset( $result[ $key ] );
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Get WordPress option json data
	 * option_name - WordPress option meta name
	 * key (optional) - get only certain key value
	 */
	public function proisp_vcaching_json_get_option( $option_name, $key = false ) {

		// If option name does not exit, return
		$option_data = get_site_option( $option_name );

		if ( $option_data == false ) {
			return false;
		}

		// If key exist, return only its value, else return complete option array
		if ( $key ) {
			// If not a valid JSON, or key does not exist, return
			$result = json_decode( get_site_option( $option_name ), true );
			// Number can also be treated as valid json, so also check if array
			if ( json_last_error() == JSON_ERROR_NONE && is_array( $result ) && key_exists( $key, $result ) ) {
				return $result[ $key ];
			} else {
				return false;
			}
		} else {
			return json_decode( get_site_option( $option_name ), true );
		}
	}

	public function override_ttl( $post ) {
		$get_globals_post_id = isset( $GLOBALS['wp_the_query']->post->ID ) ? sanitize_text_field( $GLOBALS['wp_the_query']->post->ID ) : false;
		$postId              = $get_globals_post_id ?? 0;
		if ( $postId && ( is_page() || is_single() ) ) {
			$ttl = get_post_meta( $postId, $this->prefix . 'ttl', true );
			if ( trim( $ttl ) != '' ) {
				Header( 'X-VC-TTL: ' . intval( $ttl ), true );
			}
		}
	}

	public function override_homepage_ttl() {
		if ( is_home() || is_front_page() ) {
			$this->homepage_ttl = get_option( $this->prefix . 'homepage_ttl' );
			Header( 'X-VC-TTL: ' . intval( $this->homepage_ttl ), true );
		}
	}

	public function buffer_callback( $buffer ) {
		return $buffer;
	}

	public function buffer_start() {
		ob_start( array( $this, 'buffer_callback' ) );
	}

	public function buffer_end() {
		if ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	protected function setup_ips_to_hosts() {
		$this->varnishIp       = get_option( $this->prefix . 'ips' );
		$this->varnishHost     = get_option( $this->prefix . 'hosts' );
		$this->dynamicHost     = get_option( $this->prefix . 'dynamic_host' );
		$this->statsJsons      = get_option( $this->prefix . 'stats_json_file' );
		$this->purgeOnMenuSave = get_option( $this->prefix . 'purge_menu_save' );
		$varnishIp             = explode( ',', $this->varnishIp );
		$varnishIp             = apply_filters( 'vcaching_varnish_ips', $varnishIp );
		$varnishHost           = explode( ',', $this->varnishHost );
		$varnishHost           = apply_filters( 'vcaching_varnish_hosts', $varnishHost );
		$statsJsons            = explode( ',', $this->statsJsons );
		$httpHost              = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : '';

		foreach ( $varnishIp as $key => $ip ) {
			$this->ipsToHosts[] = array(
				'ip'        => $ip,
				'host'      => $this->dynamicHost ? $httpHost : $varnishHost[ $key ],
				'statsJson' => isset( $statsJsons[ $key ] ) ? sanitize_text_field( $statsJsons[ $key ] ) : null,
			);
		}
	}

	public function check_if_purgeable() {
		return ( ! is_multisite() && current_user_can( 'activate_plugins' ) ) || current_user_can( 'manage_network' ) || ( is_multisite() && ! current_user_can( 'manage_network' ) && ( SUBDOMAIN_INSTALL || ( ! SUBDOMAIN_INSTALL && ( BLOG_ID_CURRENT_SITE != $this->blogId ) ) ) );
	}


	public function purge_message_no_ips() {
		echo '<div id="message" class="error fade"><p><strong>' . sprintf( __( 'Performance cache works with domains which are hosted on %1$sone.com%2$s.', 'proisp-vcache' ), '<a href="https://one.com/" target="_blank" rel="noopener noreferrer">', '</a>' ) . '</strong></p></div>';
	}

	public function purge_post_page() {
		return;
	}

	public function pretty_permalinks_message() {
		$message = '<div id="message" class="error"><p>' . __( 'Performance Cache requires you to use custom permalinks. Please go to the <a href="options-permalink.php">Permalinks Options Page</a> to configure them.', 'proisp-vcache' ) . '</p></div>';
		echo apply_filters( 'ocvc_permalink_notice', $message );
	}

	public function proisp_purge_varnish_cache_all_adminbar( $admin_bar ) {
		$admin_bar->add_menu(
			array(
				'id'    => 'proisp_purge-all-varnish-cache',
				'title' => '<span class="ab-icon dashicons dashicons-trash"></span>' . __( 'Clear Performance Cache', 'proisp-vcache' ),
				'href'  => wp_nonce_url( add_query_arg( $this->purgeCache, 1 ), $this->plugin ),
				'meta'  => array(
					'title' => __( 'Clear Performance Cache', 'proisp-vcache' ),
				),
			)
		);
	}

	public function varnish_glance() {
		$url          = wp_nonce_url( admin_url( '?' . $this->purgeCache ), $this->plugin );
		$button       = '';
		$nopermission = '';
		$intro        = '';
		if ( $this->varnishIp == null ) {
			$intro .= __( 'Varnish environment not present for Performance cache to work.', 'proisp-vcache' );
		} else {
			$intro        .= sprintf( __( '<a href="%1$s">Performance Cache</a> automatically purges your posts when published or updated. Sometimes you need a manual flush.', 'proisp-vcache' ), 'http://wordpress.org/plugins/varnish-caching/' );
			$button       .= __( 'Press the button below to force it to purge your entire cache.', 'proisp-vcache' );
			$button       .= '</p><p><span class="button"><a href="' . $url . '"><strong>';
			$button       .= __( 'Purge Performance Cache', 'proisp-vcache' );
			$button       .= '</strong></a></span>';
			$nopermission .= __( 'You do not have permission to purge the cache for the whole site. Please contact your adminstrator.', 'proisp-vcache' );
		}
		if ( $this->check_if_purgeable() ) {
			$text = $intro . ' ' . $button;
		} else {
			$text = $intro . ' ' . $nopermission;
		}
		echo '<p class="varnish-glance">' . esc_html( $text ) . '</p>';
	}

	protected function get_register_events() {
		$actions = array(
			'save_post',
			'deleted_post',
			'trashed_post',
			'edit_post',
			'delete_attachment',
			'switch_theme',
		);
		return apply_filters( 'vcaching_events', $actions );
	}

	public function get_cluster_meta(): object {
		$conf       = '{}';
		$conf_path1 = '/run/mail.conf';
		$conf_path2 = '/run/domain.conf';

		if ( file_exists( $conf_path1 ) ) {
			$conf = trim( file_get_contents( $conf_path1 ) );
		} elseif ( file_exists( $conf_path2 ) ) {
			$conf = trim( file_get_contents( $conf_path2 ) );
		}
		return json_decode( $conf );
	}

	public function get_cluster_webroute(): string {
		$meta           = self::get_cluster_meta();
		$request_scheme = isset( $_SERVER['REQUEST_SCHEME'] ) ? sanitize_text_field( $_SERVER['REQUEST_SCHEME'] ) : false;
		// exit if required cluster meta missing
		if (
			empty( $meta ) ||
			! ( is_object( $meta ) && isset( $meta->wp->webconfig, $meta->wp->cluster ) )
		) {
			return '';
		}

		$scheme = ! empty( $request_scheme ) ? $request_scheme : 'https';
		return sprintf( '%s://%s.website.%s.service.one', $scheme, $meta->wp->webconfig, $meta->wp->cluster );
	}

	public function purge_wp_webroute(): bool {
		// exit if not a cluster
		if ( ! PROISP_VCaching_CLUSTER_ID ) {
			return false;
		}

		// get webroute url
		$wp_webroute_url = self::get_cluster_webroute();

		// exit if webroute url is empty
		if ( empty( $wp_webroute_url ) ) {
			return false;
		}

		// check if WP home_url is equal to webroute
		if ( parse_url( home_url() ) !== parse_url( $wp_webroute_url ) ) {
			// if different, then purge the webroute url cache
			$this->purge_url( $wp_webroute_url . '/?vc-regex' );
			return true;
		}
		return false;
	}

	public function purge_cache() {
		$purgeUrls   = array_unique( $this->purgeUrls );
		$purge_cache = isset( $_GET[ $this->purgeCache ] ) ? sanitize_text_field( $_GET[ $this->purgeCache ] ) : false;

		if ( empty( $purgeUrls ) ) {
			if ( $purge_cache && $this->check_if_purgeable() && check_admin_referer( $this->plugin ) ) {
				$this->purge_url( home_url() . '/?vc-regex' );
			}
		} else {
			foreach ( $purgeUrls as $url ) {
				$this->purge_url( $url );
			}
		}

		// purge webroute if cluster
		self::purge_wp_webroute();

		if ( $this->truncateNotice && $this->truncateNoticeShown == false ) {
			$this->truncateNoticeShown = true;
			$this->noticeMessage      .= '<br />' . __( 'Truncate message activated. Showing only first 3 messages.', 'proisp-vcache' );
		}
	}

	public function purge_url( $url ) {
		$p = parse_url( $url );

		if ( isset( $p['query'] ) && ( $p['query'] == 'vc-regex' ) ) {
			$pregex      = '.*';
			$purgemethod = 'regex';
		} else {
			$pregex      = '';
			$purgemethod = 'default';
		}

		if ( isset( $p['path'] ) ) {
			$path = $p['path'];
		} else {
			$path = '';
		}

		$schema  = apply_filters( 'vcaching_schema', $this->useSsl ? 'https://' : 'http://' );
		$purgeme = '';

		foreach ( $this->ipsToHosts as $key => $ipToHost ) {
			$purgeme = $schema . $ipToHost['ip'] . $path . $pregex;
			$headers = array(
				'host'              => $ipToHost['host'],
				'X-VC-Purge-Method' => $purgemethod,
				'X-VC-Purge-Host'   => $ipToHost['host'],
			);
			if ( ! is_null( $this->purgeKey ) ) {
				$headers['X-VC-Purge-Key'] = $this->purgeKey;
			}
			$purgeme  = apply_filters( 'ocvc_purge_url', $url, $path, $pregex );
			$headers  = apply_filters( 'ocvc_purge_headers', $url, $headers );
			$response = wp_remote_request(
				$purgeme,
				array(
					'method'    => 'PURGE',
					'headers'   => $headers,
					'sslverify' => false,
				)
			);
			apply_filters( 'ocvc_purge_notices', $response, $purgeme );
			if ( $response instanceof WP_Error ) {
				foreach ( $response->errors as $error => $errors ) {
					$this->noticeMessage .= '<br />Error ' . $error . '<br />';
					foreach ( $errors as $error => $description ) {
						$this->noticeMessage .= ' - ' . $description . '<br />';
					}
				}
			} else {
				if ( $this->truncateNotice && $this->truncateCount <= 2 || $this->truncateNotice == false ) {
					$this->noticeMessage .= '' . __( 'Trying to purge URL : ', 'proisp-vcache' ) . $purgeme;
					preg_match( '/<title>(.*)<\/title>/i', $response['body'], $matches );
					$this->noticeMessage .= ' => <br /> ' . isset( $matches[1] ) ? ' => ' . $matches[1] : $response['body'];
					$this->noticeMessage .= '<br />';
					if ( $this->debug ) {
						$this->noticeMessage .= $response['body'] . '<br />';
					}
				}
				$this->truncateCount++;
			}
		}

		do_action( 'vcaching_after_purge_url', $url, $purgeme );
	}

	public function purge_post( $postId, $post = null ) {
		// Do not purge menu items
		if ( get_post_type( $post ) == 'nav_menu_item' && $this->purgeOnMenuSave == false ) {
			return;
		}

		// If this is a valid post we want to purge the post, the home page and any associated tags & cats
		// If not, purge everything on the site.
		$validPostStatus = array( 'publish', 'trash' );
		$thisPostStatus  = get_post_status( $postId );

		// If this is a revision, stop.
		if ( get_permalink( $postId ) !== true && ! in_array( $thisPostStatus, $validPostStatus ) ) {
			return;
		} else {
			// array to collect all our URLs
			$listofurls = array();

			// Category purge based on Donnacha's work in WP Super Cache
			$categories = get_the_category( $postId );
			if ( $categories ) {
				foreach ( $categories as $cat ) {
					array_push( $listofurls, get_category_link( $cat->term_id ) );
				}
			}
			// Tag purge based on Donnacha's work in WP Super Cache
			$tags = get_the_tags( $postId );
			if ( $tags ) {
				foreach ( $tags as $tag ) {
					array_push( $listofurls, get_tag_link( $tag->term_id ) );
				}
			}

			// Author URL
			array_push(
				$listofurls,
				get_author_posts_url( get_post_field( 'post_author', $postId ) ),
				get_author_feed_link( get_post_field( 'post_author', $postId ) )
			);

			// Archives and their feeds
			$archiveurls = array();
			if ( get_post_type_archive_link( get_post_type( $postId ) ) == true ) {
				array_push(
					$listofurls,
					get_post_type_archive_link( get_post_type( $postId ) ),
					get_post_type_archive_feed_link( get_post_type( $postId ) )
				);
			}

			// Post URL
			array_push( $listofurls, get_permalink( $postId ) );

			// Feeds
			array_push(
				$listofurls,
				get_bloginfo_rss( 'rdf_url' ),
				get_bloginfo_rss( 'rss_url' ),
				get_bloginfo_rss( 'rss2_url' ),
				get_bloginfo_rss( 'atom_url' ),
				get_bloginfo_rss( 'comments_rss2_url' ),
				get_post_comments_feed_link( $postId )
			);

			// Home Page and (if used) posts page
			array_push( $listofurls, home_url( '/' ) );
			if ( get_option( 'show_on_front' ) == 'page' ) {
				array_push( $listofurls, get_permalink( get_option( 'page_for_posts' ) ) );
			}

			// If Automattic's AMP is installed, add AMP permalink
			if ( function_exists( 'amp_get_permalink' ) ) {
				array_push( $listofurls, amp_get_permalink( $postId ) );
			}

			// Now flush all the URLs we've collected
			foreach ( $listofurls as $url ) {
				array_push( $this->purgeUrls, $url );
			}
		}
		// Filter to add or remove urls to the array of purged urls
		// @param array $purgeUrls the urls (paths) to be purged
		// @param int $postId the id of the new/edited post
		$this->purgeUrls = apply_filters( 'vcaching_purge_urls', $this->purgeUrls, $postId );
		$this->purge_cache();
	}

	/**
	 * Check if current cookies should be cached.
	 *
	 * @return bool
	 */
	public function is_cookie_cacheable( $cookies = array() ): bool {
		// kill switch in wp-config.php
		if ( defined( 'PROISP_VCaching_COOKIE_CACHING' ) && ! PROISP_VCaching_COOKIE_CACHING ) {
			return false;
		}

		// exit if an ajax or POST request.
		if ( defined( 'DOING_AJAX' ) || ( isset( $_POST ) && ! empty( $_POST ) ) ) {
			return false;
		}

		// iterate un-cacheable cookies array over current cookies
		// check if any current cookie starts like un-cacheable
		// exit if any un-cacheable cookie found in current cookies
		$match = array_filter(
			$this->uncacheable_cookies,
			fn( $k) => array_filter(
				array_keys( $cookies ),
				fn( $j) => str_starts_with( $j, $k )
			)
		);

		// implies there are cookies on the page,
		// but none of them matches un-cacheable cookies
		// therefore, we are good to send cookie-cache header to varnish server
		return empty( $match );
	}

	public function send_headers() {

		if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
			$exclude_from_cache = false;
			$request_uri        = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER['REQUEST_URI'] ) : '';

			if ( strpos( $request_uri, 'favicon.ico' ) === false ) {
				$post_id = url_to_postid( $request_uri );
				if ( $post_id != 0 && ! empty( get_post_meta( $post_id, '_oct_exclude_from_cache', true ) ) ) {
					$exclude_from_cache = get_post_meta( $post_id, '_oct_exclude_from_cache', true );
				}
			}

			$enable = get_option( $this->prefix . 'enable' );
			if ( ( $enable === 'true' || $enable === true || $enable === 1 ) && ! $exclude_from_cache ) {
				Header( 'X-VC-Enabled: true', true );

				if ( is_user_logged_in() ) {
					Header( 'X-VC-Cacheable: NO:User is logged in', true );
					$ttl = 0;
				} else {
					$ttl_conf = get_option( $this->prefix . 'ttl' );
					$ttl      = ( trim( $ttl_conf ) ? $ttl_conf : 2592000 );
				}

				// send cookie cache header if applicable
				if ( self::is_cookie_cacheable( $_COOKIE ) ) {
					Header( 'X-VC-Cacheable-Cookie: true', true );
					Header( 'cache-time: ' . $ttl, true );
				}

				Header( 'X-VC-TTL: ' . $ttl, true );
			} else {
				Header( 'X-VC-Enabled: false', true );
			}
		}
	}

	public function wp_login() {
		$cookie = get_option( $this->prefix . 'cookie' );
		$cookie = ( strlen( $cookie ) ? $cookie : sha1( md5( uniqid() ) ) );
		@setcookie( $cookie, 1, time() + 3600 * 24 * 100, COOKIEPATH, COOKIE_DOMAIN, false, true );
	}

	public function wp_logout() {
		$cookie = get_option( $this->prefix . 'cookie' );
		$cookie = ( strlen( $cookie ) ? $cookie : sha1( md5( uniqid() ) ) );
		@setcookie( $cookie, null, time() - 3600 * 24 * 100, COOKIEPATH, COOKIE_DOMAIN, false, true );
	}

	public function post_row_actions( $actions, $post ) {
		if ( $this->check_if_purgeable() ) {
			$actions = array_merge(
				$actions,
				array(
					'vcaching_purge_post' => sprintf( '<a href="%s">' . __( 'Purge from Varnish', 'proisp-vcache' ) . '</a>', wp_nonce_url( sprintf( 'admin.php?page=vcaching-plugin&tab=console&action=purge_post&post_id=%d', $post->ID ), $this->plugin ) ),
				)
			);
		}
		return $actions;
	}

	public function page_row_actions( $actions, $post ) {
		if ( $this->check_if_purgeable() ) {
			$actions = array_merge(
				$actions,
				array(
					'vcaching_purge_page' => sprintf( '<a href="%s">' . __( 'Purge from Varnish', 'proisp-vcache' ) . '</a>', wp_nonce_url( sprintf( 'admin.php?page=vcaching-plugin&tab=console&action=purge_page&post_id=%d', $post->ID ), $this->plugin ) ),
				)
			);
		}
		return $actions;
	}
}

$vcaching = new PROISP_VCaching();

if ( ! class_exists( 'PROISP_VCaching_Config' ) ) {
	include_once 'proisp-addons/proisp-inc.php';
}

// WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	include 'wp-cli.php';
}

if ( ! defined( 'PROISP_VCaching_HTTP_HOST' ) ) {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( $_SERVER['HTTP_HOST'] ) : 'localhost';
	define( 'PROISP_VCaching_HTTP_HOST', $host );
}

register_uninstall_hook( __FILE__, 'PROISP_VCaching_vcache_plugin_uninstall' );
function PROISP_VCaching_vcache_plugin_uninstall() {
	// delete vcache data
	// delete_option("varnish_caching_ttl");
}
