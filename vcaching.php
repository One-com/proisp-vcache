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

if (!defined('PROISP_OC_CLUSTER_ID')) {
    define('PROISP_OC_CLUSTER_ID', isset($_SERVER['ONECOM_CLUSTER_ID']) ? $_SERVER['ONECOM_CLUSTER_ID'] : '');
}

if (!defined('PROISP_OC_WEBCONFIG_ID')) {
    define('PROISP_OC_WEBCONFIG_ID', isset($_SERVER['ONECOM_WEBCONFIG_ID']) ? $_SERVER['ONECOM_WEBCONFIG_ID'] : '');
}


#[\AllowDynamicProperties]
class VCachingOC {
    protected $blogId;
    public $plugin = 'vcaching';
    protected $prefix = 'varnish_caching_';
    protected $purgeUrls = array();
    protected $varnishIp = null;
    protected $varnishHost = null;
    protected $dynamicHost = null;
    protected $ipsToHosts = array();
    protected $statsJsons = array();
    protected $purgeKey = null;
    public $purgeCache = 'purge_varnish_cache';
    protected $postTypes = array('page', 'post');
    protected $override = 0;
    protected $customFields = array();
    protected $noticeMessage = '';
    protected $truncateNotice = false;
    protected $truncateNoticeShown = false;
    protected $truncateCount = 0;
    protected $debug = 0;
    protected $vclGeneratorTab = true;
    protected $purgeOnMenuSave = false;
    protected $useSsl = false;
    protected $uncacheable_cookies = ['woocommerce_cart_hash',
        'woocommerce_items_in_cart',
        'comment_author',
        'comment_author_email_',
        'wordpress_logged_in_',
        'wp-postpass_'];

    public function __construct()
    {
        global $blog_id;
        defined($this->plugin) || define($this->plugin, true);

        $this->blogId = $blog_id;
        add_action('init', array(&$this, 'init'));
        add_action('activity_box_end', array($this, 'varnish_glance'), 100);
    }

