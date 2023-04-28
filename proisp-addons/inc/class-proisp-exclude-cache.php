<?php
class PROISP_Exclude_Cache
{
    public function __construct()
    {
        add_action('init', array($this, 'oc_vcache_register_post_meta'));
        add_action('add_meta_boxes', array($this, 'exclude_from_cache'), 10, 2);
        add_action('save_post', array($this, 'oct_save_exclude_cache'), 10, 2);
        add_action('enqueue_block_editor_assets', array($this, 'oc_block_script_enqueue'));
    }

    /**
     * adds a metabox for the posts & pages to be excluded from cache
     */
    public function exclude_from_cache()
    {
        add_meta_box(
            'exclude-vcache',
            esc_html__('Exclude from Performance cache', 'proisp-vcache'),
            [$this, 'exclude_from_cache_metabox'],
            ['post', 'page'],
            'side',
            'default',
            array(
                '__block_editor_compatible_meta_box' => true,
                '__back_compat_meta_box' => true,
            )

        );

    }

    /**
     * @param $post
     * callback function for backword compatibility / classic editor plugin installed.
     */
    public function exclude_from_cache_metabox($post)
    {
        wp_nonce_field(basename(__FILE__), 'oct-exclude-from-cache');
        $excluded_from_cache = get_post_meta($post->ID, '_oct_exclude_from_cache', true);
        ?>
        <p>
        <label for="oc-exclude-from-cache">
            <input type="checkbox" name="oc-exclude-from-cache" id="oc-exclude-cache"
                   value="<?php echo esc_attr( $excluded_from_cache ); ?>>" <?php if (isset ($excluded_from_cache)) {
                checked($excluded_from_cache, true);
            } ?> />
            <?php esc_html_e('Exclude', 'proisp-vcache'); ?>
        </label>
        </p><?php
    }


    /**
     * @param $post_id
     * @return mixed
     * saves the metabox value.
     * function for backword compatibility / classic editor plugin installed.
     */
    function oct_save_exclude_cache($post_id, $post)
    {
        $oct_exclude_from_cache = sanitize_text_field($_POST['oct-exclude-from-cache']);
        $oc_exclude_from_cache  = sanitize_text_field($_POST['oc-exclude-from-cache']);
        // replace url to be purged with a better approach
        $response = wp_remote_request(get_site_url() . '?p=' . $post_id, ['method' => 'PURGE']);

        if (!isset($oct_exclude_from_cache) || !wp_verify_nonce($oct_exclude_from_cache, basename(__FILE__))) {
            return $post_id;
        }


        if (isset($oc_exclude_from_cache)) {
            update_post_meta($post_id, '_oct_exclude_from_cache', true);
        } else {
            update_post_meta($post_id, '_oct_exclude_from_cache', false);
        }

    }
    public function oc_block_script_enqueue()
    {
        global $pagenow;
        if ($pagenow !== 'post.php') {
            return;
        }
        wp_enqueue_script(
            'block-metabox',
            plugins_url('../assets/js/blocks/block-metabox.js', __FILE__),
            array('wp-edit-post')
        );

        wp_localize_script(
            'block-metabox',
            'blockObject',
            array(
                'label' => 'Exclude from cache',
                'excludeText' => 'This %s will be excluded from cache.',
                'includeText' => 'This %s will be cached.',

            )
        );
    }

    /**
     * registers post meta for exclude cache
     */
    public function oc_vcache_register_post_meta()
    {
        if (function_exists('register_post_meta')) {
            register_post_meta('', '_oct_exclude_from_cache', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'boolean',
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                }
            ));
        } else {
            $this->oc_register_post_meta('', '_oct_exclude_from_cache', array(
                'show_in_rest' => true,
                'single' => true,
                'type' => 'boolean',
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                }
            ));
        }
    }

    /**
     * @param $post_type
     * @param $meta_key
     * @param array $args
     * @return bool
     * For backward compatibility with versions older than WP 4.9
     */
    public function oc_register_post_meta($post_type, $meta_key, array $args)
    {
        $args['object_subtype'] = $post_type;

        return register_meta('post', $meta_key, $args);
    }
}

