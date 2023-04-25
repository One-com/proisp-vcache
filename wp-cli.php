<?php

if (!defined('ABSPATH')) {
    die();
}

if (!defined('WP_CLI')){
    return;
}

/**
 * Purges Varnish Cache
 */
class WP_CLI_VCaching_Purge_Command extends WP_CLI_Command {

    public function __construct() {
        $this->vcaching = new VCachingOC();
    }

    /**
     * Forces a Varnish Purge
     *
     * ## wp cli command for purging cache
     *
     *     wp ocvcaching purge
     *
     */
    public function purge() {
        wp_create_nonce('vcaching-purge-cli');
        $this->vcaching->purge_url(home_url() .'/?vc-regex');
        WP_CLI::success('ALL Varnish cache was purged.');
    }
}

WP_CLI::add_command('ocvcaching', 'WP_CLI_VCaching_Purge_Command');
