<?php
/**
 * Plugin Name: FRS API Sync
 * Plugin URI: https://base.frs.works
 * Description: Syncs loan officers from FRS API to WordPress Person CPT and links user accounts
 * Version: 1.2.0
 * Author: FRS Team
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Set defaults on activation
register_activation_hook(__FILE__, 'frs_set_default_options');
function frs_set_default_options() {
    add_option('frs_api_base_url', 'https://base.frs.works/api');
    add_option('frs_api_token', 'frs_hook_6qLiMQhWkB4DMdkc7tsD5DMBhnEVOjgE');
    add_option('frs_auto_sync', '1');
}

class FRS_API_Sync {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // User registration hooks
        add_action('user_register', array($this, 'link_user_to_person_cpt'));
        add_action('wp_login', array($this, 'check_person_link_on_login'), 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_frs_sync_loan_officers', array($this, 'sync_loan_officers'));
        add_action('wp_ajax_frs_get_sync_status', array($this, 'get_sync_status'));
        add_action('wp_ajax_frs_test_api_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_frs_setup_webhook', array($this, 'setup_webhook_with_api'));
        
        // REST API endpoint for webhooks
        add_action('rest_api_init', array($this, 'register_webhook_endpoints'));
        
        // Admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add meta box to person edit screen
        add_action('add_meta_boxes', array($this, 'add_person_meta_boxes'));
    }
    
    public function init() {
        // Schedule daily sync if not already scheduled
        if (!wp_next_scheduled('frs_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'frs_daily_sync');
        }
        add_action('frs_daily_sync', array($this, 'daily_sync_cron'));
    }
    
    public function register_settings() {
        register_setting('frs_sync_settings', 'frs_api_token');
        register_setting('frs_sync_settings', 'frs_api_base_url'); 
        register_setting('frs_sync_settings', 'frs_auto_sync');
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=person',
            'API Sync',
            'API Sync',
            'manage_options',
            'frs-api-sync',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'frs-api-sync') !== false) {
            add_action('admin_footer', array($this, 'admin_inline_js'));
        }
    }
    
    public function admin_page() {
        $api_token = get_option('frs_api_token', '');
        $api_base_url = get_option('frs_api_base_url', 'https://base.frs.works/api');
        $auto_sync = get_option('frs_auto_sync', '1');
        ?>
        <div class="wrap">
            <h1>FRS API Sync Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('frs_sync_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Base URL</th>
                        <td>
                            <input type="url" name="frs_api_base_url" value="<?php echo esc_attr($api_base_url); ?>" class="regular-text" />
                            <p class="description">Base URL for FRS API (e.g., https://base.frs.works/api)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Token</th>
                        <td>
                            <input type="text" name="frs_api_token" value="<?php echo esc_attr($api_token); ?>" class="regular-text" />
                            <p class="description">Your FRS API authentication token (used for both API access and webhook verification)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto Sync</th>
                        <td>
                            <label>
                                <input type="checkbox" name="frs_auto_sync" value="1" <?php checked($auto_sync, '1'); ?> />
                                Enable daily automatic sync
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            </form>
            <h2>Webhook Information</h2>
            <div id="webhook-info" style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0;">
                <h4>Webhook Endpoint</h4>
                <p><strong>URL:</strong> <code><?php echo esc_html($this->get_webhook_url()); ?></code></p>
                <p><strong>Method:</strong> POST</p>
                <p><strong>Events:</strong> agent.created, agent.updated, agent.deleted, bulk.import.completed, bulk.update.completed</p>
                <p><strong>Security:</strong> HMAC-SHA256 signature verification</p>
                <?php 
                $webhook_id = get_option('frs_webhook_id');
                $webhook_secret = get_option('frs_webhook_secret');
                if ($webhook_id): ?>
                    <p><strong>Webhook ID:</strong> <?php echo esc_html($webhook_id); ?></p>
                    <p><strong>Secret:</strong> <?php echo $webhook_secret ? '✅ Configured' : '❌ Not set'; ?></p>
                <?php endif; ?>
                <p class="description">This URL will be automatically registered with the FRS API when you click "Setup Webhook"</p>
            </div>
            
            <hr>
            
            <h2>Sync Status</h2>
            <div id="sync-status">Loading...</div>
            
            <h2>Actions</h2>
            <p>
                <button type="button" id="test-api-connection" class="button">Test API Connection</button>
                <button type="button" id="sync-loan-officers" class="button button-primary">Sync Loan Officers Now</button>
                <button type="button" id="setup-webhook" class="button">Setup Webhook</button>
            </p>
            
            <!-- Progress Bar -->
            <div id="sync-progress-container" style="display: none; margin: 20px 0;">
                <h3>Sync Progress</h3>
                <div style="background: #f1f1f1; border-radius: 10px; padding: 3px; margin: 10px 0;">
                    <div id="sync-progress-bar" style="background: #0073aa; height: 20px; border-radius: 8px; width: 0%; transition: width 0.3s ease;"></div>
                </div>
                <div id="sync-progress-text">Preparing sync...</div>
                <div id="sync-progress-details" style="font-size: 12px; color: #666; margin-top: 5px;"></div>
            </div>
            
            <div id="webhook-status"></div>
            <div id="sync-results"></div>
        </div>
        <?php
    }
    
    public function admin_inline_js() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            var frs_sync_ajax = {
                ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
                nonce: '<?php echo wp_create_nonce('frs_sync_nonce'); ?>'
            };
            
            // Load sync status on page load
            loadSyncStatus();
            
            function loadSyncStatus() {
                $.ajax({
                    url: frs_sync_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'frs_get_sync_status',
                        nonce: frs_sync_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#sync-status').html(response.data);
                        } else {
                            $('#sync-status').html('<div class="notice notice-error"><p>Error loading status</p></div>');
                        }
                    }
                });
            }
            
            function performSync() {
                var $button = $('#sync-loan-officers');
                var $progressContainer = $('#sync-progress-container');
                var $progressBar = $('#sync-progress-bar');
                var $progressText = $('#sync-progress-text');
                var $progressDetails = $('#sync-progress-details');
                
                $button.prop('disabled', true).text('Syncing...');
                $progressContainer.show();
                $progressBar.css('width', '0%');
                $progressText.text('Initializing sync...');
                $progressDetails.text('');
                $('#sync-results').html('');
                
                // Start the sync process
                performSyncBatch(0, true);
            }
            
            function performSyncBatch(offset, isInitial) {
                var $progressBar = $('#sync-progress-bar');
                var $progressText = $('#sync-progress-text');
                var $progressDetails = $('#sync-progress-details');
                
                $.ajax({
                    url: frs_sync_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'frs_sync_loan_officers',
                        nonce: frs_sync_ajax.nonce,
                        offset: offset,
                        batch_size: 10,
                        is_initial: isInitial
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            
                            // Update progress bar
                            $progressBar.css('width', data.progress + '%');
                            $progressText.text('Syncing... ' + data.progress + '% complete');
                            $progressDetails.text(
                                'Processed: ' + data.processed + '/' + data.total + 
                                (data.errors > 0 ? ' (Errors: ' + data.errors + ')' : '')
                            );
                            
                            if (data.has_more) {
                                // Continue with next batch
                                setTimeout(function() {
                                    performSyncBatch(data.next_offset, false);
                                }, 500); // Small delay to show progress
                            } else {
                                // Sync complete
                                completeSyncProcess(data);
                            }
                        } else {
                            handleSyncError(response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        handleSyncError('Network error: ' + error);
                    }
                });
            }
            
            function completeSyncProcess(data) {
                var $button = $('#sync-loan-officers');
                var $progressContainer = $('#sync-progress-container');
                var $progressText = $('#sync-progress-text');
                var $progressDetails = $('#sync-progress-details');
                
                $progressText.text('Sync completed successfully!');
                $progressDetails.text(
                    'Total processed: ' + data.processed + 
                    (data.errors > 0 ? ' (Errors: ' + data.errors + ')' : '')
                );
                
                $('#sync-results').html(
                    '<div class="notice notice-success"><p>✅ ' + data.message + '</p></div>'
                );
                
                loadSyncStatus();
                
                // Hide progress bar after 3 seconds
                setTimeout(function() {
                    $progressContainer.fadeOut();
                }, 3000);
                
                $button.prop('disabled', false).text('Sync Loan Officers Now');
            }
            
            function handleSyncError(errorMessage) {
                var $button = $('#sync-loan-officers');
                var $progressContainer = $('#sync-progress-container');
                
                $('#sync-results').html(
                    '<div class="notice notice-error"><p>❌ ' + errorMessage + '</p></div>'
                );
                
                $progressContainer.hide();
                $button.prop('disabled', false).text('Sync Loan Officers Now');
            }
            
            // Bind sync button
            $('#sync-loan-officers').on('click', function() {
                performSync();
            });
            
            // Setup webhook with FRS API
            $('#setup-webhook').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true).text('Setting up...');
                
                $.ajax({
                    url: frs_sync_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'frs_setup_webhook',
                        nonce: frs_sync_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#webhook-status').html(
                                '<div class="notice notice-success"><p>✅ ' + response.data + '</p></div>'
                            );
                            loadSyncStatus();
                        } else {
                            $('#webhook-status').html(
                                '<div class="notice notice-error"><p>❌ ' + response.data + '</p></div>'
                            );
                        }
                    },
                    error: function() {
                        $('#webhook-status').html(
                            '<div class="notice notice-error"><p>❌ Failed to setup webhook</p></div>'
                        );
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Setup Webhook');
                    }
                });
            });
            
            // Test API connection
            $('#test-api-connection').on('click', function() {
                var $button = $(this);
                $button.prop('disabled', true).text('Testing...');
                
                $.ajax({
                    url: frs_sync_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'frs_test_api_connection',
                        nonce: frs_sync_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#sync-results').html('<div class="notice notice-success"><p>✅ API Connection: ' + response.data + '</p></div>');
                        } else {
                            $('#sync-results').html('<div class="notice notice-error"><p>❌ API Connection Failed: ' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#sync-results').html('<div class="notice notice-error"><p>❌ Connection test failed</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('Test API Connection');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    // AJAX handler for testing API connection
    public function test_api_connection() {
        check_ajax_referer('frs_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $api_base_url = get_option('frs_api_base_url');
        $api_token = get_option('frs_api_token');
        
        if (empty($api_base_url) || empty($api_token)) {
            wp_send_json_error('API URL and Token are required');
        }
        
        $response = wp_remote_get($api_base_url . '/dashboard', array(
            'headers' => array(
                'X-API-Token' => $api_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 200) {
            wp_send_json_success('Connected successfully');
        } else {
            wp_send_json_error('HTTP ' . $response_code);
        }
    }
    
    // AJAX handler for getting sync status
    public function get_sync_status() {
        check_ajax_referer('frs_sync_nonce', 'nonce');
        
        $last_sync = get_option('frs_last_sync_time');
        $total_loan_officers = wp_count_posts('person')->publish ?? 0;
        $linked_users = get_posts(array(
            'post_type' => 'person',
            'meta_query' => array(
                array(
                    'key' => 'account',
                    'compare' => 'EXISTS'
                )
            ),
            'fields' => 'ids',
            'posts_per_page' => -1
        ));
        
        $status_html = '<table class="wp-list-table widefat fixed striped">';
        $status_html .= '<tr><td><strong>Total People:</strong></td><td>' . $total_loan_officers . '</td></tr>';
        $status_html .= '<tr><td><strong>Linked Users:</strong></td><td>' . count($linked_users) . '</td></tr>';
        $status_html .= '<tr><td><strong>Last Sync:</strong></td><td>' . ($last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Never') . '</td></tr>';
        $status_html .= '</table>';
        
        wp_send_json_success($status_html);
    }
    
    // AJAX handler for syncing loan officers
    public function sync_loan_officers() {
        check_ajax_referer('frs_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $batch_size = intval($_POST['batch_size'] ?? 10);
        $offset = intval($_POST['offset'] ?? 0);
        $is_initial = $_POST['is_initial'] === 'true';
        
        if ($is_initial) {
            // First request - get total count and start sync
            $total_count = $this->get_total_loan_officers_count();
            if ($total_count === false) {
                wp_send_json_error('Failed to get total count from API');
                return;
            }
            
            // Store total count for progress calculation
            set_transient('frs_sync_total_count', $total_count, 3600);
            set_transient('frs_sync_processed_count', 0, 3600);
            set_transient('frs_sync_start_time', time(), 3600);
        }
        
        $result = $this->fetch_and_sync_loan_officers_batch($offset, $batch_size);
        
        if ($result['success']) {
            $total_count = get_transient('frs_sync_total_count');
            $processed_count = get_transient('frs_sync_processed_count') + $result['processed'];
            set_transient('frs_sync_processed_count', $processed_count, 3600);
            
            $progress_percent = $total_count > 0 ? min(100, round(($processed_count / $total_count) * 100)) : 100;
            $has_more = $processed_count < $total_count;
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'progress' => $progress_percent,
                'processed' => $processed_count,
                'total' => $total_count,
                'has_more' => $has_more,
                'next_offset' => $offset + $batch_size,
                'errors' => $result['errors'] ?? 0
            ));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    // Get total count of loan officers from API
    private function get_total_loan_officers_count() {
        $api_base_url = get_option('frs_api_base_url');
        $api_token = get_option('frs_api_token');
        
        $response = wp_remote_get($api_base_url . '/agents?role=loan_officer&limit=1', array(
            'headers' => array(
                'X-API-Token' => $api_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data['total_count'] ?? count($data['agents'] ?? []);
    }
    
    // Core sync function - batch processing
    public function fetch_and_sync_loan_officers_batch($offset = 0, $limit = 10) {
        $api_base_url = get_option('frs_api_base_url');
        $api_token = get_option('frs_api_token');
        
        if (empty($api_base_url) || empty($api_token)) {
            return array('success' => false, 'message' => 'API credentials not configured');
        }
        
        // Fetch loan officers from API with pagination
        $response = wp_remote_get($api_base_url . '/agents?role=loan_officer&limit=' . $limit . '&offset=' . $offset, array(
            'headers' => array(
                'X-API-Token' => $api_token
            ),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return array('success' => false, 'message' => 'API returned HTTP ' . $response_code);
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['agents'])) {
            return array('success' => false, 'message' => 'Invalid API response');
        }
        
        $synced = 0;
        $errors = 0;
        
        foreach ($data['agents'] as $agent) {
            if ($this->sync_single_agent($agent)) {
                $synced++;
            } else {
                $errors++;
            }
        }
        
        return array(
            'success' => true, 
            'message' => "Processed {$synced} loan officers" . ($errors > 0 ? " with {$errors} errors" : ""),
            'processed' => count($data['agents']),
            'synced' => $synced,
            'errors' => $errors
        );
    }
    
    // Legacy function - keep for backward compatibility
    public function fetch_and_sync_loan_officers() {
        $total_result = $this->get_total_loan_officers_count();
        if ($total_result === false) {
            return array('success' => false, 'message' => 'Failed to get total count');
        }
        
        $all_synced = 0;
        $all_errors = 0;
        $offset = 0;
        $batch_size = 50;
        
        while ($offset < $total_result) {
            $batch_result = $this->fetch_and_sync_loan_officers_batch($offset, $batch_size);
            
            if (!$batch_result['success']) {
                return $batch_result;
            }
            
            $all_synced += $batch_result['synced'];
            $all_errors += $batch_result['errors'];
            $offset += $batch_size;
            
            // Prevent timeout on large syncs
            if ($offset % 100 === 0) {
                sleep(1);
            }
        }
        
        update_option('frs_last_sync_time', time());
        
        return array(
            'success' => true, 
            'message' => "Synced {$all_synced} loan officers" . ($all_errors > 0 ? " with {$all_errors} errors" : "")
        );
    }
    
    // Sync single agent to Person CPT
    private function sync_single_agent($agent) {
        if (empty($agent['email'])) {
            return false;
        }
        
        // Look for existing person by email
        $existing_posts = get_posts(array(
            'post_type' => 'person',
            'meta_query' => array(
                array(
                    'key' => 'primary_business_email',
                    'value' => $agent['email'],
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        ));
        
        $post_data = array(
            'post_title' => trim($agent['first_name'] . ' ' . $agent['last_name']),
            'post_type' => 'person',
            'post_status' => 'publish',
            'post_content' => $agent['biography'] ?? '',
        );
        
        if ($existing_posts) {
            // Update existing
            $post_data['ID'] = $existing_posts[0]->ID;
            $post_id = wp_update_post($post_data);
        } else {
            // Create new
            $post_id = wp_insert_post($post_data);
        }
        
        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }
        
        // Update ACF fields
        $this->update_person_acf_fields($post_id, $agent);
        
        // Set taxonomy terms
        if (!empty($agent['role'])) {
            wp_set_object_terms($post_id, $agent['role'], 'role');
        }
        
        return true;
    }
    
    // Update ACF fields for a person
    private function update_person_acf_fields($post_id, $agent) {
        $field_mappings = array(
            'primary_business_email' => $agent['email'] ?? '',
            'phone_number' => $agent['phone'] ?? '',
            'job_title' => $agent['job_title'] ?? '',
            'nmls' => $agent['nmls_number'] ?? '',  // NMLS field name from ACF
            'license_number' => $agent['license_number'] ?? '', // DRE license field name from ACF
            'biography' => $agent['biography'] ?? '',
        );
        
        // Handle specialties array - FRS API provides 'specialties_lo' directly
        if (!empty($agent['specialties_lo'])) {
            $specialties = is_array($agent['specialties_lo']) ? $agent['specialties_lo'] : explode(',', $agent['specialties_lo']);
            $field_mappings['specialties_lo'] = array_map('trim', $specialties);
        }
        
        // Handle languages array - FRS API provides 'languages' directly
        if (!empty($agent['languages'])) {
            $languages = is_array($agent['languages']) ? $agent['languages'] : explode(',', $agent['languages']);
            $field_mappings['languages'] = array_map('trim', $languages);
        }
        
        // Handle headshot image
        if (!empty($agent['headshot_url'])) {
            $attachment_id = $this->upload_image_from_url($agent['headshot_url'], $post_id);
            if ($attachment_id) {
                $field_mappings['headshot'] = $attachment_id;
            }
        }
        
        // Update all fields
        foreach ($field_mappings as $field_name => $field_value) {
            if ($field_value !== '') {
                update_field($field_name, $field_value, $post_id);
            }
        }
        
        // Store FRS agent ID for reference
        update_post_meta($post_id, '_frs_agent_id', $agent['id'] ?? '');
        update_post_meta($post_id, '_frs_agent_uuid', $agent['uuid'] ?? '');
    }
    
    // Upload image from URL and attach to post
    private function upload_image_from_url($image_url, $post_id) {
        if (empty($image_url)) {
            return false;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return false;
        }
        
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );
        
        $attachment_id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }
        
        return $attachment_id;
    }
    
    // Link user to person CPT on registration
    public function link_user_to_person_cpt($user_id) {
        $user = get_user_by('ID', $user_id);
        if (!$user) return;
        
        $this->find_and_link_person_by_email($user->user_email, $user_id);
    }
    
    // Check for person link on login
    public function check_person_link_on_login($user_login, $user) {
        if (!$user) return;
        
        $this->find_and_link_person_by_email($user->user_email, $user->ID);
    }
    
    // Find person by email and link to user account
    private function find_and_link_person_by_email($email, $user_id) {
        $persons = get_posts(array(
            'post_type' => 'person',
            'meta_query' => array(
                array(
                    'key' => 'primary_business_email',
                    'value' => $email,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        ));
        
        if ($persons) {
            $person_id = $persons[0]->ID;
            
            // Check if already linked
            $linked_user = get_field('account', $person_id);
            if (!$linked_user) {
                update_field('account', $user_id, $person_id);
                
                // Add user meta pointing back to person
                update_user_meta($user_id, '_linked_person_id', $person_id);
            }
        }
    }
    
    // Setup webhook with FRS API
    public function setup_webhook_with_api() {
        check_ajax_referer('frs_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $api_base_url = get_option('frs_api_base_url');
        $api_token = get_option('frs_api_token');
        
        if (empty($api_base_url) || empty($api_token)) {
            wp_send_json_error('API credentials not configured');
        }
        
        // Auto-detect the webhook URL
        $webhook_url = $this->get_webhook_url();
        
        $webhook_data = array(
            'name' => 'WordPress Site - ' . get_bloginfo('name') . ' (' . parse_url($webhook_url, PHP_URL_HOST) . ')',
            'url' => $webhook_url,
            'events' => array('agent.created', 'agent.updated'),
            'active' => true
        );
        
        $response = wp_remote_post($api_base_url . '/webhooks', array(
            'headers' => array(
                'X-API-Token' => $api_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($webhook_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200 || $response_code === 201) {
            $body = json_decode($response_body, true);
            if (isset($body['webhook_id'])) {
                update_option('frs_webhook_id', $body['webhook_id']);
                update_option('frs_webhook_secret', $body['secret'] ?? '');
                wp_send_json_success('Webhook setup successfully. ID: ' . $body['webhook_id'] . ', URL: ' . $webhook_url);
            } else {
                wp_send_json_success('Webhook setup successfully. URL: ' . $webhook_url);
            }
        } else {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error'] ?? 'HTTP ' . $response_code;
            wp_send_json_error('Failed to setup webhook: ' . $error_message);
        }
    }
    
    // Get the properly formatted webhook URL
    private function get_webhook_url() {
        // Try different URL detection methods in order of preference
        $base_urls = array();
        
        // 1. WordPress home URL (most reliable)
        $base_urls[] = home_url();
        
        // 2. Site URL as fallback
        $base_urls[] = site_url();
        
        // 3. Try to detect from server variables if WordPress URLs are wrong
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
            $base_urls[] = $protocol . $_SERVER['HTTP_HOST'];
        }
        
        // Use the first available URL
        foreach ($base_urls as $base_url) {
            if (!empty($base_url)) {
                // Ensure HTTPS for production sites
                if (strpos($base_url, 'localhost') === false && strpos($base_url, '127.0.0.1') === false) {
                    $base_url = str_replace('http://', 'https://', $base_url);
                }
                
                return rtrim($base_url, '/') . '/wp-json/frs/v1/webhook';
            }
        }
        
        // Fallback if all else fails
        return 'https://' . $_SERVER['HTTP_HOST'] . '/wp-json/frs/v1/webhook';
    }
    
    // Register REST API endpoints for webhooks
    public function register_webhook_endpoints() {
        register_rest_route('frs/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }
    
    // Handle incoming webhook from FRS API
    public function handle_webhook($request) {
        // Get the webhook secret for HMAC verification
        $webhook_secret = get_option('frs_webhook_secret');
        
        // Verify HMAC signature if secret is available
        if (!empty($webhook_secret)) {
            $signature = $request->get_header('X-FRS-Signature');
            $body = $request->get_body();
            
            if ($signature && $this->verify_webhook_signature($body, $signature, $webhook_secret)) {
                // Signature is valid, proceed
            } else {
                error_log('FRS Webhook: Invalid HMAC signature');
                return new WP_Error('unauthorized', 'Invalid webhook signature', array('status' => 401));
            }
        }
        
        $body = $request->get_body();
        $data = json_decode($body, true);
        
        // Validate payload structure based on FRS documentation
        if (!$data || !isset($data['event']) || !isset($data['data']) || !isset($data['timestamp'])) {
            error_log('FRS Webhook: Invalid payload structure - ' . $body);
            return new WP_Error('invalid_payload', 'Invalid webhook payload', array('status' => 400));
        }
        
        $event = $data['event'];
        $agent_data = $data['data'];
        $webhook_id = $data['webhook_id'] ?? null;
        
        // Log webhook received for debugging
        error_log('FRS Webhook received: ' . $event . ' for agent ID: ' . ($agent_data['agent_id'] ?? $agent_data['id'] ?? 'unknown'));
        
        // Handle different event types based on FRS documentation
        switch ($event) {
            case 'agent.created':
                $this->handle_agent_created($agent_data);
                break;
                
            case 'agent.updated':
                $this->handle_agent_updated($agent_data);
                break;
                
            case 'agent.deleted':
                $this->handle_agent_deleted($agent_data);
                break;
                
            case 'bulk.import.completed':
                $this->handle_bulk_import_completed($agent_data);
                break;
                
            case 'bulk.update.completed':
                $this->handle_bulk_update_completed($agent_data);
                break;
                
            case 'webhook.test':
                error_log('FRS Webhook: Test event received');
                break;
                
            default:
                error_log('FRS Webhook: Unknown event type ' . $event);
                break;
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Webhook processed successfully',
            'event' => $event,
            'timestamp' => current_time('mysql'),
            'webhook_id' => $webhook_id
        ));
    }
    
    // Verify HMAC signature from FRS webhook
    private function verify_webhook_signature($payload, $signature, $secret) {
        // Remove 'sha256=' prefix if present
        $signature = str_replace('sha256=', '', $signature);
        
        $expected_signature = hash_hmac('sha256', $payload, $secret);
        
        return hash_equals($expected_signature, $signature);
    }
    
    // Handle agent created event
    private function handle_agent_created($agent_data) {
        if ($this->is_loan_officer($agent_data)) {
            // Fetch full agent data from API if needed
            $full_agent_data = $this->fetch_agent_by_id($agent_data['agent_id'] ?? $agent_data['id']);
            
            if ($full_agent_data) {
                $result = $this->sync_single_agent($full_agent_data);
                if ($result) {
                    error_log('FRS Webhook: Successfully created loan officer ' . ($agent_data['email'] ?? 'unknown'));
                } else {
                    error_log('FRS Webhook: Failed to create loan officer ' . ($agent_data['email'] ?? 'unknown'));
                }
            }
        }
    }
    
    // Handle agent updated event
    private function handle_agent_updated($agent_data) {
        if ($this->is_loan_officer($agent_data)) {
            // Fetch full agent data from API if needed
            $full_agent_data = $this->fetch_agent_by_id($agent_data['agent_id'] ?? $agent_data['id']);
            
            if ($full_agent_data) {
                $result = $this->sync_single_agent($full_agent_data);
                if ($result) {
                    error_log('FRS Webhook: Successfully updated loan officer ' . ($agent_data['email'] ?? 'unknown'));
                } else {
                    error_log('FRS Webhook: Failed to update loan officer ' . ($agent_data['email'] ?? 'unknown'));
                }
            }
        }
    }
    
    // Handle agent deleted event
    private function handle_agent_deleted($agent_data) {
        if ($this->is_loan_officer($agent_data)) {
            $this->soft_delete_agent($agent_data['agent_id'] ?? $agent_data['id'], $agent_data['email'] ?? '');
            error_log('FRS Webhook: Soft deleted loan officer ' . ($agent_data['email'] ?? 'unknown'));
        }
    }
    
    // Handle bulk import completed
    private function handle_bulk_import_completed($data) {
        error_log('FRS Webhook: Bulk import completed - triggering resync');
        // Trigger a full resync in the background
        wp_schedule_single_event(time() + 60, 'frs_resync_after_bulk');
    }
    
    // Handle bulk update completed
    private function handle_bulk_update_completed($data) {
        error_log('FRS Webhook: Bulk update completed - triggering resync');
        // Trigger a full resync in the background
        wp_schedule_single_event(time() + 60, 'frs_resync_after_bulk');
    }
    
    // Check if agent is a loan officer
    private function is_loan_officer($agent_data) {
        return isset($agent_data['role']) && $agent_data['role'] === 'loan_officer';
    }
    
    // Fetch full agent data from API
    private function fetch_agent_by_id($agent_id) {
        if (empty($agent_id)) {
            return false;
        }
        
        $api_base_url = get_option('frs_api_base_url');
        $api_token = get_option('frs_api_token');
        
        if (empty($api_base_url) || empty($api_token)) {
            return false;
        }
        
        $response = wp_remote_get($api_base_url . '/agents/' . $agent_id, array(
            'headers' => array(
                'X-API-Token' => $api_token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data['agent'] ?? false;
    }
    
    // Soft delete an agent
    private function soft_delete_agent($agent_id, $email) {
        // Find the person by FRS agent ID or email
        $person_query = new WP_Query(array(
            'post_type' => 'person',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_frs_agent_id',
                    'value' => $agent_id,
                    'compare' => '='
                ),
                array(
                    'key' => 'primary_business_email',
                    'value' => $email,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        ));
        
        if ($person_query->have_posts()) {
            $person_id = $person_query->posts[0]->ID;
            
            // Set post status to draft (soft delete)
            wp_update_post(array(
                'ID' => $person_id,
                'post_status' => 'draft'
            ));
            
            // Add deletion timestamp
            update_post_meta($person_id, '_frs_deleted_at', current_time('mysql'));
            
            error_log('FRS Webhook: Soft deleted person ID ' . $person_id);
        }
        
        wp_reset_postdata();
    }
    
    // Daily sync cron job
    public function daily_sync_cron() {
        if (get_option('frs_auto_sync') === '1') {
            $this->fetch_and_sync_loan_officers();
        }
    }
    
    // Add meta boxes to person edit screen
    public function add_person_meta_boxes() {
        add_meta_box(
            'frs-sync-info',
            'FRS Sync Information',
            array($this, 'person_sync_meta_box'),
            'person',
            'side',
            'default'
        );
    }
    
    // Display sync info meta box
    public function person_sync_meta_box($post) {
        $frs_agent_id = get_post_meta($post->ID, '_frs_agent_id', true);
        $frs_agent_uuid = get_post_meta($post->ID, '_frs_agent_uuid', true);
        $linked_user = get_field('account', $post->ID);
        
        echo '<table class="form-table">';
        echo '<tr><th>FRS Agent ID:</th><td>' . ($frs_agent_id ?: 'Not synced') . '</td></tr>';
        echo '<tr><th>FRS UUID:</th><td>' . ($frs_agent_uuid ?: 'Not synced') . '</td></tr>';
        echo '<tr><th>Linked User:</th><td>' . ($linked_user ? get_userdata($linked_user['ID'])->user_login : 'None') . '</td></tr>';
        echo '</table>';
        
        if ($frs_agent_id) {
            echo '<p><a href="' . get_option('frs_api_base_url', 'https://base.frs.works') . '" target="_blank" class="button">View in FRS</a></p>';
        }
    }
}

// Initialize the plugin
new FRS_API_Sync();

// Deactivation hook - clear scheduled events
register_deactivation_hook(__FILE__, 'frs_deactivate');
function frs_deactivate() {
    wp_clear_scheduled_hook('frs_daily_sync');
}
?>