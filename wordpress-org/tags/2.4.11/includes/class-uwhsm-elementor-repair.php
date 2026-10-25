<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rebuild Elementor's runtime caches after a cross-domain database import.
 * Content, template display rules, and Elementor plugin files are never changed.
 */
final class UWHSM_Elementor_Repair {
    private static function pro_active() {
        $plugins = (array) get_option('active_plugins', array());
        return in_array('elementor-pro/elementor-pro.php', $plugins, true);
    }

    private static function elementor_active() {
        return in_array('elementor/elementor.php', (array) get_option('active_plugins', array()), true);
    }

    /**
     * Audit the logo source actually used by Elementor Site Logo widgets.
     * Merely seeing the image in Media Library is not enough: WordPress core
     * site_logo, theme_mods and the Elementor active kit may disagree.
     * Never expose the logo URL as an unescaped HTML value.
     */
    private static function raw_theme_logo_id() {
        // Gutenberg may filter get_theme_mod('custom_logo') through the core
        // site_logo option. Read the unfiltered theme setting to detect a bad
        // site_logo override instead of receiving the same invalid ID twice.
        $mods = get_option('theme_mods_' . get_stylesheet(), array());
        return is_array($mods) ? absint($mods['custom_logo'] ?? 0) : 0;
    }

    public static function inspect_logo() {
        $result = array('core_id' => absint(get_option('site_logo', 0)), 'theme_id' => self::raw_theme_logo_id(), 'kit_id' => absint(get_option('elementor_active_kit', 0)), 'kit_logo_id' => 0, 'kit_url_stale' => false, 'invalid_ids' => array(), 'image_sources_missing' => array());
        if ($result['kit_id'] > 0) {
            $settings = get_post_meta($result['kit_id'], '_elementor_page_settings', true);
            if (is_array($settings) && isset($settings['site_logo']) && is_array($settings['site_logo'])) {
                $result['kit_logo_id'] = absint($settings['site_logo']['id'] ?? 0);
                if ($result['kit_logo_id'] > 0) {
                    $url = wp_get_attachment_url($result['kit_logo_id']);
                    $result['kit_url_stale'] = is_string($url) && $url !== '' && $url !== (string) ($settings['site_logo']['url'] ?? '');
                }
            }
        }
        foreach (array('core_id', 'theme_id', 'kit_logo_id') as $field) {
            if ($result[$field] > 0 && (!get_post($result[$field]) || !wp_attachment_is_image($result[$field]) && 'image/svg+xml' !== get_post_mime_type($result[$field]) || !wp_get_attachment_url($result[$field]))) {
                $result['invalid_ids'][] = $field;
            } elseif ($result[$field] > 0 && 'image/svg+xml' !== get_post_mime_type($result[$field]) && !wp_get_attachment_image_src($result[$field], 'full')) {
                $result['image_sources_missing'][] = $field;
            }
        }
        return $result;
    }

    /**
     * Non-destructive logo repair for a *completed* migration or an explicit
     * admin action. Correct only references backed by real WordPress media.
     * Do not invent a new logo from arbitrary attachments.
     *
     * @return array{status:string,message:string}
     */
    public static function repair_logo() {
        if (get_option('uwhsm_restore_guard', false)) {
            return array('status' => 'warning', 'message' => 'A restore is still active; finish it before repairing logo settings.');
        }
        $changed = array();
        $core_id = absint(get_option('site_logo', 0));
        $theme_id = self::raw_theme_logo_id();
        // WordPress block Site Logo may override theme_mod_custom_logo with
        // site_logo even when that attachment no longer resolves.
        if ($core_id > 0 && $core_id !== $theme_id && (!wp_get_attachment_url($core_id) || (!wp_get_attachment_image_src($core_id, 'full') && 'image/svg+xml' !== get_post_mime_type($core_id))) && $theme_id > 0 && wp_get_attachment_url($theme_id)) {
            if (!update_option('site_logo', $theme_id)) {
                return array('status' => 'warning', 'message' => 'WordPress core Site Logo points to an invalid attachment and could not be reconciled.');
            }
            $changed[] = 'WordPress core Site Logo';
        }
        $kit = absint(get_option('elementor_active_kit', 0));
        if (self::elementor_active() && $kit > 0) {
            $settings = get_post_meta($kit, '_elementor_page_settings', true);
            if (is_array($settings) && !empty($settings['site_logo']) && is_array($settings['site_logo'])) {
                $id = absint($settings['site_logo']['id'] ?? 0);
                if ($id > 0) {
                    $url = wp_get_attachment_url($id);
                    if (!$url) {
                        return array('status' => 'warning', 'message' => 'Elementor Site Identity refers to an image ID that no longer has a valid media URL. Check its attachment metadata.');
                    }
                    if ((string) ($settings['site_logo']['url'] ?? '') !== $url) {
                        $settings['site_logo']['url'] = $url;
                        if (false === update_post_meta($kit, '_elementor_page_settings', $settings)) {
                            return array('status' => 'warning', 'message' => 'Elementor Site Logo URL could not be updated.');
                        }
                        $changed[] = 'Elementor Site Identity logo URL';
                    }
                }
            }
        }
        return array('status' => 'success', 'message' => $changed ? 'Logo references repaired: ' . implode(', ', $changed) . '.' : 'Logo references were already aligned. If the header is blank, inspect the Site Logo widget, CSS visibility and Elementor Site Identity settings.');
    }

