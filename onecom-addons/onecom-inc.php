<?php
/* Copyright: group.one */

if (!class_exists('OnecomExcludeCache')) {
    require dirname(__FILE__) . '/inc/class-onecom-exclude-cache.php';
}

final class OCVCaching extends VCachingOC
{
    const defaultTTL = 2592000; //1 month
    const defaultTTLUnit = 'days'; // in days
    const defaultEnable = 'true';
    const defaultPrefix = 'varnish_caching_';
    const pluginName = 'onecom-vcache';
    const textDomain = 'vcaching';
    const transient = '__onecom_allowed_package';
    const getOCParam = 'purge_varnish_cache';

    const pluginVersion = '1.0.0';
    const ocRulesVersion = 1.2;
    const HTTPS = 'https://';
    const HTTP = 'http://';
    const ONECOM_HEADER_BEGIN_TEXT = '# One.com response headers BEGIN';


    private $OCVer;
    private $logger;

    public $VCPATH;
    public $OCVCPATH;
    public $OCVCURI;
    public $state = 'false';
    public $blog_url;

    private $messages = array();

    public function __construct()
    {
        $this->OCVCPATH = dirname(__FILE__);
        $this->OCVCURI = plugins_url(null, __FILE__);
        $this->VCPATH = dirname($this->OCVCPATH);

        $this->blog_url = get_option('home');
        $this->purge_id = $this->oc_json_get_option('onecom_vcache_info', 'vcache_purge_id');


        /**
         * This commented becuase performance cache is available to all now.
         * and Enable disable settings works with activation/deactivation hooks, no need to do it on each page load
         * @todo - to be deleted after a while if all works well
         */
        add_action('admin_init', array($this, 'runAdminSettings'), 1);

        add_action('admin_menu', array($this, 'remove_parent_page'), 100);
        add_action('admin_menu', array($this, 'add_menu_item'));

        add_action('admin_init', array($this, 'options_page_fields'));
        add_action('plugins_loaded', array($this, 'filter_purge_settings'), 1);

        add_action('admin_enqueue_scripts', array($this, 'enqueue_resources'));
        add_action('admin_head', array($this, 'onecom_vcache_icon_css'));

        add_action('wp_ajax_oc_set_vc_state', array($this, 'oc_set_vc_state_cb'));
        add_action('wp_ajax_oc_set_vc_ttl', array($this, 'oc_set_vc_ttl_cb'));
        add_action('upgrader_process_complete', array($this, 'oc_upgrade_housekeeping'), 10, 2);
        add_action('plugins_loaded', array($this, 'oc_update_headers_htaccess'));
        add_action('switch_theme', [$this, 'purge_theme_cache']);


        // remove purge requests from Oclick demo importer
        add_filter('vcaching_events', array($this, 'vcaching_events_cb'));
        //intercept the list of urls, replace multiple urls with a single generic url
        add_filter('vcaching_purge_urls', array($this, 'vcaching_purge_urls_cb'));

        register_activation_hook($this->VCPATH . DIRECTORY_SEPARATOR . 'vcaching.php', array($this, 'onActivatePlugin'));
        register_deactivation_hook($this->VCPATH . DIRECTORY_SEPARATOR . 'vcaching.php', array($this, 'onDeactivatePlugin'));
        $exclude_cache = new OnecomExcludeCache();

    }

    /**
     * Function to load ocver
     */
    public function loadOCVer()
    {
        $this->OCVer = new OCVer(true, self::pluginName, 13);
        $is_admin = is_admin();
        $isVer = $this->OCVer->isVer(self::pluginName, $is_admin);
        if ("false" == get_site_option(self::defaultPrefix . 'enable')) {
            self::disableDefaultSettings();
        } else if ('true' === $isVer) {
            self::setDefaultSettings();
            $this->state = 'true';
        }
    }

    /**
     * To retain the check in cache settings after plugin redesign
     */
    public function isVer()
    {
        $this->OCVer = new OCVer(true, self::pluginName, 13);
        $is_admin = is_admin();
        return $this->OCVer->isVer(self::pluginName, $is_admin);
    }

