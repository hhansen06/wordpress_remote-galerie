<?php
/**
 * Plugin Name: MediaHUB Gallerie
 * Description: Ein Galerie-Plugin mit REST-API-Integration für Beiträge
 * Version: 1.0.0
 * Author: Henrik Hansen
 * License: GPL v2 or later
 * Text Domain: gallery-widget
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('GALLERY_WIDGET_VERSION', '1.0.0');
define('GALLERY_WIDGET_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GALLERY_WIDGET_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
class Gallery_Widget_Plugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'register_block'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_cache_clear'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Async caching hooks
        add_action('gallery_widget_cache_image', array($this, 'async_cache_image'), 10, 2);
        add_action('gallery_widget_refresh_cache', array($this, 'async_cache_image'), 10, 2);
        
        // Create cache directory
        $this->ensure_cache_directory();
    }
    
    /**
     * Async cache image callback
     */
    public function async_cache_image($s3_url, $type) {
        // Extract original filename from URL path (constant across requests)
        $original_filename = $this->extract_filename_from_url($s3_url);
        $extension = pathinfo($original_filename, PATHINFO_EXTENSION);
        $name_without_ext = pathinfo($original_filename, PATHINFO_FILENAME);
        
        // Use original filename with type prefix for cache
        $filename = $type . '-' . $name_without_ext . '.' . $extension;
        
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/gallery-cache';
        $cache_url = $upload_dir['baseurl'] . '/gallery-cache';
        
        $file_path = $cache_dir . '/' . $filename;
        $file_url = $cache_url . '/' . $filename;
        
        error_log('Gallery Widget: Background caching for ' . $type . ' - ' . $filename);
        $this->download_and_cache_image($s3_url, $file_path, $file_url, $type);
    }
    
    /**
     * Ensure cache directory exists
     */
    private function ensure_cache_directory() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/gallery-cache';
        
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            
            // Add .htaccess to allow direct access
            $htaccess_content = "Options +FollowSymLinks\n";
            $htaccess_content .= "RewriteEngine Off\n";
            $htaccess_content .= "<IfModule mod_expires.c>\n";
            $htaccess_content .= "    ExpiresActive On\n";
            $htaccess_content .= "    ExpiresDefault \"access plus 1 year\"\n";
            $htaccess_content .= "</IfModule>\n";
            
            file_put_contents($cache_dir . '/.htaccess', $htaccess_content);
        }
        
        return $cache_dir;
    }
    
    /**
     * Extract hash from relative API path
     */
    private function extract_hash_from_path($path) {
        // Extract hash from paths like:
        // /api/media/public/6a5b79c1d4a8...
        // /api/media/public/thumbnail/6a5b79c1d4a8...
        
        $parts = explode('/', trim($path, '/'));
        $hash = end($parts);
        
        // Validate hash (should be hex string)
        if (preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            return $hash;
        }
        
        error_log('Gallery Widget: Invalid hash extracted from path: ' . $path);
        return null;
    }
    
    /**
     * Extract original filename from URL path (ignoring query parameters)
     */
    private function extract_filename_from_url($url) {
        // Remove query parameters
        $path = parse_url($url, PHP_URL_PATH);
        // Get the last part of the path (filename)
        $filename = basename($path);
        
        // Sanitize filename
        $filename = sanitize_file_name($filename);
        
        if (empty($filename)) {
            $filename = 'image.jpg';
        }
        
        return $filename;
    }
    
    /**
     * Get cached image URL or download and cache it
     */
    private function get_cached_image_url($s3_url, $type = 'media', $async = false) {
        if (empty($s3_url)) {
            return $s3_url;
        }
        
        // Check if caching is enabled
        $cache_enabled = get_option('gallery_widget_cache_enabled', true);
        if (!$cache_enabled) {
            error_log('Gallery Widget: Caching disabled, returning S3 URL');
            return $s3_url;
        }
        
        // Extract just the base URL without query params for logging
        $base_url = strtok($s3_url, '?');
        error_log('Gallery Widget: Processing ' . $type . ' cache request for URL: ' . substr($base_url, 0, 100) . '...');
        
        // Extract original filename from URL path (constant across requests)
        $original_filename = $this->extract_filename_from_url($s3_url);
        $extension = pathinfo($original_filename, PATHINFO_EXTENSION);
        $name_without_ext = pathinfo($original_filename, PATHINFO_FILENAME);
        
        // Use original filename with type prefix for cache
        $filename = $type . '-' . $name_without_ext . '.' . $extension;
        
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/gallery-cache';
        $cache_url = $upload_dir['baseurl'] . '/gallery-cache';
        
        $file_path = $cache_dir . '/' . $filename;
        $file_url = $cache_url . '/' . $filename;
        
        error_log('Gallery Widget: Using original filename: ' . $filename);
        
        // Check if file exists and is less than configured days old
        if (file_exists($file_path)) {
            $cache_ttl = get_option('gallery_widget_cache_ttl', 7);
            $file_age = time() - filemtime($file_path);
            $max_age = $cache_ttl * 24 * 60 * 60; // Convert days to seconds
            
            if ($file_age < $max_age) {
                $file_size = filesize($file_path) / 1024; // Size in KB
                error_log('Gallery Widget: Found valid cached ' . $type . ' (' . number_format($file_size, 2) . 'KB), age: ' . ($file_age / 60) . ' minutes');
                return $file_url;
            }
            // File exists but is old - schedule async refresh but return cached version
            if ($async) {
                error_log('Gallery Widget: Cached ' . $type . ' is old (' . ($file_age / 3600) . ' hours), scheduling async refresh');
                wp_schedule_single_event(time(), 'gallery_widget_refresh_cache', array($s3_url, $type));
                return $file_url;
            }
        }
        
        // If async mode and file doesn't exist, return original URL and schedule download
        if ($async && !file_exists($file_path)) {
            error_log('Gallery Widget: Async mode for ' . $type . ', scheduling background download');
            wp_schedule_single_event(time(), 'gallery_widget_cache_image', array($s3_url, $type));
            return $s3_url;
        }
        
        // Synchronous download and cache
        error_log('Gallery Widget: Starting synchronous download of ' . $type);
        return $this->download_and_cache_image($s3_url, $file_path, $file_url, $type);
    }
    
    /**
     * Download and cache an image
     */
    private function download_and_cache_image($s3_url, $file_path, $file_url, $type = 'media') {
        $base_url = strtok($s3_url, '?');
        $start_time = microtime(true);
        
        error_log('Gallery Widget: Downloading ' . $type . ' from: ' . $base_url);
        error_log('Gallery Widget: Saving to: ' . $file_path);
        
        // Download with increased timeout and retry logic
        $response = wp_remote_get($s3_url, array(
            'timeout' => 60,
            'sslverify' => false,
            'stream' => true,
            'filename' => $file_path
        ));
        
        $elapsed = round((microtime(true) - $start_time) * 1000); // milliseconds
        
        if (is_wp_error($response)) {
            error_log('Gallery Widget: DOWNLOAD FAILED (' . $elapsed . 'ms) - Error: ' . $response->get_error_message());
            error_log('Gallery Widget: Failed URL: ' . $base_url);
            return $s3_url; // Fallback to original URL
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('Gallery Widget: DOWNLOAD FAILED (' . $elapsed . 'ms) - HTTP ' . $response_code . ' for: ' . $base_url);
            return $s3_url;
        }
        
        // Check if file was created
        if (!file_exists($file_path)) {
            error_log('Gallery Widget: DOWNLOAD FAILED (' . $elapsed . 'ms) - File not created at: ' . $file_path);
            return $s3_url;
        }
        
        $file_size = filesize($file_path);
        if ($file_size === 0) {
            error_log('Gallery Widget: DOWNLOAD FAILED (' . $elapsed . 'ms) - Downloaded file is empty');
            return $s3_url;
        }
        
        $file_size_kb = $file_size / 1024;
        error_log('Gallery Widget: SUCCESS (' . $elapsed . 'ms) - ' . $type . ' cached, size: ' . number_format($file_size_kb, 2) . 'KB');
        
        return $file_url;
    }
    
    /**
     * Register REST API routes for proxy
     */
    public function register_rest_routes() {
        register_rest_route('gallery-widget/v1', '/proxy/dates', array(
            'methods' => 'GET',
            'callback' => array($this, 'proxy_dates'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('gallery-widget/v1', '/proxy/collections', array(
            'methods' => 'GET',
            'callback' => array($this, 'proxy_collections'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('gallery-widget/v1', '/proxy/images', array(
            'methods' => 'GET',
            'callback' => array($this, 'proxy_images'),
            'permission_callback' => '__return_true'
        ));
        
        // Image proxy endpoint - serves images from cache or downloads and caches them
        register_rest_route('gallery-widget/v1', '/image/(?P<hash>[a-f0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'serve_image'),
            'permission_callback' => '__return_true',
            'args' => array(
                'hash' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'thumb' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false
                )
            )
        ));
    }
    
    /**
     * Serve image from cache or download and cache it
     */
    public function serve_image($request) {
        $hash = $request->get_param('hash');
        $is_thumb = $request->get_param('thumb');
        
        $type = $is_thumb ? 'thumb' : 'media';
        $api_path = $is_thumb ? '/api/media/public/thumbnail/' . $hash : '/api/media/public/' . $hash;
        
        error_log('Gallery Widget: Serving ' . $type . ' for hash: ' . $hash);
        
        // Check cache first
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/gallery-cache';
        $filename = $type . '-' . $hash . '.jpg';
        $file_path = $cache_dir . '/' . $filename;
        
        // If cached, serve from cache
        if (file_exists($file_path) && filesize($file_path) > 0) {
            $file_size = filesize($file_path) / 1024;
            error_log('Gallery Widget: Serving ' . $type . ' from cache (' . number_format($file_size, 2) . 'KB)');
            
            $mime_type = 'image/jpeg';
            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . filesize($file_path));
            header('Cache-Control: public, max-age=31536000'); // 1 year
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
            
            readfile($file_path);
            exit;
        }
        
        // Download from API and cache
        $base_url = get_option('gallery_widget_base_url', '');
        if (empty($base_url)) {
            status_header(500);
            echo 'Base URL not configured';
            exit;
        }
        
        $api_url = $base_url . $api_path;
        error_log('Gallery Widget: Downloading ' . $type . ' from: ' . $api_url);
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 60,
            'sslverify' => false,
            'stream' => true,
            'filename' => $file_path
        ));
        
        if (is_wp_error($response)) {
            error_log('Gallery Widget: Download failed: ' . $response->get_error_message());
            // Delete potentially created empty file
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            status_header(404);
            echo 'Image not cached and server unavailable';
            exit;
        }
        
        // Check HTTP status code
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('Gallery Widget: Download failed with HTTP status ' . $status_code);
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            status_header(404);
            echo 'Image not available';
            exit;
        }
        
        // Validate downloaded file
        if (!file_exists($file_path) || filesize($file_path) === 0) {
            error_log('Gallery Widget: Downloaded file is empty or missing');
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            status_header(404);
            echo 'Image not available';
            exit;
        }
        
        // Validate file is actually an image (check minimum size and JPEG header)
        $file_size_bytes = filesize($file_path);
        if ($file_size_bytes < 100) {
            error_log('Gallery Widget: Downloaded file too small (' . $file_size_bytes . ' bytes), probably invalid');
            unlink($file_path);
            status_header(404);
            echo 'Invalid image file';
            exit;
        }
        
        // Verify JPEG header
        $handle = fopen($file_path, 'rb');
        $header = fread($handle, 2);
        fclose($handle);
        if ($header !== "\xFF\xD8") {
            error_log('Gallery Widget: Downloaded file is not a valid JPEG');
            unlink($file_path);
            status_header(404);
            echo 'Invalid image format';
            exit;
        }
        
        $file_size = $file_size_bytes / 1024;
        error_log('Gallery Widget: Downloaded and cached ' . $type . ' (' . number_format($file_size, 2) . 'KB)');
        
        // Serve the newly cached file
        $mime_type = 'image/jpeg';
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: public, max-age=31536000'); // 1 year
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        
        readfile($file_path);
        exit;
    }
    
    /**
     * Proxy dates endpoint with caching
     */
    public function proxy_dates($request) {
        $cache_key = 'gallery_widget_dates';
        $cache_ttl = get_option('gallery_widget_cache_ttl', 7) * 24 * 60 * 60;
        
        // Skip cache if nocache parameter is present (block editor)
        $nocache = $request->get_param('nocache');
        
        // Try to get from cache
        $cached = get_transient($cache_key);
        if (!$nocache && $cached !== false && !empty($cached)) {
            error_log('Gallery Widget: Serving dates from cache');
            return rest_ensure_response($cached);
        }
        
        $base_url = get_option('gallery_widget_base_url', '');
        if (empty($base_url)) {
            return new WP_Error('no_base_url', 'Base URL nicht konfiguriert', array('status' => 400));
        }
        
        error_log('Gallery Widget: Fetching dates from API: ' . $base_url . '/api/public/dates');
        $response = wp_remote_get($base_url . '/api/public/dates', array(
            'timeout' => 15,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            error_log('Gallery Widget: Dates API error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        error_log('Gallery Widget: Dates API response body: ' . substr($body, 0, 200));
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Gallery Widget: JSON decode error: ' . json_last_error_msg());
            return new WP_Error('json_error', 'Invalid JSON response', array('status' => 500));
        }
        
        // Only cache if we have valid data
        if (!empty($data)) {
            set_transient($cache_key, $data, $cache_ttl);
            error_log('Gallery Widget: Cached dates manifest (TTL: ' . $cache_ttl . ' seconds)');
        } else {
            error_log('Gallery Widget: Not caching empty dates response');
        }
        
        return rest_ensure_response($data);
    }
    
    /**
     * Proxy collections endpoint with caching
     */
    public function proxy_collections($request) {
        $cache_key = 'gallery_widget_collections';
        $cache_ttl = get_option('gallery_widget_cache_ttl', 7) * 24 * 60 * 60;
        
        // Skip cache if nocache parameter is present (block editor)
        $nocache = $request->get_param('nocache');
        
        // Try to get from cache
        $cached = get_transient($cache_key);
        if (!$nocache && $cached !== false && !empty($cached)) {
            error_log('Gallery Widget: Serving collections from cache');
            return rest_ensure_response($cached);
        }
        
        $base_url = get_option('gallery_widget_base_url', '');
        if (empty($base_url)) {
            return new WP_Error('no_base_url', 'Base URL nicht konfiguriert', array('status' => 400));
        }
        
        error_log('Gallery Widget: Fetching collections from API: ' . $base_url . '/api/public/collections');
        $response = wp_remote_get($base_url . '/api/public/collections', array(
            'timeout' => 15,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            error_log('Gallery Widget: Collections API error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        error_log('Gallery Widget: Collections API response body: ' . substr($body, 0, 200));
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Gallery Widget: JSON decode error: ' . json_last_error_msg());
            return new WP_Error('json_error', 'Invalid JSON response', array('status' => 500));
        }
        
        // Only cache if we have valid data
        if (!empty($data)) {
            set_transient($cache_key, $data, $cache_ttl);
            error_log('Gallery Widget: Cached collections manifest (TTL: ' . $cache_ttl . ' seconds)');
        } else {
            error_log('Gallery Widget: Not caching empty collections response');
        }
        
        return rest_ensure_response($data);
    }
    
    /**
     * Proxy images endpoint
     */
    public function proxy_images($request) {
        $date = $request->get_param('date');
        $collection = $request->get_param('collection');
        
        // Build cache key from parameters
        $cache_key = 'gallery_widget_images_' . md5(serialize(array('date' => $date, 'collection' => $collection)));
        $cache_ttl = get_option('gallery_widget_cache_ttl', 7) * 24 * 60 * 60;
        
        // Try to get from cache
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            error_log('Gallery Widget: Serving images list from cache (date=' . $date . ', collection=' . $collection . ')');
            return rest_ensure_response($cached);
        }
        
        $base_url = get_option('gallery_widget_base_url', '');
        if (empty($base_url)) {
            return new WP_Error('no_base_url', 'Base URL nicht konfiguriert', array('status' => 400));
        }
        
        $url = $base_url . '/api/public/images';
        $query_params = array();
        
        if (!empty($date)) {
            $query_params['date'] = $date;
        }
        if (!empty($collection)) {
            $query_params['collection'] = $collection;
        }
        
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }
        
        error_log('Gallery Widget: Fetching images list from API: ' . $url);
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            error_log('Gallery Widget: Images API error: ' . $response->get_error_message());
            return new WP_Error('api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        error_log('Gallery Widget: Images API response body: ' . substr($body, 0, 200));
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Gallery Widget: JSON decode error: ' . json_last_error_msg());
            return new WP_Error('json_error', 'Invalid JSON response', array('status' => 500));
        }
        
        // Convert relative API URLs to local proxy URLs
        if (isset($data['items']) && is_array($data['items'])) {
            $proxy_base = rest_url('gallery-widget/v1/image/');
            
            foreach ($data['items'] as &$item) {
                // Convert thumbnail URL: /api/media/public/thumbnail/{hash} -> /wp-json/.../image/{hash}?thumb=1
                if (isset($item['thumbnail_url'])) {
                    $hash = $this->extract_hash_from_path($item['thumbnail_url']);
                    if ($hash) {
                        $item['thumbnail_url'] = $proxy_base . $hash . '?thumb=1';
                        error_log('Gallery Widget: Converted thumbnail URL to: ' . $item['thumbnail_url']);
                    }
                }
                
                // Convert full image URL: /api/media/public/{hash} -> /wp-json/.../image/{hash}
                if (isset($item['public_url'])) {
                    $hash = $this->extract_hash_from_path($item['public_url']);
                    if ($hash) {
                        $item['public_url'] = $proxy_base . $hash;
                        error_log('Gallery Widget: Converted public URL to: ' . $item['public_url']);
                    }
                }
            }
        }
        
        // Only cache if we have valid data
        if (!empty($data)) {
            set_transient($cache_key, $data, $cache_ttl);
            error_log('Gallery Widget: Cached images list (TTL: ' . $cache_ttl . ' seconds)');
        } else {
            error_log('Gallery Widget: Not caching empty images response');
        }
        
        return rest_ensure_response($data);
    }
    
    /**
     * Register the Gutenberg block
     */
    public function register_block() {
        register_block_type('gallery-widget/gallery', array(
            'api_version' => 2,
            'editor_script' => 'gallery-widget-block-editor',
            'editor_style' => 'gallery-widget-block-editor',
            'style' => 'gallery-widget-block',
            'render_callback' => array($this, 'render_block'),
            'attributes' => array(
                'selectedDates' => array(
                    'type' => 'array',
                    'default' => array()
                ),
                'selectedCollections' => array(
                    'type' => 'array',
                    'default' => array()
                ),
                'columns' => array(
                    'type' => 'number',
                    'default' => 3
                ),
                'showTitle' => array(
                    'type' => 'boolean',
                    'default' => true
                )
            )
        ));
    }
    
    /**
     * Enqueue block editor assets
     */
    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'gallery-widget-block-editor',
            GALLERY_WIDGET_PLUGIN_URL . 'assets/js/block-editor.js',
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data'),
            GALLERY_WIDGET_VERSION,
            true
        );
        
        wp_enqueue_style(
            'gallery-widget-block-editor',
            GALLERY_WIDGET_PLUGIN_URL . 'assets/css/block-editor.css',
            array('wp-edit-blocks'),
            GALLERY_WIDGET_VERSION
        );
        
        wp_localize_script('gallery-widget-block-editor', 'galleryWidgetConfig', array(
            'baseUrl' => get_option('gallery_widget_base_url', ''),
            'proxyUrl' => rest_url('gallery-widget/v1/proxy'),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }
    
    /**
     * Render block callback
     */
    public function render_block($attributes) {
        $dates = isset($attributes['selectedDates']) ? $attributes['selectedDates'] : array();
        $collections = isset($attributes['selectedCollections']) ? $attributes['selectedCollections'] : array();
        $columns = isset($attributes['columns']) ? $attributes['columns'] : 3;
        $showTitle = isset($attributes['showTitle']) ? $attributes['showTitle'] : true;
        
        $html = sprintf(
            '<div class="wp-block-gallery-widget-gallery" data-dates="%s" data-collections="%s" data-columns="%d" data-show-title="%s">',
            esc_attr(json_encode($dates)),
            esc_attr(json_encode($collections)),
            esc_attr($columns),
            $showTitle ? 'true' : 'false'
        );
        $html .= '<div class="gallery-widget-placeholder">Galerie wird geladen...</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_options_page(
            __('MediaHUB Gallerie Einstellungen', 'gallery-widget'),
            __('MediaHUB Gallerie', 'gallery-widget'),
            'manage_options',
            'gallery-widget-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('gallery_widget_settings', 'gallery_widget_base_url', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_url'),
            'default' => ''
        ));
        
        register_setting('gallery_widget_settings', 'gallery_widget_cache_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ));
        
        register_setting('gallery_widget_settings', 'gallery_widget_cache_ttl', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 7
        ));
        
        register_setting('gallery_widget_settings', 'gallery_widget_thumbnail_cache_sync', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true
        ));
        
        add_settings_section(
            'gallery_widget_main_section',
            __('API Einstellungen', 'gallery-widget'),
            array($this, 'render_section_description'),
            'gallery-widget-settings'
        );
        
        add_settings_field(
            'gallery_widget_base_url',
            __('Base URL', 'gallery-widget'),
            array($this, 'render_base_url_field'),
            'gallery-widget-settings',
            'gallery_widget_main_section'
        );
        
        add_settings_section(
            'gallery_widget_cache_section',
            __('Cache Einstellungen', 'gallery-widget'),
            array($this, 'render_cache_section_description'),
            'gallery-widget-settings'
        );
        
        add_settings_field(
            'gallery_widget_cache_enabled',
            __('Caching aktivieren', 'gallery-widget'),
            array($this, 'render_cache_enabled_field'),
            'gallery-widget-settings',
            'gallery_widget_cache_section'
        );
        
        add_settings_field(
            'gallery_widget_cache_ttl',
            __('Cache-Dauer (Tage)', 'gallery-widget'),
            array($this, 'render_cache_ttl_field'),
            'gallery-widget-settings',
            'gallery_widget_cache_section'
        );
        
        add_settings_field(
            'gallery_widget_thumbnail_cache_sync',
            __('Thumbnails sofort cachen', 'gallery-widget'),
            array($this, 'render_thumbnail_cache_sync_field'),
            'gallery-widget-settings',
            'gallery_widget_cache_section'
        );
        
        add_settings_field(
            'gallery_widget_clear_cache',
            __('Cache leeren', 'gallery-widget'),
            array($this, 'render_clear_cache_field'),
            'gallery-widget-settings',
            'gallery_widget_cache_section'
        );
    }
    
    /**
     * Sanitize URL
     */
    public function sanitize_url($url) {
        return esc_url_raw(rtrim($url, '/'));
    }
    
    /**
     * Handle cache clearing action
     */
    public function handle_cache_clear() {
        // Check if this is the cache clear action
        if (!isset($_GET['page']) || $_GET['page'] !== 'gallery-widget-settings') {
            return;
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : '';
        
        if (!in_array($action, array('clear_cache', 'clear_dates_manifest', 'clear_collections_manifest'))) {
            return;
        }
        
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gallery_widget_' . $action)) {
            wp_die(__('Sicherheitsprüfung fehlgeschlagen', 'gallery-widget'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Keine Berechtigung', 'gallery-widget'));
        }
        
        $message = '';
        
        switch ($action) {
            case 'clear_cache':
                $this->clear_cache();
                $message = 'cache-cleared';
                break;
                
            case 'clear_dates_manifest':
                delete_transient('gallery_widget_dates');
                error_log('Gallery Widget: Dates manifest cleared');
                $message = 'dates-manifest-cleared';
                break;
                
            case 'clear_collections_manifest':
                delete_transient('gallery_widget_collections');
                error_log('Gallery Widget: Collections manifest cleared');
                $message = 'collections-manifest-cleared';
                break;
        }
        
        // Redirect back to settings page with success message
        wp_redirect(add_query_arg(
            array(
                'page' => 'gallery-widget-settings',
                $message => '1'
            ),
            admin_url('options-general.php')
        ));
        exit;
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Show cache cleared message
        if (isset($_GET['cache-cleared'])) {
            add_settings_error(
                'gallery_widget_messages',
                'gallery_widget_cache_cleared',
                __('Cache erfolgreich geleert (Dateien + Manifeste)', 'gallery-widget'),
                'updated'
            );
        }
        
        // Show dates manifest cleared message
        if (isset($_GET['dates-manifest-cleared'])) {
            add_settings_error(
                'gallery_widget_messages',
                'gallery_widget_dates_manifest_cleared',
                __('Dates-Manifest erfolgreich geleert', 'gallery-widget'),
                'updated'
            );
        }
        
        // Show collections manifest cleared message
        if (isset($_GET['collections-manifest-cleared'])) {
            add_settings_error(
                'gallery_widget_messages',
                'gallery_widget_collections_manifest_cleared',
                __('Collections-Manifest erfolgreich geleert', 'gallery-widget'),
                'updated'
            );
        }
        
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'gallery_widget_messages',
                'gallery_widget_message',
                __('Einstellungen gespeichert', 'gallery-widget'),
                'updated'
            );
        }
        
        settings_errors('gallery_widget_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('gallery_widget_settings');
                do_settings_sections('gallery-widget-settings');
                submit_button(__('Einstellungen speichern', 'gallery-widget'));
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Clear the cache directory
     */
    private function clear_cache() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/gallery-cache';
        
        // Clear file cache
        if (file_exists($cache_dir)) {
            $files = glob($cache_dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.htaccess') {
                    unlink($file);
                }
            }
        }
        
        // Clear manifest transients
        delete_transient('gallery_widget_dates');
        delete_transient('gallery_widget_collections');
        
        // Clear all images list transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gallery_widget_images_%' OR option_name LIKE '_transient_timeout_gallery_widget_images_%'");
        
        error_log('Gallery Widget: Cache cleared (files and manifests)');
    }
    
    /**
     * Get cache statistics
     */
    private function get_cache_stats() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/gallery-cache';
        
        if (!file_exists($cache_dir)) {
            return array(
                'files' => 0,
                'size' => 0
            );
        }
        
        $files = glob($cache_dir . '/*');
        $total_size = 0;
        $file_count = 0;
        
        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== '.htaccess') {
                $total_size += filesize($file);
                $file_count++;
            }
        }
        
        return array(
            'files' => $file_count,
            'size' => $total_size
        );
    }
    
    /**
     * Render section description
     */
    public function render_section_description() {
        echo '<p>' . __('Konfigurieren Sie die Base URL für die REST-API der Bildergalerien.', 'gallery-widget') . '</p>';
    }
    
    /**
     * Render base URL field
     */
    public function render_base_url_field() {
        $value = get_option('gallery_widget_base_url', '');
        ?>
        <input type="url" 
               id="gallery_widget_base_url" 
               name="gallery_widget_base_url" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               placeholder="https://example.com">
        <p class="description">
            <?php _e('Die Base URL für die REST-API (z.B. https://example.com)', 'gallery-widget'); ?>
        </p>
        <?php
    }
    
    /**
     * Render cache section description
     */
    public function render_cache_section_description() {
        echo '<p>' . __('Bilder werden über einen lokalen Proxy ausgeliefert und unbegrenzt gecacht. Manifeste (Daten- und Collections-Listen) werden gemäß Cache-TTL gecacht.', 'gallery-widget') . '</p>';
        echo '<p>' . __('<strong>Wichtig:</strong> Bilder werden beim ersten Abruf von der API heruntergeladen und dann permanent lokal gespeichert (unendliches Caching). Nur Manifeste werden nach Ablauf der Cache-Dauer neu geladen.', 'gallery-widget') . '</p>';
    }
    
    /**
     * Render cache enabled field
     */
    public function render_cache_enabled_field() {
        $value = get_option('gallery_widget_cache_enabled', true);
        ?>
        <label>
            <input type="checkbox" 
                   id="gallery_widget_cache_enabled" 
                   name="gallery_widget_cache_enabled" 
                   value="1"
                   <?php checked($value, true); ?>>
            <?php _e('Bilder lokal cachen (empfohlen)', 'gallery-widget'); ?>
        </label>
        <p class="description">
            <?php _e('Wenn aktiviert, werden Bilder von S3 heruntergeladen und lokal gespeichert. Dies verbessert die Performance und reduziert S3-Traffic.', 'gallery-widget'); ?>
        </p>
        <?php
    }
    
    /**
     * Render cache TTL field
     */
    public function render_cache_ttl_field() {
        $value = get_option('gallery_widget_cache_ttl', 7);
        ?>
        <input type="number" 
               id="gallery_widget_cache_ttl" 
               name="gallery_widget_cache_ttl" 
               value="<?php echo esc_attr($value); ?>" 
               min="1"
               max="365"
               class="small-text">
        <p class="description">
            <?php _e('Anzahl der Tage, wie lange Bilder gecacht werden sollen (Standard: 7 Tage)', 'gallery-widget'); ?>
        </p>
        <?php
    }
    
    /**
     * Render thumbnail cache sync field
     */
    public function render_thumbnail_cache_sync_field() {
        $value = get_option('gallery_widget_thumbnail_cache_sync', true);
        ?>
        <label>
            <input type="checkbox" 
                   id="gallery_widget_thumbnail_cache_sync" 
                   name="gallery_widget_thumbnail_cache_sync" 
                   value="1"
                   <?php checked($value, true); ?>>
            <?php _e('Thumbnails sofort cachen (empfohlen)', 'gallery-widget'); ?>
        </label>
        <p class="description">
            <?php _e('Wenn aktiviert, werden Thumbnails synchron heruntergeladen und gecacht. Dies sorgt für schnellere Seitenladezeiten. Volle Bilder werden im Hintergrund gecacht.', 'gallery-widget'); ?>
        </p>
        <?php
    }
    
    /**
     * Render clear cache field
     */
    public function render_clear_cache_field() {
        $stats = $this->get_cache_stats();
        $size_mb = round($stats['size'] / 1024 / 1024, 2);
        
        $clear_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page' => 'gallery-widget-settings',
                    'action' => 'clear_cache'
                ),
                admin_url('options-general.php')
            ),
            'gallery_widget_clear_cache'
        );
        
        $clear_dates_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page' => 'gallery-widget-settings',
                    'action' => 'clear_dates_manifest'
                ),
                admin_url('options-general.php')
            ),
            'gallery_widget_clear_dates_manifest'
        );
        
        $clear_collections_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page' => 'gallery-widget-settings',
                    'action' => 'clear_collections_manifest'
                ),
                admin_url('options-general.php')
            ),
            'gallery_widget_clear_collections_manifest'
        );
        ?>
        <div style="margin-bottom: 10px;">
            <a href="<?php echo esc_url($clear_url); ?>" 
               class="button button-secondary"
               onclick="return confirm('<?php esc_attr_e('Möchten Sie wirklich den gesamten Cache leeren (Dateien + Manifeste)?', 'gallery-widget'); ?>');">
                <?php _e('Kompletten Cache leeren', 'gallery-widget'); ?>
            </a>
        </div>
        
        <div style="margin-bottom: 10px;">
            <a href="<?php echo esc_url($clear_dates_url); ?>" 
               class="button button-secondary"
               onclick="return confirm('<?php esc_attr_e('Möchten Sie das Dates-Manifest leeren?', 'gallery-widget'); ?>');">
                <?php _e('Dates-Manifest leeren', 'gallery-widget'); ?>
            </a>
            
            <a href="<?php echo esc_url($clear_collections_url); ?>" 
               class="button button-secondary"
               onclick="return confirm('<?php esc_attr_e('Möchten Sie das Collections-Manifest leeren?', 'gallery-widget'); ?>');">
                <?php _e('Collections-Manifest leeren', 'gallery-widget'); ?>
            </a>
        </div>
        
        <p class="description">
            <?php 
            printf(
                __('Aktuell gecacht: %d Dateien (%s MB)', 'gallery-widget'),
                $stats['files'],
                $size_mb
            ); 
            ?>
            <br>
            <?php _e('Manifeste werden im WordPress-Transient-Cache gespeichert.', 'gallery-widget'); ?>
        </p>
        <?php
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        if (has_block('gallery-widget/gallery')) {
            wp_enqueue_style(
                'gallery-widget-block',
                GALLERY_WIDGET_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                GALLERY_WIDGET_VERSION
            );
            
            wp_enqueue_script(
                'gallery-widget-frontend',
                GALLERY_WIDGET_PLUGIN_URL . 'assets/js/frontend.js',
                array(),
                GALLERY_WIDGET_VERSION,
                true
            );
            
            wp_localize_script('gallery-widget-frontend', 'galleryWidgetConfig', array(
                'baseUrl' => get_option('gallery_widget_base_url', ''),
                'proxyUrl' => rest_url('gallery-widget/v1/proxy'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_rest')
            ));
        }
    }
}

// Initialize the plugin
Gallery_Widget_Plugin::get_instance();
