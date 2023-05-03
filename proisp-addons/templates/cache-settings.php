<?php
$oc_vache = new PROISP_VCaching_Config();

$pc_checked = '';
$performance_icon = $oc_vache->OCVCURI . '/assets/images/pcache-icon.svg';
$varnish_caching = get_site_option(PROISP_VCaching_Config::defaultPrefix . 'enable');
$proisp_varnish_caching_ttl = get_site_option('proisp_varnish_caching_ttl');
$proisp_varnish_caching_ttl_unit = get_site_option('proisp_varnish_caching_ttl_unit');

if ($varnish_caching == "true") {
    $pc_checked = 'checked';
}
$oc_nonce = wp_create_nonce('one_vcache_nonce');

?>
<!-- Main Wrapper -->
<div class="wrap oc-premium'" id="proisp-wrap">

    <!-- Important placeholder for one.com notifications -->
    <div class="proisp-notifier"></div>

    <!-- Page Header -->
    <div class="oc-page-header">
        <h1 class="main-heading">
            <?php echo esc_html__('Performance Tools', 'proisp-vcache'); ?>
        </h1>

        <div class="page-description">
            <?php echo esc_html__('Tools to help you improve your website’s performance', 'proisp-vcache'); ?>
        </div>
    </div>

    <!-- Main content -->
    <div class='inner-wrap'>
        <div class='oc-row oc-pcache'>
            <div class='oc-column oc-left-column'>
                <div class="oc-flex-center oc-icon-box">
                    <img id="oc-performance-icon" width="48" height="48" src="<?php echo esc_url($performance_icon); ?>"
                         alt="one.com"/>
                    <h2 class="main-heading"><?php echo esc_html__('Performance Cache', 'proisp-vcache'); ?></h2>
                </div>
                <p>
                    <?php echo esc_html__('Caching saves a copy of your website, which will then be shown to the next visitors of your site. This results in faster loading times and can improve your SEO ranking.', 'proisp-vcache'); ?>
                </p>
                <div class="oc-descripton-spacing"></div>
                <p>
                    <a href="<?php echo wp_nonce_url(add_query_arg($oc_vache->purgeCache, 1), 'proisp-vcache'); ?>"
                       class="oc-btn oc-btn-secondary oc-clear-cache-cta"
                       title="<?php echo __('Clear Cache now', 'proisp-vcache'); ?>"> <?php echo __('Clear Cache now', 'proisp-vcache'); ?></a>
                </p>
            </div>
            <div class='oc-column oc-right-column'>
                <div class="pc-settings">

                    <div class="oc-block">
                        <label for="pc_enable" class="oc-label">
                            <span class="oc_cb_switch">
                                <input type="checkbox" id="pc_enable" data-target="pc_enable_settings" name="show"
                                       value=1 <?php echo esc_attr($pc_checked); ?> />
                                <span class="oc_cb_slider" data-target="oc-performance-icon"
                                      data-target-input="pc_enable"></span>
                            </span><?php echo __("Enable Performance Cache", 'proisp-vcache'); ?>
                        </label><span id="oc_pc_switch_spinner" class="oc_cb_spinner spinner"></span>
                    </div>

                    <div id="pc_enable_settings"
                         style="display:<?php echo $pc_checked === 'checked' ? 'block' : 'none' ?>;">
                        <?php
                        if ($proisp_varnish_caching_ttl_unit == 'minutes') {
                            $vc_ttl_as_unit = $proisp_varnish_caching_ttl / 60;
                        } else if ($proisp_varnish_caching_ttl_unit == 'hours') {
                            $vc_ttl_as_unit = $proisp_varnish_caching_ttl / 3600;
                        } else if ($proisp_varnish_caching_ttl_unit == 'days') {
                            $vc_ttl_as_unit = $proisp_varnish_caching_ttl / 86400;
                        } else {
                            $vc_ttl_as_unit = $proisp_varnish_caching_ttl;
                        }
                        ?>
                        <form method="post" action="options.php">
                            <input type="hidden" name="octracking" value="<?php echo $oc_nonce ?>">
                            <div class="oc-flex-fields">
                                <div>
                                    <label class="oc_vcache_ttl_label"><?php _e('Cache TTL', 'proisp-vcache') ?><span
                                            class="oc-tooltip"><span class="dashicons dashicons-info"></span><span
                                                class="tip-content right"><?php echo __('The time that website data is stored in the Varnish cache. After the TTL expires the data will be updated, 0 means no caching.', 'proisp-vcache') ?><i
                                                    aria-hidden="true"></i></span></span></label><br/>
                                    <input type="number" min="0" name="oc_vcache_ttl" class="oc_vcache_ttl"
                                           id="oc_vcache_ttl" value="<?php echo esc_attr($vc_ttl_as_unit); ?>"/>

                                </div>
                                <div>
                                    <label class="oc_vcache_ttl_label"><?php _e('Frequency', 'proisp-vcache') ?>
                                        : </label><br/>
                                    <select class="oc-vcache-ttl-select" name="oc_vcache_ttl_unit"
                                            id="oc_vcache_ttl_unit">
                                        <option
                                            value="seconds" <?php if ($proisp_varnish_caching_ttl_unit == "seconds") {
                                            echo "selected";
                                        } ?>><?php _e('Seconds', 'proisp-vcache') ?></option>
                                        <option
                                            value="minutes" <?php if ($proisp_varnish_caching_ttl_unit == "minutes") {
                                            echo "selected";
                                        } ?>><?php _e('Minutes', 'proisp-vcache') ?></option>
                                        <option value="hours" <?php if ($proisp_varnish_caching_ttl_unit == "hours") {
                                            echo "selected";
                                        } ?>><?php _e('Hours', 'proisp-vcache') ?></option>
                                        <option value="days" <?php if ($proisp_varnish_caching_ttl_unit == "days") {
                                            echo "selected";
                                        } ?>><?php _e('Days', 'proisp-vcache') ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="oc-form-footer oc-desktop-view">
                                <div class="oc-flex-center save-box">
                                    <button type="button"
                                            class="oc_vcache_btn oc_ttl_save no-right-margin oc-btn oc-btn-primary"><?php _e('Save', 'proisp-vcache') ?></button>
                                    <span class="oc_cb_spinner oc_ttl_spinner spinner"></span>
                                </div>
                            </div>
                            <div class="oc-form-footer oc_sticky_footer">
                                <div class="oc-flex-center save-box">
                                    <button type="button"
                                            class="oc_vcache_btn oc_ttl_save no-right-margin oc-btn oc-btn-primary"><?php _e('Save', 'proisp-vcache') ?></button>
                                    <span class="oc_cb_spinner oc_ttl_spinner spinner"></span>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="clear"></div>