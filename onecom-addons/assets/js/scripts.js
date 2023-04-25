jQuery(document).ready(function () {

    // Disable premium fields for non-premium (or downgraded package)
    jQuery(".oc-non-premium #dev_mode_duration").prop('disabled', true);
    jQuery(".oc-non-premium #oc_dev_duration_save").prop('disabled', true);
    jQuery(".oc-non-premium #exclude_cdn_data").prop('disabled', true);
    jQuery(".oc-non-premium .oc_cdn_data_save").prop('disabled', true);
    
    // enable disable save button based on cdn switches state
    oc_cdn_save_state_change();
    
    jQuery('#pc_enable').change(function () {
        ocSetVCState();
    });
    jQuery('.oc_ttl_save').click(function(){
        if (oc_validate_ttl()){
            oc_update_ttl();
        }
    });
    jQuery('.oc_cdn_data_save').click(function(){
        if (oc_validate_cdn_data()) {
            oc_update_cdn_data();
        }
    });

    jQuery('.oc-activate-wp-rocket-btn').click(function(){
        oc_activate_wp_rocket();
    });

    jQuery('#cdn_enable').change(function (){
        ocSetCdnState();
    });
    jQuery('#dev_mode_enable').change(function (){
        jQuery('#dev_mode_duration').removeClass('oc_error');
        ocSetDevMode();
    });
    jQuery('#exclude_cdn_enable').change(function (){
        jQuery('#exclude_cdn_data').removeClass('oc_error');
        ocExcludeCDNState();
    });
});

function oc_toggle_state(element) {
    var current_icon = element.attr('src');
    var new_icon = element.attr('data-alt-image');
    element.attr({
        'src': new_icon,
        'data-alt-image': current_icon
    });
}

function ocSetVCState() {
    jQuery('#oc_pc_switch_spinner').removeClass('success').addClass('is_active');
    let vc_state = jQuery('#pc_enable').prop('checked') ? '1' : '0';
    let vc_ttl = jQuery('#oc_vcache_ttl').val() || '2592000';
    let vc_ttl_unit = jQuery('#oc_vcache_ttl_unit').val() || 'days';
    let ocCsrfToken = jQuery('input[name="octracking"]').val();
    jQuery.post(ajaxurl, {
        action: 'oc_set_vc_state',
        vc_state: vc_state,
        vc_ttl: vc_ttl,
        vc_ttl_unit: vc_ttl_unit,
        oc_csrf : ocCsrfToken
    }, function (response) {
        jQuery('#oc_pc_switch_spinner').removeClass('is_active').addClass('success');
        if (response.status === 'success') {
            oc_toggle_pSection();
            oc_trigger_log({
                actionType: 'wppremium_click_feature',
                isPremium: 'true',
                feature: 'PERFORMANCE_CACHE',
                featureAction: (vc_state == '1') ? 'enable_vcache' : 'disable_vcache'
            });
        }else{
            jQuery('#oc_um_overlay').show();
            ocSetModalData({
                isPremium: 'true',
                feature: 'PERFORMANCE_CACHE',
                featureAction: (vc_state == '1') ? 'enable_vcache' : 'disable_vcache'
            });
            jQuery('#pc_enable').prop('checked', false);
        }
    })
}

function oc_toggle_pSection() {
    if (jQuery('#pc_enable').prop('checked')) {
        if (!jQuery('#oc_vcache_ttl').val()){
            jQuery('#oc_vcache_ttl').val('2592000');
        }
        jQuery('#pc_enable_settings').show();
    } else {
        jQuery('#pc_enable_settings').hide();
    }
}

// If dev mode or exclude cdn switch is enabled, show mobile/desktop save button accordingly
function oc_cdn_save_state_change() {
    if (
        jQuery('.oc-cdn #dev_mode_enable').prop('checked') ||
        jQuery('.oc-cdn #exclude_cdn_enable').prop('checked')
        
    ) {
        if (jQuery(window).width() < 576 ) {
            jQuery('.oc-cdn .oc_sticky_footer').show();
        } else {
            jQuery('.oc-cdn .oc-desktop-view').show();
        }
    } else {
        jQuery('.oc-cdn .oc-form-footer').hide();
    }
}

