<?php
/**
 * Plugin Name: MediaHUB Gallerie
 * Description: Ein Galerie-Plugin mit REST-API-Integration für Beiträge
 * Version: 1.0.2
 * Author: Henrik Hansen
 * License: GPL v2 or later
 * Text Domain: gallery-widget
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('GALLERY_WIDGET_VERSION', '1.0.2');
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
        add_action('init', array($this, 'register_shortcodes'));
        add_action('init', array($this, 'register_rewrite_rules'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_cache_clear'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('template_redirect', array($this, 'handle_gallery_routes'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        
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
     * Register plugin shortcodes
     */
    public function register_shortcodes() {
        add_shortcode('gallery_widget_archive', array($this, 'render_gallery_archive_shortcode'));
    }

    /**
     * Register rewrite rules for class gallery pages
     */
    public function register_rewrite_rules() {
        add_rewrite_rule(
            '^galerie/([^/]+)/seite/([0-9]+)/?$',
            'index.php?gallery_widget_route=list&gallery_widget_class=$matches[1]&gallery_widget_paged=$matches[2]',
            'top'
        );

        add_rewrite_rule(
            '^galerie/([^/]+)/([0-9]+)/?$',
            'index.php?gallery_widget_route=detail&gallery_widget_class=$matches[1]&gallery_widget_item=$matches[2]',
            'top'
        );

        add_rewrite_rule(
            '^galerie/([^/]+)/?$',
            'index.php?gallery_widget_route=list&gallery_widget_class=$matches[1]',
            'top'
        );
    }

    /**
     * Register custom query vars
     */
    public function register_query_vars($vars) {
        $vars[] = 'gallery_widget_route';
        $vars[] = 'gallery_widget_class';
        $vars[] = 'gallery_widget_item';
        $vars[] = 'gallery_widget_paged';
        return $vars;
    }

    /**
     * Render custom gallery class routes
     */
    public function handle_gallery_routes() {
        if (is_admin()) {
            return;
        }

        $route = get_query_var('gallery_widget_route');
        $class_slug = sanitize_title(get_query_var('gallery_widget_class'));

        if (empty($route)) {
            $fallback = $this->detect_gallery_route_from_request_uri();
            if (!$fallback) {
                return;
            }
            $route = $fallback['route'];
            $class_slug = $fallback['class_slug'];
            if (isset($fallback['item'])) {
                set_query_var('gallery_widget_item', $fallback['item']);
            }
            if (isset($fallback['paged'])) {
                set_query_var('gallery_widget_paged', $fallback['paged']);
            }
        }

        $class_config = $this->find_gallery_class_by_slug($class_slug);

        if (!$class_config) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            include get_404_template();
            exit;
        }

        if ($route === 'list') {
            $this->render_class_list_page($class_config);
            exit;
        }

        if ($route === 'detail') {
            $item = absint(get_query_var('gallery_widget_item'));
            $this->render_class_detail_page($class_config, $item);
            exit;
        }
    }

    /**
     * Fallback parser for gallery routes based on request URI.
     */
    private function detect_gallery_route_from_request_uri() {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return null;
        }

        $request_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (!is_string($request_path)) {
            $request_path = '';
        }

        $uri_path = wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (!is_string($uri_path)) {
            return null;
        }

        if ($request_path !== '' && strpos($uri_path, $request_path) === 0) {
            $uri_path = substr($uri_path, strlen($request_path));
        }

        $uri_path = trim($uri_path, '/');
        if ($uri_path === '') {
            return null;
        }

        $parts = array_values(array_filter(explode('/', $uri_path), 'strlen'));
        if (count($parts) < 2 || $parts[0] !== 'galerie') {
            return null;
        }

        $class_slug = sanitize_title(rawurldecode($parts[1]));
        if ($class_slug === '') {
            return null;
        }

        if (count($parts) === 2) {
            return array(
                'route' => 'list',
                'class_slug' => $class_slug,
                'paged' => 1
            );
        }

        if (count($parts) === 4 && $parts[2] === 'seite' && ctype_digit($parts[3])) {
            return array(
                'route' => 'list',
                'class_slug' => $class_slug,
                'paged' => max(1, absint($parts[3]))
            );
        }

        if (count($parts) === 3 && ctype_digit($parts[2])) {
            return array(
                'route' => 'detail',
                'class_slug' => $class_slug,
                'item' => max(1, absint($parts[2]))
            );
        }

        return null;
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
        add_menu_page(
            __('MediaHUB Gallerie Einstellungen', 'gallery-widget'),
            __('MediaHUB Gallerie', 'gallery-widget'),
            'manage_options',
            'gallery-widget-settings',
            array($this, 'render_settings_page'),
            'dashicons-format-gallery',
            58
        );

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

        register_setting('gallery_widget_settings', 'gallery_widget_archive_sets', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_archive_sets'),
            'default' => array()
        ));

        register_setting('gallery_widget_settings', 'gallery_widget_archive_preview_map', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_archive_preview_map'),
            'default' => array()
        ));

        register_setting('gallery_widget_settings', 'gallery_widget_archive_preview_count', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 4
        ));

        register_setting('gallery_widget_settings', 'gallery_widget_classes', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_gallery_classes'),
            'default' => array()
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

        add_settings_section(
            'gallery_widget_archive_section',
            __('Galerie-Liste (Menüseite)', 'gallery-widget'),
            array($this, 'render_archive_section_description'),
            'gallery-widget-settings'
        );

        add_settings_field(
            'gallery_widget_archive_sets',
            __('Bildersets anzeigen', 'gallery-widget'),
            array($this, 'render_archive_sets_field'),
            'gallery-widget-settings',
            'gallery_widget_archive_section'
        );

        add_settings_field(
            'gallery_widget_archive_preview_map',
            __('Vorschaubilder auswählen', 'gallery-widget'),
            array($this, 'render_archive_preview_map_field'),
            'gallery-widget-settings',
            'gallery_widget_archive_section'
        );

        add_settings_field(
            'gallery_widget_archive_preview_count',
            __('Fallback: Erste X Bilder', 'gallery-widget'),
            array($this, 'render_archive_preview_count_field'),
            'gallery-widget-settings',
            'gallery_widget_archive_section'
        );

        add_settings_section(
            'gallery_widget_class_section',
            __('Galerie-Sparte & Routing', 'gallery-widget'),
            array($this, 'render_class_section_description'),
            'gallery-widget-settings'
        );

        add_settings_field(
            'gallery_widget_classes',
            __('Sparten konfigurieren', 'gallery-widget'),
            array($this, 'render_gallery_classes_field'),
            'gallery-widget-settings',
            'gallery_widget_class_section'
        );
    }
    
    /**
     * Sanitize URL
     */
    public function sanitize_url($url) {
        return esc_url_raw(rtrim($url, '/'));
    }

    /**
     * Sanitize selected archive set keys
     */
    public function sanitize_archive_sets($sets) {
        if (!is_array($sets)) {
            return array();
        }

        $sanitized = array();
        foreach ($sets as $set_key) {
            $set_key = sanitize_text_field($set_key);
            if (strpos($set_key, 'date::') === 0 || strpos($set_key, 'collection::') === 0) {
                $sanitized[] = $set_key;
            }
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * Sanitize preview map for selected archive sets
     */
    public function sanitize_archive_preview_map($preview_map) {
        if (!is_array($preview_map)) {
            return array();
        }

        $sanitized = array();
        foreach ($preview_map as $set_key => $urls) {
            $set_key = sanitize_text_field($set_key);
            if (strpos($set_key, 'date::') !== 0 && strpos($set_key, 'collection::') !== 0) {
                continue;
            }

            if (!is_array($urls)) {
                continue;
            }

            $sanitized_urls = array();
            foreach ($urls as $media_hash) {
                $media_hash = sanitize_text_field($media_hash);
                if (preg_match('/^[a-f0-9]{64}$/i', $media_hash)) {
                    $sanitized_urls[] = strtolower($media_hash);
                }
            }

            if (!empty($sanitized_urls)) {
                $sanitized[$set_key] = array_values(array_unique($sanitized_urls));
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize custom labels for class set entries
     */
    public function sanitize_class_set_labels($labels) {
        if (!is_array($labels)) {
            return array();
        }

        $sanitized = array();
        foreach ($labels as $set_key => $label) {
            $set_key = sanitize_text_field($set_key);
            if (strpos($set_key, 'date::') !== 0 && strpos($set_key, 'collection::') !== 0) {
                continue;
            }
            $clean_label = sanitize_text_field($label);
            if ($clean_label !== '') {
                $sanitized[$set_key] = $clean_label;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize multi-class gallery configuration
     */
    public function sanitize_gallery_classes($classes) {
        if (!is_array($classes)) {
            return array();
        }

        $sanitized = array();
        foreach ($classes as $class) {
            if (!is_array($class)) {
                continue;
            }

            $slug = isset($class['slug']) ? sanitize_title($class['slug']) : '';
            if ($slug === '') {
                continue;
            }

            $label = isset($class['label']) ? sanitize_text_field($class['label']) : '';
            if ($label === '') {
                $label = ucfirst($slug);
            }

            $per_page = isset($class['per_page']) ? absint($class['per_page']) : 6;
            $per_page = max(1, $per_page);

            $sets = isset($class['sets']) ? $this->sanitize_archive_sets($class['sets']) : array();
            $set_labels = isset($class['set_labels']) ? $this->sanitize_class_set_labels($class['set_labels']) : array();

            // Keep only labels for selected sets.
            $set_labels = array_intersect_key($set_labels, array_flip($sets));

            $sanitized[] = array(
                'slug' => $slug,
                'label' => $label,
                'per_page' => $per_page,
                'sets' => $sets,
                'set_labels' => $set_labels
            );
        }

        return $sanitized;
    }

    /**
     * Get configured classes with legacy fallback
     */
    private function get_gallery_classes() {
        $classes = get_option('gallery_widget_classes', array());
        $classes = $this->sanitize_gallery_classes($classes);
        if (!empty($classes)) {
            return $classes;
        }

        // Legacy fallback (single class config).
        $legacy_slug = sanitize_title(get_option('gallery_widget_class_slug', ''));
        if ($legacy_slug === '') {
            return array();
        }

        $legacy_sets = $this->sanitize_archive_sets(get_option('gallery_widget_class_sets', array()));
        $legacy_labels = $this->sanitize_class_set_labels(get_option('gallery_widget_class_set_labels', array()));

        return array(
            array(
                'slug' => $legacy_slug,
                'label' => sanitize_text_field(get_option('gallery_widget_class_label', ucfirst($legacy_slug))),
                'per_page' => max(1, absint(get_option('gallery_widget_class_per_page', 6))),
                'sets' => $legacy_sets,
                'set_labels' => array_intersect_key($legacy_labels, array_flip($legacy_sets))
            )
        );
    }

    /**
     * Fetch JSON payload from remote API endpoint
     */
    private function fetch_remote_json($path, $query_params = array()) {
        $base_url = get_option('gallery_widget_base_url', '');
        if (empty($base_url)) {
            return new WP_Error('no_base_url', 'Base URL nicht konfiguriert', array('status' => 400));
        }

        $url = untrailingslashit($base_url) . $path;
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid JSON response', array('status' => 500));
        }

        return $decoded;
    }

    /**
     * Get available set options from dates and collections manifests
     */
    private function get_available_archive_sets() {
        $sets = array();

        $dates = $this->proxy_dates(new WP_REST_Request('GET'));
        if (!is_wp_error($dates)) {
            $dates_data = $dates instanceof WP_REST_Response ? $dates->get_data() : $dates;
            if (is_array($dates_data)) {
                foreach ($dates_data as $entry) {
                    $date_value = is_array($entry) ? (isset($entry['date']) ? $entry['date'] : '') : $entry;
                    $date_value = sanitize_text_field((string) $date_value);
                    if (!empty($date_value)) {
                        $sets['date::' . $date_value] = sprintf(__('Date: %s', 'gallery-widget'), $date_value);
                    }
                }
            }
        }

        $collections = $this->proxy_collections(new WP_REST_Request('GET'));
        if (!is_wp_error($collections)) {
            $collections_data = $collections instanceof WP_REST_Response ? $collections->get_data() : $collections;
            if (is_array($collections_data)) {
                foreach ($collections_data as $entry) {
                    if (is_array($entry)) {
                        $collection_value = isset($entry['id']) ? $entry['id'] : (isset($entry['name']) ? $entry['name'] : '');
                        $collection_label = isset($entry['name']) ? $entry['name'] : $collection_value;
                    } else {
                        $collection_value = $entry;
                        $collection_label = $entry;
                    }

                    $collection_value = sanitize_text_field((string) $collection_value);
                    $collection_label = sanitize_text_field((string) $collection_label);

                    if (!empty($collection_value)) {
                        $sets['collection::' . $collection_value] = sprintf(__('Collection: %s', 'gallery-widget'), $collection_label);
                    }
                }
            }
        }

        return $sets;
    }

    /**
     * Parse set key to type/value pair
     */
    private function parse_archive_set_key($set_key) {
        $parts = explode('::', (string) $set_key, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $type = $parts[0];
        $value = $parts[1];
        if (!in_array($type, array('date', 'collection'), true) || $value === '') {
            return null;
        }

        return array(
            'type' => $type,
            'value' => $value
        );
    }

    /**
     * Fetch images for a specific archive set
     */
    private function get_archive_set_images($type, $value) {
        $query = array();
        if ($type === 'date') {
            $query['date'] = $value;
        } elseif ($type === 'collection') {
            $query['collection'] = $value;
        }

        $data = $this->fetch_remote_json('/api/public/images', $query);
        if (is_wp_error($data)) {
            return array();
        }

        if (isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }

        return is_array($data) ? $data : array();
    }

    /**
     * Build human-readable label for a set
     */
    private function get_archive_set_label($set_key, $fallback_sets = array()) {
        if (isset($fallback_sets[$set_key])) {
            return $fallback_sets[$set_key];
        }

        $parsed = $this->parse_archive_set_key($set_key);
        if (!$parsed) {
            return $set_key;
        }

        if ($parsed['type'] === 'date') {
            return sprintf(__('Date: %s', 'gallery-widget'), $parsed['value']);
        }

        return sprintf(__('Collection: %s', 'gallery-widget'), $parsed['value']);
    }

    /**
     * Render archive section description
     */
    public function render_archive_section_description() {
        echo '<p>' . __('Diese Einstellungen steuern eine eigenständige Galerie-Liste für eine normale Seite (z.B. im Menü).', 'gallery-widget') . '</p>';
        echo '<p><strong>[gallery_widget_archive]</strong> ' . __('diesen Shortcode in eine Seite einfügen und die Seite im Menü verlinken.', 'gallery-widget') . '</p>';
    }

    /**
     * Render class section description
     */
    public function render_class_section_description() {
        echo '<p>' . __('Konfiguriert mehrere Sparten mit eigener URL-Struktur.', 'gallery-widget') . '</p>';
        echo '<p><code>/galerie/{sparte}</code> ' . __('zeigt eine paginierte Liste der Galerien.', 'gallery-widget') . '</p>';
        echo '<p><code>/galerie/{sparte}/{nummer}</code> ' . __('zeigt eine einzelne Galerie.', 'gallery-widget') . '</p>';
        echo '<p>' . __('Die konkreten URLs werden unten je Sparte angezeigt.', 'gallery-widget') . '</p>';
    }

    /**
     * Render multi-class configuration field
     */
    public function render_gallery_classes_field() {
        $classes = $this->get_gallery_classes();
        $available_sets = $this->get_available_archive_sets();

        if (empty($available_sets)) {
            echo '<p>' . __('Keine Bildersets gefunden. Prüfen Sie die API-Verbindung.', 'gallery-widget') . '</p>';
            return;
        }

        if (empty($classes)) {
            $classes = array(
                array(
                    'slug' => 'kart',
                    'label' => 'Kart',
                    'per_page' => 6,
                    'sets' => array(),
                    'set_labels' => array()
                )
            );
        }

        echo '<div id="gallery-widget-classes-container">';
        foreach ($classes as $idx => $class) {
            $slug = isset($class['slug']) ? $class['slug'] : '';
            $label = isset($class['label']) ? $class['label'] : '';
            $per_page = isset($class['per_page']) ? max(1, (int) $class['per_page']) : 6;
            $selected_sets = isset($class['sets']) && is_array($class['sets']) ? $class['sets'] : array();
            $set_labels = isset($class['set_labels']) && is_array($class['set_labels']) ? $class['set_labels'] : array();
            $list_url = $slug !== '' ? home_url('/galerie/' . rawurlencode($slug) . '/') : '';

            echo '<div class="gallery-widget-class-card" data-class-index="' . esc_attr($idx) . '" style="border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:14px;background:#fff;">';
            echo '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">';
            echo '<label><span style="display:block;margin-bottom:4px;">' . esc_html__('Sparte URL-Slug', 'gallery-widget') . '</span><input class="regular-text" type="text" name="gallery_widget_classes[' . esc_attr($idx) . '][slug]" value="' . esc_attr($slug) . '" placeholder="kart"></label>';
            echo '<label><span style="display:block;margin-bottom:4px;">' . esc_html__('Sparte Titel', 'gallery-widget') . '</span><input class="regular-text" type="text" name="gallery_widget_classes[' . esc_attr($idx) . '][label]" value="' . esc_attr($label) . '" placeholder="Kart"></label>';
            echo '<label><span style="display:block;margin-bottom:4px;">' . esc_html__('Galerien pro Seite', 'gallery-widget') . '</span><input class="small-text" type="number" min="1" max="30" name="gallery_widget_classes[' . esc_attr($idx) . '][per_page]" value="' . esc_attr($per_page) . '"></label>';
            echo '<button type="button" class="button gallery-widget-remove-class">' . esc_html__('Sparte entfernen', 'gallery-widget') . '</button>';
            echo '</div>';

            echo '<div style="margin-top:12px;">';
            echo '<strong>' . esc_html__('Bildersets auswählen', 'gallery-widget') . '</strong>';
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:6px;margin-top:8px;">';
            foreach ($available_sets as $set_key => $set_default_label) {
                echo '<label><input type="checkbox" name="gallery_widget_classes[' . esc_attr($idx) . '][sets][]" value="' . esc_attr($set_key) . '" ' . checked(in_array($set_key, $selected_sets, true), true, false) . '> ' . esc_html($set_default_label) . '</label>';
            }
            echo '</div>';
            echo '</div>';

            if (!empty($selected_sets)) {
                echo '<div style="margin-top:12px;">';
                echo '<strong>' . esc_html__('Eigener Galerie-Name pro gewähltem Set', 'gallery-widget') . '</strong>';
                foreach ($selected_sets as $set_key) {
                    $default_label = $this->get_archive_set_label($set_key, $available_sets);
                    $current_label = isset($set_labels[$set_key]) ? $set_labels[$set_key] : '';
                    echo '<label style="display:block;margin-top:8px;">';
                    echo '<span style="display:block;margin-bottom:4px;">' . esc_html($default_label) . '</span>';
                    echo '<input class="regular-text" type="text" name="gallery_widget_classes[' . esc_attr($idx) . '][set_labels][' . esc_attr($set_key) . ']" value="' . esc_attr($current_label) . '" placeholder="' . esc_attr($default_label) . '">';
                    echo '</label>';
                }
                echo '</div>';
            }

            echo '<div style="margin-top:12px;padding:10px;border:1px solid #f0f0f1;border-radius:4px;background:#f9f9f9;">';
            echo '<strong>' . esc_html__('Galerie URLs', 'gallery-widget') . '</strong>';
            if ($list_url !== '') {
                echo '<p style="margin:8px 0 6px;"><span>' . esc_html__('Übersicht:', 'gallery-widget') . '</span> <a href="' . esc_url($list_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($list_url) . '</a></p>';
            } else {
                echo '<p style="margin:8px 0 6px;">' . esc_html__('Bitte zuerst einen Sparte URL-Slug eintragen.', 'gallery-widget') . '</p>';
            }

            if ($list_url !== '' && !empty($selected_sets)) {
                echo '<ul style="margin:6px 0 0 18px;list-style:disc;">';
                foreach (array_values($selected_sets) as $set_index => $set_key) {
                    $detail_url = home_url('/galerie/' . rawurlencode($slug) . '/' . ($set_index + 1) . '/');
                    $detail_label = isset($set_labels[$set_key]) && $set_labels[$set_key] !== ''
                        ? $set_labels[$set_key]
                        : $this->get_archive_set_label($set_key, $available_sets);
                    echo '<li><span>' . esc_html($detail_label) . ':</span> <a href="' . esc_url($detail_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($detail_url) . '</a></li>';
                }
                echo '</ul>';
            }
            echo '</div>';

            echo '</div>';
        }
        echo '</div>';

        echo '<p><button type="button" class="button button-secondary" id="gallery-widget-add-class">' . esc_html__('Sparte hinzufügen', 'gallery-widget') . '</button></p>';
        echo '<p class="description">' . esc_html__('Nach Änderungen bei den Sparten einmal Permalinks speichern, damit neue URLs aktiv sind.', 'gallery-widget') . '</p>';

        $set_options = '';
        foreach ($available_sets as $set_key => $set_label) {
            $set_options .= '<label><input type="checkbox" name="gallery_widget_classes[__INDEX__][sets][]" value="' . esc_attr($set_key) . '"> ' . esc_html($set_label) . '</label>';
        }

        echo '<script>';
        echo '(function(){';
        echo 'var container=document.getElementById("gallery-widget-classes-container");';
        echo 'var addBtn=document.getElementById("gallery-widget-add-class");';
        echo 'if(!container||!addBtn){return;}';
        echo 'function bindRemoveButtons(){var buttons=container.querySelectorAll(".gallery-widget-remove-class");buttons.forEach(function(btn){btn.onclick=function(){var card=btn.closest(".gallery-widget-class-card");if(card){card.remove();}};});}';
        echo 'bindRemoveButtons();';
        echo 'addBtn.onclick=function(){var index=Date.now();var card=document.createElement("div");card.className="gallery-widget-class-card";card.setAttribute("data-class-index",String(index));card.style.cssText="border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:14px;background:#fff;";';
        echo 'card.innerHTML=' . wp_json_encode(
            '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">'
            . '<label><span style="display:block;margin-bottom:4px;">' . esc_html__('Sparte URL-Slug', 'gallery-widget') . '</span><input class="regular-text" type="text" name="gallery_widget_classes[__INDEX__][slug]" placeholder="kart"></label>'
            . '<label><span style="display:block;margin-bottom:4px;">' . esc_html__('Sparte Titel', 'gallery-widget') . '</span><input class="regular-text" type="text" name="gallery_widget_classes[__INDEX__][label]" placeholder="Kart"></label>'
            . '<label><span style="display:block;margin-bottom:4px;">' . esc_html__('Galerien pro Seite', 'gallery-widget') . '</span><input class="small-text" type="number" min="1" max="30" name="gallery_widget_classes[__INDEX__][per_page]" value="6"></label>'
            . '<button type="button" class="button gallery-widget-remove-class">' . esc_html__('Sparte entfernen', 'gallery-widget') . '</button>'
            . '</div>'
            . '<div style="margin-top:12px;"><strong>' . esc_html__('Bildersets auswählen', 'gallery-widget') . '</strong><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:6px;margin-top:8px;">' . $set_options . '</div></div>'
        ) . ';';
        echo 'card.innerHTML=card.innerHTML.replaceAll("__INDEX__",String(index));';
        echo 'container.appendChild(card);bindRemoveButtons();};';
        echo '})();';
        echo '</script>';
    }

    /**
     * Render archive sets field
     */
    public function render_archive_sets_field() {
        $selected_sets = get_option('gallery_widget_archive_sets', array());
        $available_sets = $this->get_available_archive_sets();

        if (empty(get_option('gallery_widget_base_url', ''))) {
            echo '<p>' . __('Bitte zuerst die Base URL konfigurieren.', 'gallery-widget') . '</p>';
            return;
        }

        if (empty($available_sets)) {
            echo '<p>' . __('Keine Bildersets gefunden. Prüfen Sie die API-Verbindung.', 'gallery-widget') . '</p>';
            return;
        }

        echo '<fieldset>';
        foreach ($available_sets as $set_key => $label) {
            printf(
                '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="gallery_widget_archive_sets[]" value="%s" %s> %s</label>',
                esc_attr($set_key),
                checked(in_array($set_key, $selected_sets, true), true, false),
                esc_html($label)
            );
        }
        echo '</fieldset>';
    }

    /**
     * Render preview selection field
     */
    public function render_archive_preview_map_field() {
        $selected_sets = get_option('gallery_widget_archive_sets', array());
        $preview_map = get_option('gallery_widget_archive_preview_map', array());
        $available_sets = $this->get_available_archive_sets();

        if (empty($selected_sets)) {
            echo '<p>' . __('Wählen Sie zuerst Bildersets aus.', 'gallery-widget') . '</p>';
            return;
        }

        foreach ($selected_sets as $set_key) {
            $parsed = $this->parse_archive_set_key($set_key);
            if (!$parsed) {
                continue;
            }

            $label = $this->get_archive_set_label($set_key, $available_sets);
            $images = $this->get_archive_set_images($parsed['type'], $parsed['value']);
            $current_previews = isset($preview_map[$set_key]) && is_array($preview_map[$set_key]) ? $preview_map[$set_key] : array();

            echo '<div style="margin-bottom:18px;padding:10px;border:1px solid #dcdcde;border-radius:4px;">';
            echo '<strong>' . esc_html($label) . '</strong>';

            if (empty($images)) {
                echo '<p style="margin-top:8px;">' . __('Keine Bilder zum Auswählen verfügbar. Es wird automatisch der Fallback genutzt.', 'gallery-widget') . '</p>';
                echo '</div>';
                continue;
            }

            echo '<p style="margin-top:8px;">' . __('Optional konkrete Vorschaubilder wählen. Wenn nichts markiert ist, werden automatisch die ersten X Bilder verwendet.', 'gallery-widget') . '</p>';
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">';

            $max_options = min(count($images), 20);
            for ($i = 0; $i < $max_options; $i++) {
                $image = $images[$i];
                $preview_url = '';
                if (isset($image['thumbnail_url']) && !empty($image['thumbnail_url'])) {
                    $preview_url = esc_url($image['thumbnail_url']);
                } elseif (isset($image['public_url']) && !empty($image['public_url'])) {
                    $preview_url = esc_url($image['public_url']);
                }

                $source_url = isset($image['public_url']) ? $image['public_url'] : (isset($image['thumbnail_url']) ? $image['thumbnail_url'] : '');
                $image_value = $this->extract_hash_from_path((string) $source_url);
                if (empty($image_value)) {
                    continue;
                }

                $image_title = isset($image['title']) ? sanitize_text_field($image['title']) : '';
                if ($image_title === '') {
                    $image_title = sprintf(__('Bild %d', 'gallery-widget'), $i + 1);
                }

                printf(
                    '<label style="display:flex;align-items:center;gap:8px;border:1px solid #f0f0f1;padding:6px;border-radius:4px;"><input type="checkbox" name="gallery_widget_archive_preview_map[%1$s][]" value="%2$s" %3$s>%4$s<span>%5$s</span></label>',
                    esc_attr($set_key),
                    esc_attr($image_value),
                    checked(in_array(strtolower($image_value), array_map('strtolower', $current_previews), true), true, false),
                    $preview_url ? '<img src="' . $preview_url . '" alt="" style="width:52px;height:52px;object-fit:cover;border-radius:4px;">' : '',
                    esc_html($image_title)
                );
            }

            echo '</div>';
            echo '</div>';
        }
    }

    /**
     * Render fallback preview count field
     */
    public function render_archive_preview_count_field() {
        $value = get_option('gallery_widget_archive_preview_count', 4);
        ?>
        <input type="number"
               id="gallery_widget_archive_preview_count"
               name="gallery_widget_archive_preview_count"
               value="<?php echo esc_attr(max(1, (int) $value)); ?>"
               min="1"
               max="20"
               class="small-text">
        <p class="description">
            <?php _e('Wenn für ein Bilderset keine Vorschaubilder gewählt sind, werden die ersten X Bilder angezeigt.', 'gallery-widget'); ?>
        </p>
        <?php
    }

    /**
     * Render standalone archive shortcode output
     */
    public function render_gallery_archive_shortcode($atts = array()) {
        $selected_sets = get_option('gallery_widget_archive_sets', array());
        $preview_map = get_option('gallery_widget_archive_preview_map', array());
        $preview_count = max(1, (int) get_option('gallery_widget_archive_preview_count', 4));
        $available_sets = $this->get_available_archive_sets();

        if (empty($selected_sets)) {
            return '<div class="gallery-widget-empty">Keine Bildersets konfiguriert.</div>';
        }

        $html = '<div class="gallery-widget-archive-list">';
        foreach ($selected_sets as $set_key) {
            $parsed = $this->parse_archive_set_key($set_key);
            if (!$parsed) {
                continue;
            }

            $label = $this->get_archive_set_label($set_key, $available_sets);
            $selected_previews = isset($preview_map[$set_key]) && is_array($preview_map[$set_key]) ? array_values($preview_map[$set_key]) : array();

            $html .= sprintf(
                '<article class="gallery-widget-archive-item" data-set-key="%1$s" data-set-title="%2$s" data-source-type="%3$s" data-source-value="%4$s" data-preview-count="%5$d" data-selected-previews="%6$s">',
                esc_attr($set_key),
                esc_attr($label),
                esc_attr($parsed['type']),
                esc_attr($parsed['value']),
                esc_attr($preview_count),
                esc_attr(wp_json_encode($selected_previews))
            );
            $html .= '<h3 class="gallery-widget-archive-title">' . esc_html($label) . '</h3>';
            $html .= '<div class="gallery-widget-archive-content"><div class="gallery-widget-loading">Lade Galerie...</div></div>';
            $html .= '</article>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Build configured class gallery definitions
     */
    private function get_class_gallery_definitions($class_config) {
        $selected_sets = isset($class_config['sets']) && is_array($class_config['sets']) ? $class_config['sets'] : array();
        $custom_labels = isset($class_config['set_labels']) && is_array($class_config['set_labels']) ? $class_config['set_labels'] : array();
        $available_sets = $this->get_available_archive_sets();

        $items = array();
        foreach ($selected_sets as $set_key) {
            $parsed = $this->parse_archive_set_key($set_key);
            if (!$parsed) {
                continue;
            }

            $default_label = $this->get_archive_set_label($set_key, $available_sets);
            $title = isset($custom_labels[$set_key]) && $custom_labels[$set_key] !== ''
                ? sanitize_text_field($custom_labels[$set_key])
                : $default_label;

            $items[] = array(
                'set_key' => $set_key,
                'type' => $parsed['type'],
                'value' => $parsed['value'],
                'title' => $title
            );
        }

        return $items;
    }

    /**
     * Find class config by slug
     */
    private function find_gallery_class_by_slug($slug) {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return null;
        }

        foreach ($this->get_gallery_classes() as $class) {
            if (isset($class['slug']) && sanitize_title($class['slug']) === $slug) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Build detail URL for gallery entry
     */
    private function get_gallery_detail_url($class_slug, $index) {
        return home_url('/galerie/' . rawurlencode($class_slug) . '/' . absint($index) . '/');
    }

    /**
     * Build pagination URL for class list
     */
    private function get_gallery_list_page_url($class_slug, $page) {
        $page = max(1, (int) $page);
        if ($page === 1) {
            return home_url('/galerie/' . rawurlencode($class_slug) . '/');
        }

        return home_url('/galerie/' . rawurlencode($class_slug) . '/seite/' . $page . '/');
    }

    /**
     * Convert media item URL to proxy URL when hash is available
     */
    private function get_proxy_media_url($url, $is_thumb = false) {
        $hash = $this->extract_hash_from_path((string) $url);
        if (!$hash) {
            return '';
        }

        $proxy = rest_url('gallery-widget/v1/image/' . $hash);
        if ($is_thumb) {
            $proxy .= '?thumb=1';
        }
        return $proxy;
    }

    /**
     * Select preview images from a set using explicit hash map or fallback count
     */
    private function select_preview_images($set_key, $images) {
        $preview_map = get_option('gallery_widget_archive_preview_map', array());
        $preview_count = max(1, (int) get_option('gallery_widget_archive_preview_count', 4));

        if (!empty($preview_map[$set_key]) && is_array($preview_map[$set_key])) {
            $selected_hashes = array_map('strtolower', $preview_map[$set_key]);
            $explicit = array();

            foreach ($images as $image) {
                $candidate_url = isset($image['public_url']) ? $image['public_url'] : (isset($image['thumbnail_url']) ? $image['thumbnail_url'] : '');
                $hash = $this->extract_hash_from_path((string) $candidate_url);
                if ($hash && in_array(strtolower($hash), $selected_hashes, true)) {
                    $explicit[] = $image;
                }
            }

            if (!empty($explicit)) {
                return $explicit;
            }
        }

        return array_slice($images, 0, $preview_count);
    }

    /**
     * Render class list route
     */
    private function render_class_list_page($class_config) {
        $class_slug = sanitize_title($class_config['slug']);
        $class_label = isset($class_config['label']) ? $class_config['label'] : ucfirst($class_slug);
        $per_page = isset($class_config['per_page']) ? max(1, (int) $class_config['per_page']) : 6;
        $current_page = max(1, absint(get_query_var('gallery_widget_paged')));

        $all_items = $this->get_class_gallery_definitions($class_config);
        $total = count($all_items);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }

        $offset = ($current_page - 1) * $per_page;
        $page_items = array_slice($all_items, $offset, $per_page);

        status_header(200);
        nocache_headers();
        get_header();

        echo '<main class="gallery-widget-route gallery-widget-route-list">';
        echo '<div class="gallery-widget-route-inner">';
        echo '<h1>' . esc_html($class_label) . '</h1>';

        if (empty($page_items)) {
            echo '<div class="gallery-widget-empty">Keine Galerien konfiguriert.</div>';
        } else {
            echo '<div class="gallery-widget-archive-list">';
            foreach ($page_items as $idx => $item) {
                $absolute_index = $offset + $idx + 1;
                $images = $this->get_archive_set_images($item['type'], $item['value']);
                $preview_images = $this->select_preview_images($item['set_key'], $images);
                $detail_url = $this->get_gallery_detail_url($class_slug, $absolute_index);

                echo '<article class="gallery-widget-archive-item">';
                echo '<h3 class="gallery-widget-archive-title"><a href="' . esc_url($detail_url) . '">' . esc_html($item['title']) . '</a></h3>';
                echo '<div class="gallery-widget-archive-meta">' . sprintf(esc_html__('%d Bilder', 'gallery-widget'), count($images)) . '</div>';

                if (empty($preview_images)) {
                    echo '<div class="gallery-widget-empty">Keine Vorschaubilder verfügbar.</div>';
                } else {
                    echo '<div class="gallery-widget-grid columns-4">';
                    foreach ($preview_images as $image) {
                        $full_url = '';
                        $thumb_url = '';

                        if (!empty($image['public_url'])) {
                            $full_url = $this->get_proxy_media_url($image['public_url'], false);
                        }
                        if (!empty($image['thumbnail_url'])) {
                            $thumb_url = $this->get_proxy_media_url($image['thumbnail_url'], true);
                        }
                        if ($thumb_url === '' && $full_url !== '') {
                            $thumb_url = $full_url;
                        }

                        if ($thumb_url === '') {
                            continue;
                        }

                        $image_title = isset($image['title']) ? sanitize_text_field($image['title']) : '';
                        $image_alt = isset($image['alt']) && $image['alt'] !== '' ? sanitize_text_field($image['alt']) : ($image_title !== '' ? $image_title : 'Bild');

                        echo '<a class="gallery-widget-item" href="' . esc_url($detail_url) . '">';
                        echo '<img src="' . esc_url($thumb_url) . '" alt="' . esc_attr($image_alt) . '" title="' . esc_attr($image_title) . '" loading="lazy">';
                        echo '</a>';
                    }
                    echo '</div>';
                }

                echo '<p><a class="button" href="' . esc_url($detail_url) . '">Galerie öffnen</a></p>';
                echo '</article>';
            }
            echo '</div>';

            if ($total_pages > 1) {
                echo '<nav class="gallery-widget-pagination" aria-label="Galerie Seiten">';
                if ($current_page > 1) {
                    echo '<a class="button" href="' . esc_url($this->get_gallery_list_page_url($class_slug, $current_page - 1)) . '">Zurück</a> ';
                }
                echo '<span>' . sprintf(esc_html__('Seite %1$d von %2$d', 'gallery-widget'), $current_page, $total_pages) . '</span>';
                if ($current_page < $total_pages) {
                    echo ' <a class="button" href="' . esc_url($this->get_gallery_list_page_url($class_slug, $current_page + 1)) . '">Weiter</a>';
                }
                echo '</nav>';
            }
        }

        echo '</div>';
        echo '</main>';

        get_footer();
    }

    /**
     * Render class detail route
     */
    private function render_class_detail_page($class_config, $item_number) {
        $class_slug = sanitize_title($class_config['slug']);
        $item_number = absint($item_number);
        $items = $this->get_class_gallery_definitions($class_config);

        if ($item_number < 1 || $item_number > count($items)) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            include get_404_template();
            return;
        }

        $item = $items[$item_number - 1];
        $dates = $item['type'] === 'date' ? array($item['value']) : array();
        $collections = $item['type'] === 'collection' ? array($item['value']) : array();

        status_header(200);
        nocache_headers();
        get_header();

        echo '<main class="gallery-widget-route gallery-widget-route-detail">';
        echo '<div class="gallery-widget-route-inner">';
        echo '<p><a href="' . esc_url($this->get_gallery_list_page_url($class_slug, 1)) . '">&larr; Zurück zur Übersicht</a></p>';
        echo '<h1>' . esc_html($item['title']) . '</h1>';
        echo sprintf(
            '<div class="wp-block-gallery-widget-gallery" data-dates="%s" data-collections="%s" data-columns="4" data-show-title="false"><div class="gallery-widget-placeholder">Galerie wird geladen...</div></div>',
            esc_attr(wp_json_encode($dates)),
            esc_attr(wp_json_encode($collections))
        );
        echo '</div>';
        echo '</main>';

        get_footer();
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
            <p style="margin:10px 0 16px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">
                <strong><?php _e('Laufzeit-Info', 'gallery-widget'); ?>:</strong>
                <?php
                printf(
                    /* translators: 1: plugin version, 2: loaded php file path */
                    esc_html__('Version %1$s | Geladene Datei: %2$s', 'gallery-widget'),
                    esc_html(GALLERY_WIDGET_VERSION),
                    esc_html(__FILE__)
                );
                ?>
            </p>
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
        $should_enqueue = has_block('gallery-widget/gallery');

        if (!$should_enqueue && get_query_var('gallery_widget_route')) {
            $should_enqueue = true;
        }

        if (!$should_enqueue && is_singular()) {
            global $post;
            if ($post instanceof WP_Post && has_shortcode($post->post_content, 'gallery_widget_archive')) {
                $should_enqueue = true;
            }
        }

        if ($should_enqueue) {
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

/**
 * Activate plugin and flush rewrite rules for custom gallery routes
 */
function gallery_widget_activate_plugin() {
    $plugin = Gallery_Widget_Plugin::get_instance();
    $plugin->register_rewrite_rules();
    flush_rewrite_rules();
}

/**
 * Deactivate plugin and flush rewrite rules
 */
function gallery_widget_deactivate_plugin() {
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'gallery_widget_activate_plugin');
register_deactivation_hook(__FILE__, 'gallery_widget_deactivate_plugin');

// Initialize the plugin
Gallery_Widget_Plugin::get_instance();