    /**
     * Function to run admin settings
     */
    public function runAdminSettings()
    {
        if ('false' !== $this->state) {
            return;
        }

        // Following removes admin bar purge link, so commented
        // add_action( 'admin_bar_menu', array( $this, 'remove_toolbar_node' ), 999 );

        add_filter('post_row_actions', array($this, 'remove_post_row_actions'), 10, 2);
        add_filter('page_row_actions', array($this, 'remove_page_row_actions'), 10, 2);
    }

    /**
     * Function will execute after plugin activated
     *
     **/
    public function onActivatePlugin()
    {
        global $pagenow;
        if ($pagenow === 'plugins.php') {
            $referrer = 'plugins_page';
        } else {
            $referrer = 'install_wizard';
        }
        self::setDefaultSettings();
    }

    /**
     * Function will execute after plugin deactivated
     */
    public function onDeactivatePlugin()
    {
        self::disableDefaultSettings($onDeactivate = true);
        self::purgeAll();
    }

    /**
     * Function to make some checks to ensure best usage
     **/
    private function runChecklist()
    {
        $this->oc_upgrade_housekeeping('activate');

        // If not exist, then return
        if (!in_array('vcaching/vcaching.php', (array)get_option('active_plugins'))) {
            return true;
        }

        $this->logger->wpAPISendLog('already_exists', self::pluginName, self::pluginName . 'DefaultWP Caching plugin already exists.', self::pluginVersion);
        add_action('admin_notices', array($this, 'duplicateWarning'));

        return false;
    }

    /*
     * Show Admin notice
     */
    public function duplicateWarning()
    {

        $screen = get_current_screen();
        $warnScreens = array(
            'toplevel_page_onecom-vcache-plugin',
            'plugins',
            'options-general',
            'dashboard',
        );

        if (!in_array($screen->id, $warnScreens)) {
            return;
        }

        $class = 'notice notice-warning is-dismissible';

        $dectLink = add_query_arg(
            array(
                'disable-old-varnish' => 1,
                '_wpnonce' => wp_create_nonce('disable-old-varnish')
            )
        );

        $dectLink = wp_nonce_url($dectLink, 'plugin-deactivation');
        $message = __('To get the best out of One.com Performance Cache, kindly deactivate the existing "Varnish Caching" plugin.&nbsp;&nbsp;', 'vcaching');
        $message .= sprintf("<a href='%s' class='button'>%s</a>", ($dectLink), __('Deactivate'));
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    }

    /* Function to convert boolean to string
     *
     *
     */
    private function booleanCast($value)
    {
        if (!is_string($value)) {
            $value = (1 === $value || TRUE === $value) ? 'true' : 'false';
        }
        if ('1' === $value) {
            $value = 'true';
        }
        if ('0' === $value) {
            $value = 'false';
        }
        return $value;
    }


    /**
     * Function to set default settings for one.com
     *
     **/
    private function setDefaultSettings()
    {
        // Enable by default
        $enable = $this->booleanCast(self::defaultEnable);
        $enabled = update_option(self::defaultPrefix . 'enable', $enable);
        $check = get_option(self::defaultPrefix . 'enable', $enable);
        if (!($check === "true" || $check === true || $check === 1)) {
            return;
        }

        // Update the cookie name
        if (!get_option(self::defaultPrefix . 'cookie')) {
            $name = sha1(md5(uniqid()));
            update_option(self::defaultPrefix . 'cookie', $name);
        }

        // Set default TTL
        $ttl = self::defaultTTL;
        $ttl_unit = self::defaultTTLUnit;
        if (!get_option(self::defaultPrefix . 'ttl') && !is_bool(get_option(self::defaultPrefix . 'ttl')) && get_option(self::defaultPrefix . 'ttl') != 0) {
            update_option(self::defaultPrefix . 'ttl', $ttl);
            update_option(self::defaultPrefix . 'ttl_unit', $ttl_unit);
        } elseif (!get_option(self::defaultPrefix . 'ttl') && is_bool(get_option(self::defaultPrefix . 'ttl'))) {
            update_option(self::defaultPrefix . 'ttl', $ttl);
            update_option(self::defaultPrefix . 'ttl_unit', $ttl_unit);
        }
        if (!get_option(self::defaultPrefix . 'homepage_ttl') && !is_bool(get_option(self::defaultPrefix . 'homepage_ttl')) && get_option(self::defaultPrefix . 'homepage_ttl') != 0) {
            update_option(self::defaultPrefix . 'homepage_ttl', $ttl);
            update_option(self::defaultPrefix . 'ttl_unit', $ttl_unit);
        } elseif (!get_option(self::defaultPrefix . 'homepage_ttl') && is_bool(get_option(self::defaultPrefix . 'homepage_ttl'))) {
            update_option(self::defaultPrefix . 'homepage_ttl', $ttl);
            update_option(self::defaultPrefix . 'ttl_unit', $ttl_unit);
        }

        // Set default varnish IP
        $ip = getHostByName(getHostName());
        update_option(self::defaultPrefix . 'ips', $ip);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            update_option(self::defaultPrefix . 'debug', true);
        }