    /** Lightweight read-only audit, bounded to 250 templates. */
    public static function inspect() {
        $result = array(
            'elementor_active' => self::elementor_active(),
            'pro_active' => self::pro_active(),
            'templates' => 0,
            'headers' => 0,
            'footers' => 0,
            'addon_templates' => 0,
            'addon_active' => false,
            'published' => 0,
            'missing_conditions' => array(),
            'invalid_data' => array(),
            'conditions_cached' => array('header' => 0, 'footer' => 0),
            'truncated' => false,
        );
        $active = (array) get_option('active_plugins', array());
        $result['addon_active'] = in_array('header-footer-elementor/header-footer-elementor.php', $active, true)
            || in_array('elementor-header-footer-builder/elementor-header-footer-builder.php', $active, true);
        if (!function_exists('get_posts')) {
            return $result;
        }
        // Only inspect. Never automatically assign new display rules to templates.
        $posts = get_posts(array(
            'post_type' => array('elementor_library', 'elementor-hf', 'hfe_templates'),
            'post_status' => array('publish', 'private', 'draft', 'pending'),
            'posts_per_page' => 251,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        $posts = is_array($posts) ? $posts : array();
        $result['truncated'] = count($posts) > 250;
        foreach (array_slice($posts, 0, 250) as $post_id) {
            $id = (int) $post_id;
            if ($id < 1) {
                continue;
            }
            $result['templates']++;
            $post = get_post($id);
            if (!$post) {
                continue;
            }
            $published = 'publish' === $post->post_status;
            if ($published) {
                $result['published']++;
            }
            $kind = (string) get_post_meta($id, '_elementor_template_type', true);
            // The free Elementor Header & Footer Builder plugin stores a
            // distinct custom post type and its own header/footer metadata.
            if (in_array((string) ($post->post_type ?? ''), array('elementor-hf', 'hfe_templates'), true)) {
                $result['addon_templates']++;
                $addon_kind = (string) get_post_meta($id, 'ehf_template_type', true);
                if ('' === $addon_kind) {
                    $addon_kind = (string) get_post_meta($id, 'hfe_template_type', true);
                }
                if (in_array($addon_kind, array('type_header', 'header'), true)) {
                    $kind = 'header';
                } elseif (in_array($addon_kind, array('type_footer', 'footer'), true)) {
                    $kind = 'footer';
                }
            }
            if (in_array($kind, array('header', 'footer'), true) && $published) {
                $result[$kind . 's']++;
                if ($result['pro_active'] && 'elementor_library' === (string) ($post->post_type ?? '') && !metadata_exists('post', $id, '_elementor_conditions')) {
                    $result['missing_conditions'][] = $id;
                }
            }
            $data = get_post_meta($id, '_elementor_data', true);
            if (is_string($data) && '' !== $data) {
                json_decode($data, true);
                if (JSON_ERROR_NONE !== json_last_error()) {
                    $result['invalid_data'][] = $id;
                }
            }
        }
        $cache = get_option('elementor_pro_theme_builder_conditions', array());
        foreach (array('header', 'footer') as $kind) {
            $result['conditions_cached'][$kind] = is_array($cache) && isset($cache[$kind]) && is_array($cache[$kind]) ? count($cache[$kind]) : 0;
        }
        return $result;
    }

    private static function includes_previous_conditions($previous, $current) {
        if (!is_array($previous) || !is_array($current)) {
            return false;
        }
        foreach (array('header', 'footer') as $type) {
            foreach ((array) ($previous[$type] ?? array()) as $id => $conditions) {
                if (!isset($current[$type]) || !array_key_exists($id, (array) $current[$type])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Source-first restore: preserve the source Theme Builder conditions cache.
     * It is stored in wp_options and is part of the verified full database
     * snapshot. Rebuilding healthy conditions may silently override otherwise
     * identical source header/footer assignments in another environment.
     *
     * An absent cache may be rebuilt using Elementor Pro's official API after
     * the original plugins are active on a fresh WordPress request. The existing
     * rebuild() method safeguards previous mappings and remains available for
     * the explicit Troubleshooting action.
     *
     * @return array{status:string,message:string}
     */
    public static function rebuild_only_if_missing() {
        if (!self::elementor_active() || !self::pro_active()) {
            return array('status' => 'skipped', 'message' => 'Elementor Pro conditions cache not applicable.');
        }
        $conditions = get_option('elementor_pro_theme_builder_conditions', array());
        if (is_array($conditions)) {
            foreach (array('header', 'footer') as $location) {
                if (!empty($conditions[$location]) && is_array($conditions[$location])) {
                    return array('status' => 'success', 'message' => 'Verified source Elementor Theme Builder conditions were preserved.');
                }
            }
        }
        return self::rebuild();
    }

    /**
     * Elementor Pro Conditions_Manager exposes a get_cache() object with a
     * regenerate() method. Inspect availability at runtime across versions.
     * A preexisting header/footer mapping is retained on any failed rebuild.
     *
     * @return array{status:string,message:string}
     */
    public static function rebuild() {
        if (!self::elementor_active()) {
            return array('status' => 'skipped', 'message' => 'Elementor is not active on this WordPress installation.');
        }
        if (!self::pro_active()) {
            return array('status' => 'skipped', 'message' => 'Elementor Pro is not active; only Elementor CSS needs refreshing.');
        }
        $class = 'ElementorPro\\Modules\\ThemeBuilder\\Module';
        if (!class_exists($class) || !is_callable(array($class, 'instance'))) {
            return array('status' => 'warning', 'message' => 'Elementor Pro is marked active but its Theme Builder API did not load. Check plugin dependencies and the PHP error log.');
        }
        $module = $class::instance();
        if (!is_object($module) || !is_callable(array($module, 'get_conditions_manager'))) {
            return array('status' => 'warning', 'message' => 'Elementor Pro Theme Builder conditions manager is unavailable.');
        }
        $manager = $module->get_conditions_manager();
        if (!is_object($manager) || !is_callable(array($manager, 'get_cache'))) {
            return array('status' => 'warning', 'message' => 'Elementor Pro conditions cache API is unavailable; use the official WP-CLI clear-conditions command.');
        }
        $cache = $manager->get_cache();
        if (!is_object($cache) || !is_callable(array($cache, 'regenerate'))) {
            return array('status' => 'warning', 'message' => 'Elementor Pro conditions regeneration API is unavailable.');
        }
        $before = get_option('elementor_pro_theme_builder_conditions', array());
        $before = is_array($before) ? $before : array();
        try {
            $cache->regenerate();
            $after = get_option('elementor_pro_theme_builder_conditions', array());
            if (!self::includes_previous_conditions($before, $after)) {
                // Never silently replace working source display conditions with
                // a blank cache after an incomplete/filtered regeneration.
                update_option('elementor_pro_theme_builder_conditions', $before, false);
                if (is_callable(array($cache, 'refresh'))) {
                    $cache->refresh();
                }
                if (is_callable(array($manager, 'clear_location_cache'))) {
                    $manager->clear_location_cache();
                }
                return array('status' => 'warning', 'message' => 'Elementor condition rebuild omitted existing header/footer templates. The previous conditions cache was preserved.');
            }
            if (is_callable(array($manager, 'clear_location_cache'))) {
                $manager->clear_location_cache();
            }
            return array('status' => 'success', 'message' => 'Elementor Pro header/footer display conditions were rebuilt successfully.');
        } catch (Throwable $error) {
            // On a PHP error, do not discard the known-good conditions cache.
            update_option('elementor_pro_theme_builder_conditions', $before, false);
            if (is_callable(array($cache, 'refresh'))) {
                $cache->refresh();
            }
            if (is_callable(array($manager, 'clear_location_cache'))) {
                $manager->clear_location_cache();
            }
            return array('status' => 'warning', 'message' => 'Elementor conditions could not be rebuilt: ' . $error->getMessage());
        }
    }
}