function oc_toggle_devModeSection() {
    if (jQuery('#dev_mode_enable').prop('checked')) {
        if (!jQuery('#dev_mode_duration').val()){
            jQuery('#dev_mode_duration').val('48');
        }
        jQuery('#dev_mode_enable_settings').show();
    } else {
        jQuery('#dev_mode_enable_settings').hide();
    }

    // enable disable save button
    oc_cdn_save_state_change();
}

function oc_toggle_excludeCDNSection() {
    if (jQuery('#exclude_cdn_enable').prop('checked')) {
        if (!jQuery('#exclude_cdn_data').val()){
            jQuery('#exclude_cdn_data').val('');
        }
        jQuery('#exclude_cdn_enable_settings').show();
    } else {
        jQuery('#exclude_cdn_enable_settings').hide();
    }

    // enable disable save button
    oc_cdn_save_state_change();
}

function oc_validate_ttl(){
    var element = jQuery('#oc_vcache_ttl');
    var ttl_value = element.val();
    let pattern = /^[0-9]\d*$/;
    if (pattern.test(ttl_value)){
        element.removeClass('oc_error');
        return true;
    }else{
        element.addClass('oc_error');
        return false;
    }
}

function oc_validate_cdn_data(){
    let dev_switch = jQuery('#dev_mode_enable');
    let dev_mode_value = jQuery('#dev_mode_duration');
    let cdnSwitch = jQuery('#exclude_cdn_enable');
    let cdnData = jQuery('#exclude_cdn_data');

    // clean existing error state
    dev_mode_value.removeClass('oc_error');
    cdnData.removeClass('oc_error');
    let cdn_data_validated = true;

    // validate dev mode duration - If switched on but invalid data
    let pattern = /^[1-9]\d*$/;
    if (dev_switch.is(":checked") &&  !pattern.test(dev_mode_value.val())){
        dev_mode_value.addClass('oc_error');
        cdn_data_validated = false;
    }

    // validate exlude cdn data - if swtiched on but empty data
    if (cdnSwitch.is(":checked") && cdnData.val().trim() === ""){
        cdnData.addClass('oc_error');
        cdn_data_validated = false;
    }

    // Return based on error
    return cdn_data_validated;
}

function oc_update_ttl(){
    jQuery('.oc_ttl_spinner').removeClass('success').addClass('is_active');
    var vc_ttl = jQuery('#oc_vcache_ttl').val() || '2592000';
    var vc_ttl_unit = jQuery('#oc_vcache_ttl_unit').val() || 'days';
    let ocCsrfToken = jQuery('input[name="octracking"]').val();

    jQuery.post(ajaxurl, {
        action: 'oc_set_vc_ttl',
        vc_ttl: vc_ttl,
        vc_ttl_unit: vc_ttl_unit,
        oc_csrf : ocCsrfToken
    }, function(response){
        jQuery('.oc_ttl_spinner').removeClass('is_active');
        if (response.status === 'success'){
            jQuery('.oc_ttl_spinner').addClass('success');
        }
        if ( ! jQuery('#oc_vcache_ttl').val().trim()){
            jQuery('#oc_vcache_ttl').val('2592000');
        }
    });
}

function ocExcludeCDNState(){
    jQuery('#oc_exclude_cdn_switch_spinner').removeClass('success').addClass('is_active');
    var exclude_cdn_mode = jQuery('#exclude_cdn_enable').prop('checked') ? '1' : '0';
    jQuery.post(ajaxurl, {
        action: 'oc_exclude_cdn_mode',
        exclude_cdn_mode : exclude_cdn_mode,
    }, function(response){
        jQuery('#oc_exclude_cdn_switch_spinner').removeClass('is_active');
        if (response.status === 'success'){
            oc_toggle_excludeCDNSection();
            jQuery('#oc_exclude_cdn_switch_spinner').addClass('success');
            oc_trigger_log({
                actionType: 'wppremium_click_feature',
                isPremium: 'true',
                feature: 'CDN',
                /* featureAction: (dev_mode == '1') ? 'enable_cdn' : 'disable_cdn' */
            });
        }else{
            jQuery('#oc_um_overlay').show();
            jQuery('#exclude_cdn_enable').prop('checked', false);
        }        
    });    
}

