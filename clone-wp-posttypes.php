<?php
/**
 * Plugin Name: Clone WP Post Types
 * Description: Adds a "Clone" link to custom post types to duplicate them as drafts.
 * Version: 1.0
 * Author: mfaisalakhtar
 */

add_action('admin_init', function() {
    $post_types = get_post_types(['public' => true], 'names');
    foreach ($post_types as $post_type) {
        add_filter("{$post_type}_row_actions", 'cpt_clone_link', 10, 2);
    }
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cpt_clone_settings_link');
function cpt_clone_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=cpt-clone-settings">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

add_filter('page_row_actions', 'cpt_clone_link', 10, 2);

if (!function_exists('cpt_clone_link')) {
    function cpt_clone_link($actions, $post) {
        $enabled = get_option('cpt_clone_enabled_types', []);
        if (!in_array($post->post_type, $enabled)) return $actions;

        if (current_user_can('edit_posts') && $post->post_type !== 'revision') {
            $url = wp_nonce_url(
                admin_url('admin.php?action=cpt_clone_post&post=' . $post->ID),
                'cpt_clone_post_' . $post->ID
            );
            $actions['cpt_clone'] = '<a href="' . esc_url($url) . '" title="' . esc_attr__('Clone this item', 'clone-custom-post-type') . '">' . esc_html__('Clone', 'clone-custom-post-type') . '</a>';
        }
        return $actions;
    }
}

add_action('admin_action_cpt_clone_post', 'cpt_clone_post');
function cpt_clone_post() {
    if (!isset($_GET['post']) || !isset($_GET['_wpnonce'])) {
        wp_die('Invalid request.');
    }

    $post_id = intval($_GET['post']);
    $nonce = $_GET['_wpnonce'];

    if (!wp_verify_nonce($nonce, 'cpt_clone_post_' . $post_id)) {
        wp_die('Security check failed.');
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type === 'revision') {
        wp_die('Post not found or is a revision.');
    }

    $new_post = array(
        'post_title'    => $post->post_title . ' (Clone)',
        'post_content'  => $post->post_content,
        'post_status'   => 'draft',
        'post_type'     => $post->post_type,
        'post_author'   => get_current_user_id(),
    );
    $new_post_id = wp_insert_post($new_post);

    $taxonomies = get_object_taxonomies($post->post_type);
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
        wp_set_object_terms($new_post_id, $terms, $taxonomy);
    }

    $meta = get_post_meta($post_id);
    foreach ($meta as $key => $values) {
        foreach ($values as $value) {
            add_post_meta($new_post_id, $key, maybe_unserialize($value));
        }
    }

    wp_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
    exit;
}

add_action('admin_menu', 'cpt_clone_plugin_menu');
function cpt_clone_plugin_menu() {
    add_options_page(
        'Clone Post Types Settings',
        'Clone Post Types',
        'manage_options',
        'cpt-clone-settings',
        'cpt_clone_settings_page'
    );
}

function cpt_clone_settings_page() {
    $post_types = get_post_types(['public' => true], 'objects');
    $enabled_types = get_option('cpt_clone_enabled_types', []);

    echo '<div class="wrap">';
    echo '<h1>Clone Post Types Settings</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('cpt_clone_settings_group');
    do_settings_sections('cpt-clone-settings');

    echo '<table class="form-table">';
    foreach ($post_types as $type) {
        $checked = in_array($type->name, $enabled_types) ? 'checked' : '';
        echo '<tr><th scope="row">' . esc_html($type->labels->name) . '</th>';
        echo '<td><input type="checkbox" name="cpt_clone_enabled_types[]" value="' . esc_attr($type->name) . '" ' . $checked . '></td></tr>';
    }
    echo '</table>';
    submit_button();
    echo '</form></div>';
}

add_action('admin_init', 'cpt_clone_register_settings');
function cpt_clone_register_settings() {
    register_setting('cpt_clone_settings_group', 'cpt_clone_enabled_types');
}