        // Deactivate the old varnish caching plugin on user's consent.
        if (isset($_REQUEST['disable-old-varnish']) && $_REQUEST['disable-old-varnish'] == 1) {
            deactivate_plugins('/vcaching/vcaching.php');
            self::runAdminSettings();
            add_action('admin_bar_menu', array($this, 'remove_toolbar_node'), 999);
        }

        // Check and notify if varnish plugin already active.
        if (in_array('vcaching/vcaching.php', (array)get_option('active_plugins'))) {
            add_action('admin_notices', array($this, 'duplicateWarning'));
        }
    }

    /**
     * Function to disable varnish plugin
     *
     **/
    private function disableDefaultSettings($onDeactivate = false)
    {
        // Disable by default
        // $enable = $this->booleanCast( false );
        // $disabled = update_option( self::defaultPrefix . 'enable', $enable );
        $disabled = false;
        $action = (TRUE === $onDeactivate) ? 'disableManual' : 'featureDisabled';
        if ($disabled) {
            $this->logger->log($message = self::pluginName . ' feature disabled ' . $action);
            self::purgeAll();
        }
        // Intentionally commented the auto-turn-off on package downgrade
        // BECAUSE it is causing auto-ON
        delete_option(self::defaultPrefix . 'ttl');
        delete_option(self::defaultPrefix . 'homepage_ttl');
        delete_option(self::defaultPrefix . 'ttl_unit');
        delete_option("onecom_vcache_info");

    }

    /**
     * Remove current menu item
     */
    public function remove_parent_page()
    {
        remove_menu_page('vcaching-plugin');
    }

    /**
     * Add menu item
     */
    public function add_menu_item()
    {
        if (parent::check_if_purgeable()) {
            global $onecom_generic_menu_position;
            $position = (function_exists('onecom_get_free_menu_position') && !empty($onecom_generic_menu_position)) ? onecom_get_free_menu_position($onecom_generic_menu_position) : null;
            add_menu_page(__('Performance Cache', 'vcaching'), __('Performance Cache&nbsp;', 'vcaching'), 'manage_options', self::pluginName . '-plugin', array($this, 'settings_page'), 'dashicons-dashboard', $position);

        }
    }

    /**
     * Function to show settings page
     */
    public function settings_page()
    {
        include_once $this->OCVCPATH . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'cache-settings.php';
    }

    public static function cache_settings_page()
    {
        require_once plugin_dir_path(__FILE__) . '/templates/cache-settings.php';
    }

    /**
     * Function to customize options fields
     */
    public function options_page_fields()
    {
        add_settings_section(self::defaultPrefix . 'oc_options', null, null, self::defaultPrefix . 'oc_options');

        add_settings_field(self::defaultPrefix . "ttl", __("Cache TTL", 'vcaching') . '<span class="oc-tooltip"><span class="dashicons dashicons-editor-help"></span><span>' . __('The time that website data is stored in the Varnish cache. After the TTL expires the data will be updated, 0 means no caching.', 'vcaching') . '</span></span>', array($this, self::defaultPrefix . "ttl_callback"), self::defaultPrefix . 'oc_options', self::defaultPrefix . 'oc_options');

        if (isset($_POST['option_page']) && $_POST['option_page'] == self::defaultPrefix . 'oc_options') {
            register_setting(self::defaultPrefix . 'oc_options', self::defaultPrefix . "enable");
            register_setting(self::defaultPrefix . 'oc_options', self::defaultPrefix . "ttl");

            $ttl = $_POST[self::defaultPrefix . 'ttl'];
            $is_update = update_option(self::defaultPrefix . "homepage_ttl", $ttl); //overriding homepage TTL
        }
    }

    /**
     * Function enqueue resources
     */
    public function enqueue_resources($hook)
    {
        $pages = [
            'toplevel_page_onecom-vcache-plugin',
            '_page_onecom-cdn',
            '_page_onecom-wp-rocket',
        ];
        if (!in_array($hook, $pages)) {
            return;
        }

        if (SCRIPT_DEBUG || SCRIPT_DEBUG == 'true') {
            $folder = '';
            $extenstion = '';
        } else {
            $folder = 'min-';
            $extenstion = '.min';
        }

        wp_register_style(
            $handle = self::pluginName,
            $src = $this->OCVCURI . '/assets/' . $folder . 'css/style' . $extenstion . '.css',
            $deps = null,
            $ver = '2.0.0',
            $media = 'all'
        );
        wp_register_script(
            $handle = self::pluginName,
            $src = $this->OCVCURI . '/assets/' . $folder . 'js/scripts' . $extenstion . '.js',
            $deps = ['jquery'],
            $ver = '2.0.0',
            $media = 'all'
        );
        wp_enqueue_style(self::pluginName);
        wp_enqueue_script(self::pluginName);
    }

    /* Function to enqueue style tag in admin head
     * */
    function onecom_vcache_icon_css()
    {
        echo "<style>.toplevel_page_onecom-vcache-plugin > .wp-menu-image{display:flex !important;align-items: center;justify-content: center;}.toplevel_page_onecom-vcache-plugin > .wp-menu-image:before{content:'';background-image:url('" . $this->OCVCURI . "/assets/images/performance-inactive-icon.svg');font-family: sans-serif !important;background-repeat: no-repeat;background-position: center center;background-size: 18px 18px;background-color:#fff;border-radius: 100px;padding:0 !important;width:18px;height: 18px;}.toplevel_page_onecom-vcache-plugin.current > .wp-menu-image:before{background-size: 16px 16px; background-image:url('" . $this->OCVCURI . "/assets/images/performance-active-icon.svg');}.ab-top-menu #wp-admin-bar-purge-all-varnish-cache .ab-icon:before,#wpadminbar>#wp-toolbar>#wp-admin-bar-root-default>#wp-admin-bar-onecom-wp .ab-item:before, .ab-top-menu #wp-admin-bar-onecom-staging .ab-item .ab-icon:before{top: 2px;}a.current.menu-top.toplevel_page_onecom-vcache-plugin.menu-top-last{word-spacing: 10px;}@media only screen and (max-width: 960px){.auto-fold #adminmenu a.menu-top.toplevel_page_onecom-vcache-plugin{height: 55px;}}</style>";
        return;
    }

    /**
     * Function to purge all
     */
    private function purgeAll()
    {
        $pregex = '.*';
        $purgemethod = 'regex';
        $path = '/';
        $schema = self::HTTP;

        $ip = get_option(self::defaultPrefix . 'ips');

        $purgeme = $schema . $ip . $path . $pregex;

        $headers = array(
            'host' => $_SERVER['SERVER_NAME'],
            'X-VC-Purge-Method' => $purgemethod,
            'X-VC-Purge-Host' => $_SERVER['SERVER_NAME']
        );
        $response = wp_remote_request(
            $purgeme,
            array(
                'method' => 'PURGE',
                'headers' => $headers,
                "sslverify" => false
            )
        );
        if ($response instanceof WP_Error) {
            error_log("Cannot purge: " . $purgeme);
        }
    }

    /**
     * Function to change purge settings
     */
    public function filter_purge_settings()
    {
        add_filter('ocvc_purge_notices', array($this, 'ocvc_purge_notices_callback'), 10, 2);
        add_filter('ocvc_purge_url', array($this, 'ocvc_purge_url_callback'), 1, 3);
        add_filter('ocvc_purge_headers', array($this, 'ocvc_purge_headers_callback'), 1, 2);
        add_filter('ocvc_permalink_notice', array($this, 'ocvc_permalink_notice_callback'));
        add_filter('vcaching_purge_urls', array($this, 'vcaching_purge_urls_callback'), 10, 2);

        add_action('admin_notices', array($this, 'oc_vc_notice'));
    }

    /**
     * Function to filter the purge request response
     *
     * @param object $response //request response object
     * @param string $url // url trying to purge
     */
    public function ocvc_purge_notices_callback($response, $url)
    {

        $response = wp_remote_retrieve_body($response);

        $find = array(
            '404 Key not found' => sprintf(__('It seems that %s is already purged. There is no resource in the cache to purge.', 'vcaching'), $url),
            'Error 200 Purged' => sprintf(__('%s is purged successfully.', 'vcaching'), $url),
        );

        foreach ($find as $key => $message) {
            if (strpos($response, $key) !== false) {
                array_push($this->messages, $message);
            }
        }


    }

    /**
     * Function to add notice
     */
    public function oc_vc_notice()
    {
        if (empty($this->messages) && empty($_SESSION['ocvcaching_purge_note'])) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <ul>
                <?php
                if (!empty($this->messages)) {
                    foreach ($this->messages as $key => $message) {
                        if ($key > 0) {
                            break;
                        }
                        ?>
                        <li><?php echo $message; ?></li>
                        <?php
                    }
                } elseif (!empty($_SESSION['ocvcaching_purge_note'])) {
                    foreach ($_SESSION['ocvcaching_purge_note'] as $key => $message) {
                        if ($key > 0) {
                            break;
                        }
                        ?>
                        <li><?php echo $message; ?></li>
                        <?php
                    }

                }
                ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Function to change purge URL
     *
     * @param string $url //URL to be purge
     * @param string $path //Path of URL
     * @param string $prefex //Regex if any
     * @return string $purgeme //URL to be purge
     */
    public function ocvc_purge_url_callback($url, $path, $pregex)
    {
        $p = parse_url($url);

        $scheme = (isset($p['scheme']) ? $p['scheme'] : '');
        $host = (isset($p['host']) ? $p['host'] : '');
        $purgeme = $scheme . '://' . $host . $path . $pregex;

        return $purgeme;
    }

    /**
     * Function to change purge request headers
     *
     * @param string $url //URL to be purge
     * @param array $headers //Headers for the request
     * @return array $headers //New headers
     */
    public function ocvc_purge_headers_callback($url, $headers)
    {
        $p = parse_url($url);
        if (isset($p['query']) && ($p['query'] == 'vc-regex')) {
            $purgemethod = 'regex';
        } else {
            $purgemethod = 'exact';
        }
        $headers['X-VC-Purge-Host'] = $_SERVER['SERVER_NAME'];
        $headers['host'] = $_SERVER['SERVER_NAME'];
        $headers['X-VC-Purge-Method'] = $purgemethod;
        return $headers;
    }

    /**
     * Function to change permalink message
     */
    public function ocvc_permalink_notice_callback($message)
    {
        $message = __('A custom URL or permalink structure is required for the Performance Cache plugin to work correctly. Please go to the <a href="options-permalink.php">Permalinks Options Page</a> to configure them.', 'vcaching');
        return '<div class="notice notice-warning"><p>' . $message . '</p></div>';
    }


    /**
     * Function to to remove menu item from admin menu bar
     */
    public function remove_toolbar_node($wp_admin_bar)
    {
        // replace 'updraft_admin_node' with your node id
        $wp_admin_bar->remove_node('purge-all-varnish-cache');
    }

    /**
     * Function to remove purge cache from post
     */
    public function remove_post_row_actions($actions, $post)
    {
        if (isset($actions['vcaching_purge_post'])) {
            unset($actions['vcaching_purge_post']);
        }
        return $actions;
    }

    /**
     * Function to remove purge cache from page
     */
    public function remove_page_row_actions($actions, $post)
    {
        if (isset($actions['vcaching_purge_page'])) {
            unset($actions['vcaching_purge_page']);
        }
        return $actions;
    }

    /**
     * Function to set purge single post/page URL
     *
     * @param array $array // array of urls
     * @param number $post_id //POST ID
     */
    public function vcaching_purge_urls_callback($array, $post_id)
    {
        $url = get_permalink($post_id);
        array_unshift($array, $url);
        return $array;
    }

    /**
     * Function vcaching_events_cb
     * Callback function for vcaching_events WP filter
     * This function checks if the registered events are to be returned, judging from request payload.
     * e.g. the events are nulled for request actions like "heartbeat" and  "ocdi_import_demo_data"
     * @param $events , an array of events on which caching is hooked.
     * @return array
     */
    function vcaching_events_cb($events)
    {

        $no_post_action = !isset($_REQUEST['action']);
        $action_not_watched = isset($_REQUEST['action']) && ($_REQUEST['action'] === 'ocdi_import_demo_data' || $_REQUEST['action'] === 'heartbeat');

        if ($no_post_action || $action_not_watched) {
            return [];
        } else {
            return $events;
        }
    }

    /**
     * Function vcaching_purge_urls_cb
     * Callback function for vcaching_purge_urls WP filters
     * This function removes all the urls that are to be purged and returns single url that purges entire cache.
     * @param $urls , an array of urls that were originally to be purged.
     * @return array
     */
    function vcaching_purge_urls_cb($urls)
    {
        $site_url = trailingslashit(get_site_url());
        $purgeUrl = $site_url . '.*';
        $urls = array($purgeUrl);
        return $urls;
    }

    /**
     * Function oc_set_vc_state_cb()
     * Enable/disable vcaching. Used as AJAX callback
     * @param null
     * @return null
     * @since v0.1.24
     */
    public function oc_set_vc_state_cb()
    {
        if (!isset($_POST['oc_csrf']) && !wp_verify_nonce('one_vcache_nonce')) {
            return false;
        }
        $state = intval($_POST['vc_state']) === 0 ? "false" : "true";

        // check eligibility if Performance Cache is being enabled. If it is being disabled, allow to continue
        if ($state == "true") {
            $event_action = 'enable';
        } else {
            $event_action = 'disable';
        }

        if (get_site_option(self::defaultPrefix . 'enable') == $state) {
            $result_status = true;
        } else {
            $result_status = update_site_option(self::defaultPrefix . 'enable', $state);
        }
        $result_ttl = $this->oc_set_vc_ttl_cb(false);
        $response = [];
        if ($result_ttl && $result_status) {
            $response = [
                'status' => 'success',
                'message' => __('Performance cache settings updated', 'vcaching')
            ];
        } else {
            $response = [
                'status' => 'error',
                'message' => __('Something went wrong!', 'vcaching')
            ];
        }
        wp_send_json($response);
    }

    public function oc_set_vc_ttl_cb($echo)
    {

        if (wp_doing_ajax() && !isset($_POST['oc_csrf']) && !wp_verify_nonce('one_vcache_nonce')) {
            return false;
        }
        if ($echo === '') {
            $echo = true;
        }
        $ttl_value = intval(trim($_POST['vc_ttl']));
        $ttl = $ttl_value === 0 ? 2592000 : $ttl_value;
        $ttl_unit = trim($_POST['vc_ttl_unit']);
        $ttl_unit = empty($ttl_unit) ? 'days' : $ttl_unit;

        // Convert into seconds except default value
        if ($ttl != 2592000 && $ttl_unit == 'minutes') {
            $ttl = $ttl * 60;
        } else if ($ttl != 2592000 && $ttl_unit == 'hours') {
            $ttl = $ttl * 3600;
        } else if ($ttl != 2592000 && $ttl_unit == 'days') {
            $ttl = $ttl * 86400;
        }

        if ((get_site_option('varnish_caching_ttl') == $ttl) && (get_site_option('varnish_caching_homepage_ttl') == $ttl) && (get_site_option('varnish_caching_ttl_unit') == $ttl_unit)) {
            $result = true;
        } else {
            $result = update_site_option('varnish_caching_ttl', $ttl);
            update_site_option('varnish_caching_homepage_ttl', $ttl);
            update_site_option('varnish_caching_ttl_unit', $ttl_unit);
        }
        $response = [];
        if ($result) {
            $response = [
                'status' => 'success',
                'message' => __('TTL updated', 'vcaching')
            ];
        } else {
            $response = [
                'status' => 'error',
                'message' => __('Something went wrong!', 'vcaching')
            ];
        }
        if ($echo) {
            wp_send_json($response);
        } else {
            return $result;
        }
    }

    /**
     * Function rewrite
     * Rewrite assets url, replace native ones with the CDN version if the url meets rewrite conditions.
     * @param array $html , the html source of the page, provided by ob_start
     * @return string modified html source
     * @since v0.1.24
     */
    public function rewrite($html)
    {
        $url = get_option('home');
        $protocols = [self::HTTPS, self::HTTP, "/"];
        $domain_name = str_replace($protocols, "", $url);

        $directories = 'wp-content';
        $pattern = "/(?:https:\/\/$domain_name\/$directories)(\S*\.[0-9a-z]+)\b/m";
        $updated_html = preg_replace_callback($pattern, [$this, 'rewrite_asset_url'], $html);
        return $updated_html;
    }

    /**
     * Function rewrite_asset_url
     * Returns the url that is to be modified to point to CDN.
     * This function acts as a callback to preg_replace_callback called in rewrite()
     * @param array $asset , first element in the array will have the url we are interested in.
     * @return string modified single url
     * @since v0.1.24
     */
    protected function rewrite_asset_url($asset)
    {
        /**
         * Set conditions to rewrite urls.
         * To maintain consistency, write conditions in a way that if they yield positive value,
         * the url should not be modified
         */
        $preview_condition = (is_admin_bar_showing() && array_key_exists('preview', $_GET) && $_GET['preview'] == 'true');
        $path_condition = (strpos($asset[0], 'wp-content') === false);
        //skip cdn rewrite in yoast-schema-graph
        $skip_yoast_path = (strpos($asset[0], 'contentUrl') !== false);
        $extension_condition = (strpos($asset[0], '.php') !== false);
        $existing_live = get_option('onecom_staging_existing_live');

        $staging_condition = (!empty($existing_live) && isset($existing_live->directoryName));
        $template_path_condition = ((strpos($asset[0], 'plugins') !== false) && (strpos($asset[0], 'assets/templates') !== false));

        // If any condition is true, skip cdn rewrite
        if ($preview_condition || $path_condition || $extension_condition || $staging_condition || $template_path_condition || $skip_yoast_path) {
            return $asset[0];
        }

        $blog_url = $this->relative_url($this->blog_url);
        // both http and https urls are to be replaced
        $subst_urls = [
            'http:' . $blog_url,
            'https:' . $blog_url,
        ];


        // Get all rules in array
        $cdn_exclude = $this->oc_json_get_option('onecom_vcache_info', 'oc_exclude_cdn_data');
        $oc_exclude_cdn_status = $this->oc_json_get_option('onecom_vcache_info', 'oc_exclude_cdn_enabled');
        $explode_rules = explode("\n", $cdn_exclude);

        // If CDN exclude is enabled and any rule exists
        if ($oc_exclude_cdn_status == "true" && count($explode_rules) > 0) {
            // If any rule match to exclude CDN, replace CDN with domain URL
            foreach ($explode_rules as $explode_rule) {
                // If rule start with dot (.), check for file extension,
                if (strpos($explode_rule, $asset[0]) === 0 && !empty(trim($explode_rule))) {
                    // Exclude if current URL have given file extension
                    if (substr_compare($explode_rule, $asset[0], -strlen($asset[0])) === 0) {
                        return $asset[0];
                    }
                    return $asset[0];
                } else if (strpos($asset[0], $explode_rule) > 0 && !empty(trim($explode_rule))) {
                    // else simply exclude folder/path etc if rule string find anywhere
                    return $asset[0];
                }
            }
        }

        // is it a protocol independent URL?
        if (strpos($asset[0], '//') === 0) {
            $final_url = str_replace($blog_url, $this->cdn_url, $asset[0]);
        }

        // check if not a relative path
        if (strpos($asset[0], $blog_url) !== 0) {
            $final_url = str_replace($subst_urls, $this->cdn_url, $asset[0]);
        }

        /**
         *  Append query paramter to purge CDN files
         *  * rawurlencode() to handle CDN Purge with Brizy builder URLs
         */
        if ($this->purge_id && strpos($final_url, 'wp-content/uploads/brizy/')) {
            // raw_url_encode with add_query_arg if used in other cases will return unexpected results such as /?ver?media
            $new_url = add_query_arg('media', $this->purge_id, rawurlencode($final_url));

            return rawurldecode($new_url);
        } elseif ($this->purge_id) {
            return add_query_arg('media', $this->purge_id, $final_url);
        } else {
            return $final_url;
        }


        // relative URL
        return $this->cdn_url . $asset[0];
    }


    /**
     * Function relative_url
     * Check if given string is a relative url
     * @param string $url
     * @return string
     * @since v0.1.24
     */
    protected function relative_url($url)
    {
        return substr($url, strpos($url, '//'));
    }


    /**
     * Function oc_upgrade_housekeeping
     * Perform actions after plugin is upgraded or activated
     * @param $upgrade_data - data passed by WP hooks, used only in case of activation
     * @return void
     * @since v0.1.24
     */
    public function oc_upgrade_housekeeping($upgrade_data = null, $options = null)
    {

        // exit if this plugin is not being upgraded
        if ($options && isset($options['pugins']) && !in_array('onecom-vcache/vcaching.php', $options['plugins'])) {
            return;
        }

        $existing_version_db = trim(get_site_option('onecom_plugin_version_vcache'));
        $current_version = trim(self::pluginVersion);

        //exit if plugin version is same in plugin and DB. If plugin is activated, bypass this condition
        if (($existing_version_db == $current_version) && ($upgrade_data !== 'activate')) {
            return;
        }
        // update plugin version in DB
        update_site_option('onecom_plugin_version_vcache', $current_version);

        // if current subscription is eligible for Performance Cache, enable the plugins
        if (get_site_option(self::defaultPrefix . 'enable') == '') {
            update_site_option(self::defaultPrefix . 'enable', "true");
        }

        if (get_site_option('oc_cdn_enabled') == '') {
            update_site_option('oc_cdn_enabled', "true");
        }

        //set TTL for varnish caching, default for 1 month in seconds
        if (get_site_option('varnish_caching_ttl') == '') {
            update_site_option('varnish_caching_ttl', '2592000');
        }
        if (get_site_option('varnish_caching_homepage_ttl') == '') {
            update_site_option('varnish_caching_homepage_ttl', '2592000');
        }

    }

    function oc_update_headers_htaccess()
    {

        // exit if not logged in or not admin
        $user = wp_get_current_user();
        if ((!isset($user->roles)) || (!in_array('administrator', (array)$user->roles))) {
            return;
        }

        // exit for some of the common conditions
        if (
            defined('XMLRPC_REQUEST')
            || defined('DOING_AJAX')
            || defined('IFRAME_REQUEST')
            || (function_exists('wp_is_json_request') && wp_is_json_request())
        ) {
            return;
        }

        // check if CDN is enabled 
        $cdn_enabled = get_site_option('oc_cdn_enabled');
        if ($cdn_enabled != 'true') {
            return;
        }
        // check if rules version is saved. If saved, do we need to updated them?
        // removed to match the site URL

        $origin = !empty(site_url()) ? site_url() : '*';

        $file = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . '.htaccess';
        $rules = self::ONECOM_HEADER_BEGIN_TEXT
            . PHP_EOL
            . '<IfModule mod_headers.c>
    <FilesMatch "\.(ttf|ttc|otf|eot|woff|woff2|css|js|png|jpg|jpeg|svg|pdf)$">
        Header set Access-Control-Allow-Origin ' . $origin . '
    </FilesMatch>
</IfModule>' . PHP_EOL . '# One.com response headers END';

        if (file_exists($file)) {
            $contents = @file_get_contents($file);
            // if file exists but rules not found, add them         
            if (strpos($contents, self::ONECOM_HEADER_BEGIN_TEXT) === false) {
                @file_put_contents($file, PHP_EOL . $rules, FILE_APPEND);
            } elseif (!preg_match('~\bHeader set Access-Control-Allow-Origin ' . site_url() . '</FilesMatch>\b~', $contents)
                || preg_match("~\b" . site_url() . "</FilesMatch>\b~", $contents) === 0) { //if file exists, rules are present but existing rules need to be updated due to mismatch of siteurl
                //replace content between our BEGIN and END markers
                $content_array = preg_split('/\r\n|\r|\n/', $contents);
                $start = array_search(self::ONECOM_HEADER_BEGIN_TEXT, $content_array);
                $end = array_search('# One.com response headers END', $content_array);
                $length = ($end - $start) + 1;
                array_splice($content_array, $start, $length, preg_split('/\r\n|\r|\n/', $rules));
                @file_put_contents($file, implode(PHP_EOL, $content_array));
                do_action('onecom_purge_cdn');
            }
        } else {
            @file_put_contents($file, $rules);
        }
        //finally, if file was changed, update the self::ocRulesVersion as oc_rules_version in options for future reference
        update_site_option('oc_rules_version', self::ocRulesVersion);
    }

    function purge_theme_cache()
    {
        wp_remote_request($this->blog_url, ['method' => 'PURGE']);

    }
}

$OCVCaching = new OCVCaching();