function oc_update_cdn_data(){
    jQuery('.oc_cdn_data_save_spinner').removeClass('success').addClass('is_active');
    jQuery.post(ajaxurl, {
        action: 'oc_update_cdn_data',
        exclude_cdn_data: jQuery('#exclude_cdn_data').val() || '',
        dev_duration: jQuery('#dev_mode_duration').val() || '48'
    }, function(response){
        jQuery('.oc_cdn_data_save_spinner').removeClass('is_active');
        if (response.status === 'success'){
            jQuery('.oc_cdn_data_save_spinner').addClass('success');
        }
    });
}

// Set dev mode when switched
function ocSetDevMode(){
    jQuery('#oc_dev_mode_switch_spinner').removeClass('success').addClass('is_active');
    var dev_mode = jQuery('#dev_mode_enable').prop('checked') ? '1' : '0';
    var dev_duration = jQuery('#dev_mode_duration').val() || '48';
    jQuery.post(ajaxurl, {
        action: 'oc_set_dev_mode',
        dev_mode : dev_mode,
        dev_duration: dev_duration
    }, function(response){
        jQuery('#oc_dev_mode_switch_spinner').removeClass('is_active');
        if (response.status === 'success'){
            oc_toggle_devModeSection();
            jQuery('#oc_dev_mode_switch_spinner').addClass('success');
            oc_trigger_log({
                actionType: 'wppremium_click_feature',
                isPremium: 'true',
                feature: 'CDN',
                featureAction: (dev_mode == '1') ? 'enable_cdn' : 'disable_cdn'
            });
        }else{
            jQuery('#oc_um_overlay').show();
            jQuery('#dev_mode_enable').prop('checked', false);
        }        
    });    
}

function ocSetCdnState(){
    jQuery('#oc_cdn_switch_spinner').removeClass('success').addClass('is_active');
    var cdn_state = jQuery('#cdn_enable').prop('checked') ? '1' : '0';
    jQuery.post(ajaxurl, {
        action: 'oc_set_cdn_state',
        cdn_state : cdn_state,
    }, function(response){
        jQuery('#oc_cdn_switch_spinner').removeClass('is_active');
        if (response.status === 'success'){
            jQuery('#oc_cdn_switch_spinner').addClass('success');
            oc_change_cdn_icon();
            oc_trigger_log({
                actionType: 'wppremium_click_feature',
                isPremium: 'true',
                feature: 'CDN',
                featureAction: (cdn_state == '1') ? 'enable_cdn' : 'disable_cdn'
            });
        }else{
            jQuery('#oc_um_overlay').show();
            ocSetModalData({
                isPremium: 'true',
                feature: 'CDN',
                featureAction: (cdn_state == '1') ? 'enable_cdn' : 'disable_cdn'
            });
            jQuery('#cdn_enable').prop('checked', false);
        }        
    });    
}

function oc_change_cdn_icon(){
    if (jQuery('#cdn_enable').prop('checked')) {
        jQuery('#oc-cdn-icon-active').show();
        jQuery('#oc-cdn-icon').hide();
        jQuery('.oc-cdn-feature-box').show();
        // Remove sub features success classes else spinner animate on each switch
        jQuery('.oc-cdn-feature-box .oc_cb_spinner').removeClass('success');
    } else {
        jQuery('#oc-cdn-icon').show();
        jQuery('#oc-cdn-icon-active').hide();
        jQuery('.oc-cdn-feature-box').hide();
        // Remove sub features success classes else spinner animate on each switch
        jQuery('.oc-cdn-feature-box .oc_cb_spinner').removeClass('success');
    }
}

// activate wp rocket button action
function oc_activate_wp_rocket(){
    jQuery('.oc_activate_wp_rocket_spinner').removeClass('success').addClass('is_active');
    jQuery.post(ajaxurl, {
        action: 'oc_activate_wp_rocket'
    }, function(response){
        jQuery('.oc_activate_wp_rocket_spinner').removeClass('is_active');
        if (response.status === true){
            jQuery('.oc_activate_wp_rocket_spinner').addClass('success');
            window.location.href = "options-general.php?page=wprocket";
        } else {
            console.log("Error: Could not activate plugin")
        }
    });
}

function oc_show_more_less(){
    if (jQuery(".oc-hidden-content").css('display') === 'none') {
        jQuery(".oc-show-hide a").text("Show less"); 
        jQuery(".oc-hidden-content").show();
    } else {
        jQuery(".oc-show-hide a").text("Show more"); 
        jQuery(".oc-hidden-content").hide();
    }
}