    public function init()
    {
        /** load english en_US tranlsations [as] if any unsupported language en is selected in WP-Admin
         *  Eg: If en_NZ selected, en_US will be loaded
         * */

		if(strpos(get_locale(), 'en_') === 0){
            $mo_path = WP_PLUGIN_DIR . '/'.plugin_basename( dirname( __FILE__ ) ) . '/languages/vcaching-en_US.mo';
			load_textdomain( $this->plugin, $mo_path );
		} else if (strpos(get_locale(), 'pt_BR') === 0){
            $mo_path = WP_PLUGIN_DIR . '/'.plugin_basename( dirname( __FILE__ ) ) . '/languages/onecom-wp-pt_PT.mo';
			load_textdomain( $this->plugin, $mo_path );
		} else{
			load_plugin_textdomain($this->plugin, false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
		}

        $this->customFields = array(
            array(
                'name'          => 'ttl',
                'title'         => 'TTL',
                'description'   => __('Not required. If filled in overrides default TTL of %s seconds. 0 means no caching.', 'vcaching'),
                'type'          => 'text',
                'scope'         =>  array('post', 'page'),
                'capability'    => 'manage_options'
            )
        );

        $this->setup_ips_to_hosts();
        $this->purgeKey = ($purgeKey = trim(get_option($this->prefix . 'purge_key'))) ? $purgeKey : null;
        $this->admin_menu();

        add_action('wp', array($this, 'buffer_start'), 1000000);
        add_action('shutdown', array($this, 'buffer_end'), 1000000);

        $this->truncateNotice = get_option($this->prefix . 'truncate_notice');
        $this->debug = get_option($this->prefix . 'debug');

        // send headers to varnish
        add_action('send_headers', array($this, 'send_headers'), 1000000);

        // logged in cookie
        add_action('wp_login', array($this, 'wp_login'), 1000000);
        add_action('wp_logout', array($this, 'wp_logout'), 1000000);

        // register events to purge post
        foreach ($this->get_register_events() as $event) {
            add_action($event, array($this, 'purge_post'), 10, 2);
        }

        // purge all cache from admin bar
        if ($this->check_if_purgeable()) {
            add_action('admin_bar_menu', array($this, 'purge_varnish_cache_all_adminbar'), 100);
            if (isset($_GET[$this->purgeCache]) && $_GET[$this->purgeCache] == 1 && check_admin_referer($this->plugin)) {
                if (get_option('permalink_structure') == '' && current_user_can('manage_options')) {
                    add_action('admin_notices' , array($this, 'pretty_permalinks_message'));
                }
                if ($this->varnishIp == null) {
                    add_action('admin_notices' , array($this, 'purge_message_no_ips'));
                } else {
                    $this->purge_cache();
                }
            } else if (isset($_GET[$this->purgeCache]) && $_GET[$this->purgeCache] == 'cdn' && check_admin_referer($this->plugin)) {
                if (get_option('permalink_structure') == '' && current_user_can('manage_options')) {
                    add_action('admin_notices' , array($this, 'pretty_permalinks_message'));
                }
                if ($this->varnishIp == null) {
                    add_action('admin_notices' , array($this, 'purge_message_no_ips'));
                } else {
                    $purge_id = time();
                    $updated_data = array('vcache_purge_id'=> $purge_id);
                    $this->oc_json_update_option('onecom_vcache_info', $updated_data);
                    // Purge cache needed after purge CDN
                    $this->purge_cache();
                }
            }
        }

        // purge post/page cache from post/page actions
        if ($this->check_if_purgeable()) {
            add_filter('post_row_actions', array(
                &$this,
                'post_row_actions'
            ), 0, 2);
            add_filter('page_row_actions', array(
                &$this,
                'page_row_actions'
            ), 0, 2);
            if (isset($_GET['action']) && isset($_GET['post_id']) && ($_GET['action'] == 'purge_post' || $_GET['action'] == 'purge_page') && check_admin_referer($this->plugin)) {
                $this->purge_post($_GET['post_id']);
                //[28-May-2019] Removing $_SESSION usage
                // $_SESSION['vcaching_note'] = $this->noticeMessage;
                $referer = str_replace('purge_varnish_cache=1', '', wp_get_referer());
                wp_redirect($referer . (strpos($referer, '?') ? '&' : '?') . 'vcaching_note=' . $_GET['action']);
            }
            if (isset($_GET['vcaching_note']) && ($_GET['vcaching_note'] == 'purge_post' || $_GET['vcaching_note'] == 'purge_page')) {
                add_action('admin_notices' , array($this, 'purge_post_page'));
            }
        }

        if ($this->override = get_option($this->prefix . 'override')) {
            add_action('admin_menu', array($this, 'create_custom_fields'));
            add_action('save_post', array($this, 'save_custom_fields' ), 1, 2);
            add_action('wp_enqueue_scripts', array($this, 'override_ttl'), 1000);
        }
        add_action('wp_enqueue_scripts', array($this, 'override_homepage_ttl'), 1000);
        $this->useSsl = get_option($this->prefix . 'ssl');
    }


    /**
     * Update WordPress option data as a json
     * option_name - WordPress option meta name
     * data - Pass array as a key => value
     * oc_json_update_option($option_name, array)
     */
    public function oc_json_update_option($option_name, $data ){

        // return if no option_name and data
        if (empty($option_name) || empty($data)) {
            return false;
        }

        // If exising data exists, merge else update as a fresh data
        $option_data = get_site_option($option_name);
        if($option_data && !empty($data)){
            $existing_data = json_decode($option_data, true);
            $new_array = array_merge($existing_data, $data);
			return update_site_option($option_name, json_encode($new_array));
		} else {
			return update_site_option($option_name, json_encode($data));
		}
    }

    public function oc_json_delete_option($option_name, $key ){

        // return if no option_name and key
        if (empty($option_name) || empty($key)) {
            return false;
        }

        // If not a valid JSON, or key does not exist, return
        $result = json_decode(get_site_option($option_name), true);
        // Number can also be treated as valid json, so also check if array
        if (json_last_error() == JSON_ERROR_NONE && is_array($result) && key_exists($key, $result)) {
            unset($result[$key]);
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
    public function oc_json_get_option($option_name, $key = false){


        // If option name does not exit, return
        $option_data = get_site_option($option_name);

        if ($option_data == false) {
            return false;
        }

        // If key exist, return only its value, else return complete option array
        if($key){
            // If not a valid JSON, or key does not exist, return
			$result = json_decode(get_site_option($option_name), true);
            // Number can also be treated as valid json, so also check if array
            if (json_last_error() == JSON_ERROR_NONE && is_array($result) && key_exists($key, $result)) {
                return $result[$key];
            } else {
                return false;
            }
		} else {
			return json_decode(get_site_option($option_name), true);
		}
    }

    public function override_ttl($post)
    {
        $postId = isset($GLOBALS['wp_the_query']->post->ID) ? $GLOBALS['wp_the_query']->post->ID : 0;
        if ($postId && (is_page() || is_single())) {
            $ttl = get_post_meta($postId, $this->prefix . 'ttl', true);
            if (trim($ttl) != '') {
                Header('X-VC-TTL: ' . intval($ttl), true);
            }
        }
    }

    public function override_homepage_ttl()
    {
        if (is_home() || is_front_page()) {
            $this->homepage_ttl = get_option($this->prefix . 'homepage_ttl');
            Header('X-VC-TTL: ' . intval($this->homepage_ttl), true);
        }
    }

    public function buffer_callback($buffer)
    {
        return $buffer;
    }

    public function buffer_start()
    {
        ob_start(array($this, "buffer_callback"));
    }

    public function buffer_end()
    {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    protected function setup_ips_to_hosts()
    {
        $this->varnishIp = get_option($this->prefix . 'ips');
        $this->varnishHost = get_option($this->prefix . 'hosts');
        $this->dynamicHost = get_option($this->prefix . 'dynamic_host');
        $this->statsJsons = get_option($this->prefix . 'stats_json_file');
        $this->purgeOnMenuSave = get_option($this->prefix . 'purge_menu_save');
        $varnishIp = explode(',', $this->varnishIp);
        $varnishIp = apply_filters('vcaching_varnish_ips', $varnishIp);
        $varnishHost = explode(',', $this->varnishHost);
        $varnishHost = apply_filters('vcaching_varnish_hosts', $varnishHost);
        $statsJsons = explode(',', $this->statsJsons);

        foreach ($varnishIp as $key => $ip) {
            $this->ipsToHosts[] = array(
                'ip' => $ip,
                'host' => $this->dynamicHost ? $_SERVER['HTTP_HOST'] : $varnishHost[$key],
                'statsJson' => isset($statsJsons[$key]) ? $statsJsons[$key] : null
            );
        }
    }

    public function create_custom_fields()
    {
        if (function_exists('add_meta_box')) {
            foreach ($this->postTypes as $postType) {
                add_meta_box($this->plugin, __('Performance Cache', 'vcaching'), array($this, 'display_custom_fields'), $postType, 'side', 'high');
            }
        }
    }

    public function save_custom_fields($post_id, $post)
    {
        if (!isset($_POST['vc-custom-fields_wpnonce']) || !wp_verify_nonce($_POST['vc-custom-fields_wpnonce'], 'vc-custom-fields')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!in_array($post->post_type, $this->postTypes)) {
            return;
        }
        foreach ($this->customFields as $customField) {
            if (current_user_can($customField['capability'], $post_id)) {
                if (isset($_POST[$this->prefix . $customField['name']]) && trim($_POST[$this->prefix . $customField['name']]) != '') {
                    update_post_meta($post_id, $this->prefix . $customField['name'], $_POST[$this->prefix . $customField['name']]);
                } else {
                    delete_post_meta($post_id, $this->prefix . $customField['name']);
                }
            }
        }
    }

    public function display_custom_fields()
    {
        global $post;
        wp_nonce_field('vc-custom-fields', 'vc-custom-fields_wpnonce', false, true);
        foreach ($this->customFields as $customField) {
            // Check scope
            $scope = $customField['scope'];
            $output = false;
            foreach ($scope as $scopeItem) {

                if ($post->post_type == $scopeItem){
	                $output = true;
	                break;
                }
            }
            // Check capability
            if (!current_user_can($customField['capability'], $post->ID)) {
                $output = false;
            }
            // Output if allowed
            if ($output) {
                switch ($customField['type']) {
                    case "checkbox": {
                        // Checkbox
                        $yesVal = "yes";
                        $checkedVal = '';
                        $style = '';
                        if (get_post_meta($post->ID, $this->prefix . $customField['name'], true ) == "yes") {
                            $checkedVal = 'checked="checked"';
                            $style = 'style="width: auto;"';
                        }
                        ?><p><strong><?php echo esc_html( $customField['title'] ); ?></strong></p>
                        <label class="screen-reader-text" for="<?php echo esc_attr( $this->prefix . $customField['name'] ); ?>"><?php echo esc_html( $customField['title'] ); ?></label>
                        <p><input type="checkbox" name="<?php echo esc_attr( $this->prefix . $customField['name'] ); ?>" id="<?php echo esc_attr( $this->prefix . $customField['name'] ); ?>" value="<?php echo esc_attr( $yesVal ); ?>" <?php echo $checkedVal;?> <?php echo $style;?> /></p>
                        <?php break;
                    }
                    default: {
                        // Plain text field
                        ?><p><strong><?php echo esc_html( $customField['title'] ); ?></strong></p><?php
                        $value = get_post_meta($post->ID, $this->prefix . $customField[ 'name' ], true);
                        ?> <p><input type="text" name="<?php echo esc_html( $this->prefix . $customField['name'] ); ?>" id="<?php echo esc_attr( $this->prefix . $customField['name'] ); ?>" value="<?php echo esc_attr( $value ); ?>" /></p> <?php
                        break;
                    }
                }
            } else {
                ?><p><strong><?php echo esc_html( $customField['title'] ); ?></strong></p>
                <?php $value = get_post_meta($post->ID, $this->prefix . $customField[ 'name' ], true); ?>
                <p><input type="text" name="<?php echo esc_html( $this->prefix . $customField['name'] ); ?>" id="<?php echo esc_html( $this->prefix . $customField['name'] ); ?>" value="<?php echo esc_attr( $value ); ?>" disabled /></p>
           <?php }
            $default_ttl = get_option($this->prefix . 'ttl');
            if ($customField['description']) { ?>
                <p><?php echo sprintf( esc_html__( $customField['description'], 'vcaching' ), $default_ttl); ?></p>
            <?php
            }
        }
    }

    public function check_if_purgeable()
    {
        return (!is_multisite() && current_user_can('activate_plugins')) || current_user_can('manage_network') || (is_multisite() && !current_user_can('manage_network') && (SUBDOMAIN_INSTALL || (!SUBDOMAIN_INSTALL && (BLOG_ID_CURRENT_SITE != $this->blogId))));
    }
    

    public function purge_message_no_ips()
    {
        echo '<div id="message" class="error fade"><p><strong>' . sprintf(__('Performance cache works with domains which are hosted on %sone.com%s.', 'vcaching'), '<a href="https://one.com/" target="_blank" rel="noopener noreferrer">', '</a>') . '</strong></p></div>';
    }

    public function purge_post_page()
    {
        return;
        /*if (isset($_SESSION['vcaching_note'])) {
            echo '<div id="message" class="updated fade"><p><strong>' . __('Performance Cache', 'vcaching') . '</strong><br /><br />' . $_SESSION['vcaching_note'] . '</p></div>';
            unset ($_SESSION['vcaching_note']);
        }*/
    }

    public function pretty_permalinks_message()
    {
        $message = '<div id="message" class="error"><p>' . __('Performance Cache requires you to use custom permalinks. Please go to the <a href="options-permalink.php">Permalinks Options Page</a> to configure them.', 'vcaching') . '</p></div>';
        echo apply_filters( 'ocvc_permalink_notice', $message );
    }

    public function purge_varnish_cache_all_adminbar($admin_bar)
    {
        $admin_bar->add_menu(array(
            'id'    => 'purge-all-varnish-cache',
            'title' => '<span class="ab-icon dashicons dashicons-trash"></span>' . __('Clear Performance Cache', 'vcaching'),
            'href'  => wp_nonce_url(add_query_arg($this->purgeCache, 1), $this->plugin),
            'meta'  => array(
                'title' => __('Clear Performance Cache', 'vcaching'),
            )
        ));
    }

    public function varnish_glance()
    {
        $url = wp_nonce_url(admin_url('?' . $this->purgeCache), $this->plugin);
        $button = '';
        $nopermission = '';
        $intro = '';
        if ($this->varnishIp == null) {
            $intro .= __('Varnish environment not present for Performance cache to work.', 'vcaching');
        } else {
            $intro .= sprintf(__('<a href="%1$s">Performance Cache</a> automatically purges your posts when published or updated. Sometimes you need a manual flush.', 'vcaching'), 'http://wordpress.org/plugins/varnish-caching/');
            $button .=  __('Press the button below to force it to purge your entire cache.', 'vcaching');
            $button .= '</p><p><span class="button"><a href="' . $url . '"><strong>';
            $button .= __('Purge Performance Cache', 'vcaching');
            $button .= '</strong></a></span>';
            $nopermission .=  __('You do not have permission to purge the cache for the whole site. Please contact your adminstrator.', 'vcaching');
        }
        if ($this->check_if_purgeable()) {
            $text = $intro . ' ' . $button;
        } else {
            $text = $intro . ' ' . $nopermission;
        }
        echo '<p class="varnish-glance">' . $text . '</p>';
    }

    protected function get_register_events()
    {
        $actions = array(
            'save_post',
            'deleted_post',
            'trashed_post',
            'edit_post',
            'delete_attachment',
            'switch_theme',
        );
        return apply_filters('vcaching_events', $actions);
    }

    public function get_cluster_meta(): object
    {
        $conf = '{}';
        $conf_path1 = '/run/mail.conf';
        $conf_path2 = '/run/domain.conf';

        if (file_exists($conf_path1)) {
            $conf = trim(file_get_contents($conf_path1));
        } else if (file_exists($conf_path2)) {
            $conf = trim(file_get_contents($conf_path2));
        }
        return json_decode($conf);
    }

    public function get_cluster_webroute(): string
    {
        $meta = self::get_cluster_meta();

        // exit if required cluster meta missing
        if (
            empty($meta) ||
            !(is_object($meta) && isset($meta->wp->webconfig, $meta->wp->cluster))
        ) {
            return '';
        }

        $scheme = !empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https';
        return sprintf('%s://%s.website.%s.service.one', $scheme, $meta->wp->webconfig, $meta->wp->cluster);
    }

    public function purge_wp_webroute(): bool
    {
        // exit if not a cluster
        if (!PROISP_OC_CLUSTER_ID) {
            return false;
        }

        // get webroute url
        $wp_webroute_url = self::get_cluster_webroute();

        // exit if webroute url is empty
        if(empty($wp_webroute_url)){
            return false;
        }

        // check if WP home_url is equal to webroute
        if (parse_url(home_url()) !== parse_url($wp_webroute_url)) {
            // if different, then purge the webroute url cache
            $this->purge_url($wp_webroute_url . '/?vc-regex');
            return true;
        }
        return false;
    }

    public function purge_cache()
    {
        $purgeUrls = array_unique($this->purgeUrls);

        if (empty($purgeUrls)) {
            if (isset($_GET[$this->purgeCache]) && $this->check_if_purgeable() && check_admin_referer($this->plugin)) {
                $this->purge_url(home_url() .'/?vc-regex');
            }
        } else {
            foreach($purgeUrls as $url) {
                $this->purge_url($url);
            }
        }

        // purge webroute if cluster
        self::purge_wp_webroute();

        if ($this->truncateNotice && $this->truncateNoticeShown == false) {
            $this->truncateNoticeShown = true;
            $this->noticeMessage .= '<br />' . __('Truncate message activated. Showing only first 3 messages.', 'vcaching');
        }
    }

    public function purge_url($url)
    {
        $p = parse_url($url);

        if (isset($p['query']) && ($p['query'] == 'vc-regex')) {
            $pregex = '.*';
            $purgemethod = 'regex';
        } else {
            $pregex = '';
            $purgemethod = 'default';
        }

        if (isset($p['path'])) {
            $path = $p['path'];
        } else {
            $path = '';
        }

        $schema = apply_filters('vcaching_schema', $this->useSsl ? 'https://' : 'http://');
        $purgeme = '';

        foreach ($this->ipsToHosts as $key => $ipToHost) {
            $purgeme = $schema . $ipToHost['ip'] . $path . $pregex;
            $headers = array('host' => $ipToHost['host'], 'X-VC-Purge-Method' => $purgemethod, 'X-VC-Purge-Host' => $ipToHost['host']);
            if (!is_null($this->purgeKey)) {
                $headers['X-VC-Purge-Key'] = $this->purgeKey;
            }
            $purgeme = apply_filters( 'ocvc_purge_url', $url, $path, $pregex );
            $headers = apply_filters( 'ocvc_purge_headers', $url, $headers );
            $response = wp_remote_request($purgeme, array('method' => 'PURGE', 'headers' => $headers, "sslverify" => false));
            apply_filters( 'ocvc_purge_notices', $response, $purgeme );
            if ($response instanceof WP_Error) {
                foreach ($response->errors as $error => $errors) {
                    $this->noticeMessage .= '<br />Error ' . $error . '<br />';
                    foreach ($errors as $error => $description) {
                        $this->noticeMessage .= ' - ' . $description . '<br />';
                    }
                }
            } else {
                if ($this->truncateNotice && $this->truncateCount <= 2 || $this->truncateNotice == false) {
                    $this->noticeMessage .= '' . __('Trying to purge URL : ', 'vcaching') . $purgeme;
                    preg_match("/<title>(.*)<\/title>/i", $response['body'], $matches);
                    $this->noticeMessage .= ' => <br /> ' . isset($matches[1]) ? " => " . $matches[1] : $response['body'];
                    $this->noticeMessage .= '<br />';
                    if ($this->debug) {
                        $this->noticeMessage .= $response['body'] . "<br />";
                    }
                }
                $this->truncateCount++;
            }
        }

        do_action('vcaching_after_purge_url', $url, $purgeme);
    }

    public function purge_post($postId, $post=null)
    {
        // Do not purge menu items
        if (get_post_type($post) == 'nav_menu_item' && $this->purgeOnMenuSave == false) {
            return;
        }

        // If this is a valid post we want to purge the post, the home page and any associated tags & cats
        // If not, purge everything on the site.
        $validPostStatus = array('publish', 'trash');
        $thisPostStatus  = get_post_status($postId);

        // If this is a revision, stop.
        if(get_permalink($postId) !== true && !in_array($thisPostStatus, $validPostStatus)) {
            return;
        } else {
            // array to collect all our URLs
            $listofurls = array();

            // Category purge based on Donnacha's work in WP Super Cache
            $categories = get_the_category($postId);
            if ($categories) {
                foreach ($categories as $cat) {
                    array_push($listofurls, get_category_link($cat->term_id));
                }
            }
            // Tag purge based on Donnacha's work in WP Super Cache
            $tags = get_the_tags($postId);
            if ($tags) {
                foreach ($tags as $tag) {
                    array_push($listofurls, get_tag_link($tag->term_id));
                }
            }

            // Author URL
            array_push($listofurls,
                get_author_posts_url(get_post_field('post_author', $postId)),
                get_author_feed_link(get_post_field('post_author', $postId))
            );

            // Archives and their feeds
            $archiveurls = array();
            if (get_post_type_archive_link(get_post_type($postId)) == true) {
                array_push($listofurls,
                    get_post_type_archive_link( get_post_type($postId)),
                    get_post_type_archive_feed_link( get_post_type($postId))
                );
            }

            // Post URL
            array_push($listofurls, get_permalink($postId));

            // Feeds
            array_push($listofurls,
                get_bloginfo_rss('rdf_url') ,
                get_bloginfo_rss('rss_url') ,
                get_bloginfo_rss('rss2_url'),
                get_bloginfo_rss('atom_url'),
                get_bloginfo_rss('comments_rss2_url'),
                get_post_comments_feed_link($postId)
            );

            // Home Page and (if used) posts page
            array_push($listofurls, home_url('/'));
            if (get_option('show_on_front') == 'page') {
                array_push($listofurls, get_permalink(get_option('page_for_posts')));
            }

            // If Automattic's AMP is installed, add AMP permalink
            if (function_exists('amp_get_permalink')) {
                array_push($listofurls, amp_get_permalink($postId));
            }

            // Now flush all the URLs we've collected
            foreach ($listofurls as $url) {
                array_push($this->purgeUrls, $url) ;
            }
        }
        // Filter to add or remove urls to the array of purged urls
        // @param array $purgeUrls the urls (paths) to be purged
        // @param int $postId the id of the new/edited post
        $this->purgeUrls = apply_filters('vcaching_purge_urls', $this->purgeUrls, $postId);
        $this->purge_cache();
    }

    /**
     * Check if current cookies should be cached.
     * @return bool
     */
    public function is_cookie_cacheable($cookies = []): bool
    {
        // kill switch?
        if (defined('OC_COOKIE_CACHING') && !OC_COOKIE_CACHING) {
            return false;
        }

        // exit if an ajax or POST request.
        if(defined( 'DOING_AJAX' ) || (isset($_POST) && !empty($_POST))){
            return false;
        }

        // iterate un-cacheable cookies array over current cookies
        // check if any current cookie starts like un-cacheable
        // exit if any un-cacheable cookie found in current cookies
        $match = array_filter($this->uncacheable_cookies,
            fn($k) => array_filter(array_keys($cookies),
                fn($j) => str_starts_with($j, $k)
            )
        );

        // implies there are cookies on the page,
        // but none of them matches un-cacheable cookies
        // therefore, we are good to send cookie-cache header to varnish server
        return empty($match);
    }
    public function send_headers() {

        if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
            $exclude_from_cache = false;
            if ( strpos( $_SERVER['REQUEST_URI'], 'favicon.ico' ) === false ) {
                $post_id = url_to_postid( $_SERVER['REQUEST_URI'] );
                if ( $post_id != 0 && ! empty( get_post_meta( $post_id, '_oct_exclude_from_cache', true ) ) ) {
                    $exclude_from_cache = get_post_meta( $post_id, '_oct_exclude_from_cache', true );
                }
            }

            $enable = get_option( $this->prefix . 'enable' );
            if ( ( $enable === "true" || $enable === true || $enable === 1 ) && ! $exclude_from_cache ) {
                Header( 'X-VC-Enabled: true', true );

                if ( is_user_logged_in() ) {
                    Header( 'X-VC-Cacheable: NO:User is logged in', true );
                    $ttl = 0;
                } else {
                    $ttl_conf = get_option( $this->prefix . 'ttl' );
                    $ttl      = ( trim( $ttl_conf ) ? $ttl_conf : 2592000 );
                }

                // send cookie cache header if applicable
                if (self::is_cookie_cacheable($_COOKIE)) {
                    Header('X-VC-Cacheable-Cookie: true', true);
                    Header('cache-time: '.$ttl, true);
                }

                Header( 'X-VC-TTL: ' . $ttl, true );
            } else {
                Header( 'X-VC-Enabled: false', true );
            }
        }
    }

	public function wp_login()
	{
		$cookie = get_option($this->prefix . 'cookie');
		$cookie = ( strlen($cookie) ? $cookie : sha1(md5(uniqid())) );
		@setcookie($cookie, 1, time()+3600*24*100, COOKIEPATH, COOKIE_DOMAIN, false, true);
	}

	public function wp_logout()
	{
		$cookie = get_option($this->prefix . 'cookie');
		$cookie = ( strlen($cookie) ? $cookie : sha1(md5(uniqid())) );
		@setcookie($cookie, null, time()-3600*24*100, COOKIEPATH, COOKIE_DOMAIN, false, true);
	}

    public function admin_menu()
    {
        add_action('admin_menu', array($this, 'add_menu_item'));
        add_action('admin_init', array($this, 'options_page_fields'));
        add_action('admin_init', array($this, 'console_page_fields'));
        if ($this->vclGeneratorTab) {
            add_action('admin_init', array($this, 'conf_page_fields'));
        }
    }

    public function add_menu_item()
    {
        if ($this->check_if_purgeable()) {
            add_menu_page(__('Performance Cache', 'vcaching'), __('Performance Cache', 'vcaching'), 'manage_options', $this->plugin . '-plugin', array($this, 'settings_page'), plugins_url() . '/' . $this->plugin . '/icon.png', 99);
        }
    }

    public function options_page_fields()
    {
        add_settings_section($this->prefix . 'options', __('Settings', 'vcaching'), null, $this->prefix . 'options');

        add_settings_field($this->prefix . "enable", __("Enable" , 'vcaching'), array($this, $this->prefix . "enable"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "homepage_ttl", __("Homepage cache TTL", 'vcaching'), array($this, $this->prefix . "homepage_ttl"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "ttl", __("Cache TTL", 'vcaching'), array($this, $this->prefix . "ttl"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "ips", __("IPs", 'vcaching'), array($this, $this->prefix . "ips"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "dynamic_host", __("Dynamic host", 'vcaching'), array($this, $this->prefix . "dynamic_host"), $this->prefix . 'options', $this->prefix . 'options');
        if (!get_option($this->prefix . 'dynamic_host')) {
            add_settings_field($this->prefix . "hosts", __("Hosts", 'vcaching'), array($this, $this->prefix . "hosts"), $this->prefix . 'options', $this->prefix . 'options');
        }
        add_settings_field($this->prefix . "override", __("Override default TTL", 'vcaching'), array($this, $this->prefix . "override"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "purge_key", __("Purge key", 'vcaching'), array($this, $this->prefix . "purge_key"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "cookie", __("Logged in cookie", 'vcaching'), array($this, $this->prefix . "cookie"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "stats_json_file", __("Statistics JSONs", 'vcaching'), array($this, $this->prefix . "stats_json_file"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "truncate_notice", __("Truncate notice message", 'vcaching'), array($this, $this->prefix . "truncate_notice"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "purge_menu_save", __("Purge on save menu", 'vcaching'), array($this, $this->prefix . "purge_menu_save"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "ssl", __("Use SSL on purge requests", 'vcaching'), array($this, $this->prefix . "ssl"), $this->prefix . 'options', $this->prefix . 'options');
        add_settings_field($this->prefix . "debug", __("Enable debug", 'vcaching'), array($this, $this->prefix . "debug"), $this->prefix . 'options', $this->prefix . 'options');

        if(isset($_POST['option_page']) && $_POST['option_page'] == $this->prefix . 'options') {
            register_setting($this->prefix . 'options', $this->prefix . "enable");
            register_setting($this->prefix . 'options', $this->prefix . "ttl");
            register_setting($this->prefix . 'options', $this->prefix . "homepage_ttl");
            register_setting($this->prefix . 'options', $this->prefix . "ips");
            register_setting($this->prefix . 'options', $this->prefix . "dynamic_host");
            register_setting($this->prefix . 'options', $this->prefix . "hosts");
            register_setting($this->prefix . 'options', $this->prefix . "override");
            register_setting($this->prefix . 'options', $this->prefix . "purge_key");
            register_setting($this->prefix . 'options', $this->prefix . "cookie");
            register_setting($this->prefix . 'options', $this->prefix . "stats_json_file");
            register_setting($this->prefix . 'options', $this->prefix . "truncate_notice");
            register_setting($this->prefix . 'options', $this->prefix . "purge_menu_save");
            register_setting($this->prefix . 'options', $this->prefix . "ssl");
            register_setting($this->prefix . 'options', $this->prefix . "debug");
        }
    }

    public function varnish_caching_enable()
    {
        ?>
            <input type="checkbox" name="varnish_caching_enable" value="1" <?php checked(1, get_option($this->prefix . 'enable'), true); ?> />
            <p class="description"><?php echo __('Enable Performance Cache', 'vcaching')?></p>
        <?php
    }

    public function varnish_caching_homepage_ttl()
    {
        ?>
            <input type="text" name="varnish_caching_homepage_ttl" id="varnish_caching_homepage_ttl" value="<?php echo get_option($this->prefix . 'homepage_ttl'); ?>" />
            <p class="description"><?php echo __('Time to live in seconds in Varnish cache for homepage', 'vcaching')?></p>
        <?php
    }

    public function varnish_caching_ttl()
    {
        ?>
            <input type="text" name="varnish_caching_ttl" id="varnish_caching_ttl" value="<?php echo get_option($this->prefix . 'ttl'); ?>" />
            <p class="description"><?php echo __('Time to live in seconds in Varnish cache', 'vcaching')?></p>
        <?php
    }

    public function varnish_caching_ips()
    {
        ?>
            <input type="text" name="varnish_caching_ips" id="varnish_caching_ips" size="100" value="<?php echo get_option($this->prefix . 'ips'); ?>" />
            <p class="description"><?php echo __('Comma separated ip/ip:port. Example : 192.168.0.2,192.168.0.3:8080', 'vcaching')?></p>
        <?php
    }

    public function varnish_caching_dynamic_host()
    {
        ?>
            <input type="checkbox" name="varnish_caching_dynamic_host" value="1" <?php checked(1, get_option($this->prefix . 'dynamic_host'), true); ?> />
            <p class="description">
                <?php echo __('Uses the $_SERVER[\'HTTP_HOST\'] as hash for Varnish. This means the purge cache action will work on the domain you\'re on.<br />Use this option if you use only one domain.', 'vcaching')?>
            </p>
        <?php
    }

    public function varnish_caching_hosts()
    {
        ?>
            <input type="text" name="varnish_caching_hosts" id="varnish_caching_hosts" size="100" value="<?php echo get_option($this->prefix . 'hosts'); ?>" />
            <p class="description">
                <?php echo __('Comma separated hostnames. Varnish uses the hostname to create the cache hash. For each IP, you must set a hostname.<br />Use this option if you use multiple domains.', 'vcaching')?>
            </p>
        <?php
    }

    public function varnish_caching_override()
    {
        ?>
            <input type="checkbox" name="varnish_caching_override" value="1" <?php checked(1, get_option($this->prefix . 'override'), true); ?> />
            <p class="description"><?php echo __('Override default TTL on each post/page.', 'vcaching')?></p>
        <?php
    }

    public function varnish_caching_purge_key()
    {
        ?>
            <input type="text" name="varnish_caching_purge_key" id="varnish_caching_purge_key" size="100" maxlength="64" value="<?php echo get_option($this->prefix . 'purge_key'); ?>" />
            <span onclick="generateHash(64, 0, 'varnish_caching_purge_key'); return false;" class="dashicons dashicons-image-rotate" title="<?php echo __('Generate')?>"></span>
            <p class="description">
                <?php echo __('Key used to purge Varnish cache. It is sent to Varnish as X-VC-Purge-Key header. Use a SHA-256 hash.<br />If you can\'t use ACL\'s, use this option. You can set the `purge key` in lib/purge.vcl.<br />Search the default value ff93c3cb929cee86901c7eefc8088e9511c005492c6502a930360c02221cf8f4 to find where to replace it.', 'vcaching')?>
            </p>
        <?php
    }

    public function varnish_caching_cookie()
    {
        ?>
            <input type="text" name="varnish_caching_cookie" id="varnish_caching_cookie" size="100" maxlength="64" value="<?php echo get_option($this->prefix . 'cookie'); ?>" />
            <span onclick="generateHash(64, 0, 'varnish_caching_cookie'); return false;" class="dashicons dashicons-image-rotate" title="<?php echo __('Generate')?>"></span>
            <p class="description">
                <?php echo __('This module sets a special cookie to tell Varnish that the user is logged in. Use a SHA-256 hash. You can set the `logged in cookie` in default.vcl.<br />Search the default value <i>flxn34napje9kwbwr4bjwz5miiv9dhgj87dct4ep0x3arr7ldif73ovpxcgm88vs</i> to find where to replace it.', 'vcaching')?>
            </p>
        <?php
    }

    public function varnish_caching_stats_json_file()
    {
        ?>
            <input type="text" name="varnish_caching_stats_json_file" id="varnish_caching_stats_json_file" size="100" value="<?php echo get_option($this->prefix . 'stats_json_file'); ?>" />
            <p class="description">
                <?php echo sprintf(__('Comma separated relative URLs. One for each IP. <a href="%1$s/wp-admin/index.php?page=vcaching-plugin&tab=stats&info=1">Click here</a> for more info on how to set this up.', 'vcaching'), home_url())?>
            </p>
        <?php
    }

    public function varnish_caching_truncate_notice()
    {
        ?>
            <input type="checkbox" name="varnish_caching_truncate_notice" value="1" <?php checked(1, get_option($this->prefix . 'truncate_notice'), true); ?> />
            <p class="description">
                <?php echo __('When using multiple Varnish Cache servers, VCaching shows too many `Trying to purge URL` messages. Check this option to truncate that message.', 'vcaching')?>
            </p>
        <?php
    }

    public function varnish_caching_purge_menu_save()
    {
        ?>
            <input type="checkbox" name="varnish_caching_purge_menu_save" value="1" <?php checked(1, get_option($this->prefix . 'purge_menu_save'), true); ?> />
            <p class="description">
                <?php echo __('Purge menu related pages when a menu is saved.', 'vcaching')?>
            </p>
        <?php
    }

    public function varnish_caching_ssl()
    {
        ?>
            <input type="checkbox" name="varnish_caching_ssl" value="1" <?php checked(1, get_option($this->prefix . 'ssl'), true); ?> />
            <p class="description">
                <?php echo __('Use SSL (https://) for purge requests.', 'vcaching')?>
            </p>
        <?php
    }

    public function varnish_caching_debug()
    {
        ?>
            <input type="checkbox" name="varnish_caching_debug" value="1" <?php checked(1, get_option($this->prefix . 'debug'), true); ?> />
            <p class="description">
                <?php echo __('Send all debugging headers to the client. Also shows complete response from Varnish on purge all.', 'vcaching')?>
            </p>
        <?php
    }

    public function console_page_fields()
    {
        add_settings_section('console', __("Console", 'vcaching'), null, $this->prefix . 'console');

        add_settings_field($this->prefix . "purge_url", __("URL", 'vcaching'), array($this, $this->prefix . "purge_url"), $this->prefix . 'console', "console");
    }

    public function conf_page_fields()
    {
        add_settings_section('conf', __("Varnish configuration", 'vcaching'), null, $this->prefix . 'conf');

        add_settings_field($this->prefix . "varnish_backends", __("Backends", 'vcaching'), array($this, $this->prefix . "varnish_backends"), $this->prefix . 'conf', "conf");
        add_settings_field($this->prefix . "varnish_acls", __("ACLs", 'vcaching'), array($this, $this->prefix . "varnish_acls"), $this->prefix . 'conf', "conf");

        if(isset($_POST['option_page']) && $_POST['option_page'] == $this->prefix . 'conf') {
            register_setting($this->prefix . 'conf', $this->prefix . "varnish_backends");
            register_setting($this->prefix . 'conf', $this->prefix . "varnish_acls");
        }

        add_settings_section('download', __("Get configuration files", 'vcaching'), null, $this->prefix . 'download');
        if (!class_exists('ZipArchive')) {
            add_settings_section('download_error', __("You cannot download the configuration files", 'vcaching'), null, $this->prefix . 'download_error');
        }

        add_settings_field($this->prefix . "varnish_version", __("Version", 'vcaching'), array($this, $this->prefix . "varnish_version"), $this->prefix . 'download', "download");

        if(isset($_POST['option_page']) && $_POST['option_page'] == $this->prefix . 'download') {
            $version = in_array($_POST['varnish_caching_varnish_version'], array(3,4,5)) ? $_POST['varnish_caching_varnish_version'] : 3;
            $tmpfile = tempnam(sys_get_temp_dir(), "zip");
            $zip = new ZipArchive();
            $zip->open($tmpfile, ZipArchive::OVERWRITE);
            $files = array(
                'default.vcl' => true,
                'LICENSE' => false,
                'README.rst' => false,
                'conf/acl.vcl' => true,
                'conf/backend.vcl' => true,
                'lib/bigfiles.vcl' => false,
                'lib/bigfiles_pipe.vcl' => false,
                'lib/cloudflare.vcl' => false,
                'lib/mobile_cache.vcl' => false,
                'lib/mobile_pass.vcl' => false,
                'lib/purge.vcl' => true,
                'lib/static.vcl' => false,
                'lib/xforward.vcl' => false,
            );
            foreach ($files as $file => $parse) {
                $filepath = __DIR__ . '/varnish-conf/v' . $version . '/' . $file;
                if ($parse) {
                    $content = $this->_parse_conf_file($version, $file, file_get_contents($filepath));
                } else {
                    $content = file_get_contents($filepath);
                }
                $zip->addFromString($file, $content);
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($tmpfile));
            header('Content-Disposition: attachment; filename="varnish_v' . $version . '_conf.zip"');
            readfile($tmpfile);
            unlink($tmpfile);
            exit();
        }
    }

    public function varnish_caching_varnish_version()
    {
        ?>
            <select name="varnish_caching_varnish_version" id="varnish_caching_varnish_version">
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
            </select>
            <p class="description"><?php echo __('Varnish Cache version', 'vcaching')?></p>
        <?php
    }

    public function varnish_caching_varnish_backends()
    {
        ?>
            <input type="text" name="varnish_caching_varnish_backends" id="varnish_caching_varnish_backends" size="100" value="<?php echo get_option($this->prefix . 'varnish_backends'); ?>" />
            <p class="description"><?php echo __('Comma separated ip/ip:port. Example : 192.168.0.2,192.168.0.3:8080', 'vcaching')?></p>
        <?php
    }

    public function varnish_caching_varnish_acls()
    {
        ?>
            <input type="text" name="varnish_caching_varnish_acls" id="varnish_caching_varnish_acls" size="100" value="<?php echo get_option($this->prefix . 'varnish_acls'); ?>" />
            <p class="description"><?php echo __('Comma separated ip/ip range. Example : 192.168.0.2,192.168.1.1/24', 'vcaching')?></p>
        <?php
    }

    private function _parse_conf_file($version, $file, $content)
    {
        if ($file == 'default.vcl') {
            $logged_in_cookie = get_option($this->prefix . 'cookie');
            $content = str_replace('flxn34napje9kwbwr4bjwz5miiv9dhgj87dct4ep0x3arr7ldif73ovpxcgm88vs', $logged_in_cookie, $content);
        } else if ($file == 'conf/backend.vcl') {
            if ($version == 3) {
                $content = "";
            } else if ($version == 4 || $version == 5) {
                $content = "import directors;\n\n";
            }
            $backend = array();
            $ips = get_option($this->prefix . 'varnish_backends');
            $ips = explode(',', $ips);
            $id = 1;
            foreach ($ips as $ip) {
                if (strstr($ip, ":")) {
                    $_ip = explode(':', $ip);
                    $ip = $_ip[0];
                    $port = $_ip[1];
                } else {
                    $port = 80;
                }
                $content .= "backend backend" . $id . " {\n";
                $content .= "\t.host = \"" . $ip . "\";\n";
                $content .= "\t.port = \"" . $port . "\";\n";
                $content .= "}\n";
                $backend[3] .= "\t{\n";
                $backend[3] .= "\t\t.backend = backend" . $id . ";\n";
                $backend[3] .= "\t}\n";
                $backend[4] .= "\tbackends.add_backend(backend" . $id . ");\n";
                $id++;
            }
            if ($version == 3) {
                $content .= "\ndirector backends round-robin {\n";
                $content .= $backend[3];
                $content .= "}\n";
                $content .= "\nsub vcl_recv {\n";
                $content .= "\tset req.backend = backends;\n";
                $content .= "}\n";
            } elseif ($version == 4 || $version == 5) {
                $content .= "\nsub vcl_init {\n";
                $content .= "\tnew backends = directors.round_robin();\n";
                $content .= $backend[4];
                $content .= "}\n";
                $content .= "\nsub vcl_recv {\n";
                $content .= "\tset req.backend_hint = backends.backend();\n";
                $content .= "}\n";
            }
        } else if ($file == 'conf/acl.vcl') {
            $acls = get_option($this->prefix . 'varnish_acls');
            $acls = explode(',', $acls);
            $content = "acl cloudflare {\n";
            $content .= "\t# set this ip to your Railgun IP (if applicable)\n";
            $content .= "\t# \"1.2.3.4\";\n";
            $content .= "}\n";
            $content .= "\nacl purge {\n";
            $content .= "\t\"localhost\";\n";
            $content .= "\t\"127.0.0.1\";\n";
            foreach ($acls as $acl) {
                $content .= "\t\"" . $acl . "\";\n";
            }
            $content .= "}\n";
        } else if ($file == 'lib/purge.vcl') {
            $purge_key = get_option($this->prefix . 'purge_key');
            $content = str_replace('ff93c3cb929cee86901c7eefc8088e9511c005492c6502a930360c02221cf8f4', $purge_key, $content);
        }
        return $content;
    }

    public function post_row_actions($actions, $post)
    {
        if ($this->check_if_purgeable()) {
            $actions = array_merge($actions, array(
                'vcaching_purge_post' => sprintf('<a href="%s">' . __('Purge from Varnish', 'vcaching') . '</a>', wp_nonce_url(sprintf('admin.php?page=vcaching-plugin&tab=console&action=purge_post&post_id=%d', $post->ID), $this->plugin))
            ));
        }
        return $actions;
    }

    public function page_row_actions($actions, $post)
    {
        if ($this->check_if_purgeable()) {
            $actions = array_merge($actions, array(
                'vcaching_purge_page' => sprintf('<a href="%s">' . __('Purge from Varnish', 'vcaching') . '</a>', wp_nonce_url(sprintf('admin.php?page=vcaching-plugin&tab=console&action=purge_page&post_id=%d', $post->ID), $this->plugin))
            ));
        }
        return $actions;
    }
}

$vcaching = new VCachingOC();

if( ! class_exists( 'OCVCaching' ) ) {
    include_once 'onecom-addons/onecom-inc.php';
}


//if( ! class_exists( 'ONECOMUPDATER' ) ) {
//    require_once plugin_dir_path( __FILE__ ).'/onecom-addons/inc/update.php';
//}

// WP-CLI
if (defined('WP_CLI') && WP_CLI) {
    include('wp-cli.php');
}

if ( ! defined('OC_HTTP_HOST')){
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define( 'OC_HTTP_HOST', $host);
}

if ( ! defined( 'OC_CP_LOGIN_URL' ) ) {
    $domain = $_SERVER['ONECOM_DOMAIN_NAME'] ?? '';
    define( 'OC_CP_LOGIN_URL', sprintf("https://one.com/admin/select-admin-domain.do?domain=%s&targetUrl=/admin/managedwp/%s/managed-wp-dashboard.do", $domain, OC_HTTP_HOST) );
}

if ( ! defined( 'OC_WPR_BUY_URL' ) ) {
    $domain = $_SERVER['ONECOM_DOMAIN_NAME'] ?? '';
    define( 'OC_WPR_BUY_URL', sprintf("https://one.com/admin/wprocket-prepare-buy.do?directToDomainAfterPurchase=%s&amp;domain=%s", OC_HTTP_HOST, $domain) );
}

register_uninstall_hook( __FILE__, 'oc_vcache_plugin_uninstall' );
function oc_vcache_plugin_uninstall() {
    
    // delete vcache data
    // delete_option("varnish_caching_ttl");
    // delete_option("varnish_caching_homepage_ttl");
    // delete_option("varnish_caching_ttl_unit");
    // delete_option("onecom_vcache_info");
}
