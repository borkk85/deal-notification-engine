<?php

namespace DNE\Integrations;

/**
 * OneSignal REST API v16 Integration
 * Handles subscription lifecycle management and cleanup
 * 
 * @since 1.2.0
 */
class OneSignal_API
{
    private $app_id;
    private $api_key;
    private $api_base_url = 'https://api.onesignal.com/';
    
    /**
     * Constructor
     */
    public function __construct()
    {
        // Get credentials from settings
        $this->app_id = get_option('dne_onesignal_app_id', '');
        $this->api_key = get_option('dne_onesignal_api_key', '');
        
        // Fallback to OneSignal plugin settings if not configured
        if (empty($this->app_id) || empty($this->api_key)) {
            $onesignal_settings = get_option('OneSignalWPSetting');
            if ($onesignal_settings && is_array($onesignal_settings)) {
                $this->app_id = $this->app_id ?: ($onesignal_settings['app_id'] ?? '');
                $this->api_key = $this->api_key ?: ($onesignal_settings['app_rest_api_key'] ?? '');
            }
        }
    }
    
    /**
     * Get user data by External ID
     * 
     * @param string|int $external_id WordPress user ID
     * @return array|false User data or false on error
     */
    public function get_user_by_external_id($external_id)
    {
        if (empty($this->app_id) || empty($this->api_key)) {
            $this->log_error('Missing OneSignal credentials');
            return false;
        }
        
        $external_id = (string) $external_id;
        
        // Use the Users endpoint with filter
        $response = $this->make_request(
            'apps/' . $this->app_id . '/users?filters[external_id]=' . urlencode($external_id),
            'GET'
        );
        
        if (!$response || !isset($response['users'])) {
            return false;
        }
        
        // Return first user if found
        return !empty($response['users']) ? $response['users'][0] : false;
    }
    
    /**
     * Get all subscriptions for an External ID
     * 
     * @param string|int $external_id WordPress user ID
     * @return array Array of subscriptions
     */
    public function get_subscriptions_by_external_id($external_id)
    {
        $user = $this->get_user_by_external_id($external_id);
        
        if (!$user || !isset($user['subscriptions'])) {
            return [];
        }
        
        return $user['subscriptions'];
    }
    
    /**
     * Cleanup disabled subscriptions for a user
     * This is critical for preventing "subscription poisoning"
     * 
     * @param string|int $external_id WordPress user ID
     * @return array Results ['deleted' => count, 'errors' => array]
     */
    public function cleanup_disabled_subscriptions($external_id)
    {
        $results = [
            'deleted' => 0,
            'errors' => [],
            'subscriptions_found' => 0
        ];
        
        $subscriptions = $this->get_subscriptions_by_external_id($external_id);
        $results['subscriptions_found'] = count($subscriptions);
        
        if (empty($subscriptions)) {
            $this->log_debug("No subscriptions found for External ID: {$external_id}");
            return $results;
        }
        
        foreach ($subscriptions as $subscription) {
            // Check if subscription is disabled
            // notification_types: 1 = subscribed, -2 = user opted out, -31 = API disabled
            $is_disabled = isset($subscription['notification_types']) && 
                          $subscription['notification_types'] <= 0;
            
            if ($is_disabled && isset($subscription['id'])) {
                $this->log_debug("Deleting disabled subscription: {$subscription['id']} for External ID: {$external_id}");
                
                // Delete the disabled subscription
                $deleted = $this->delete_subscription($subscription['id']);
                
                if ($deleted) {
                    $results['deleted']++;
                } else {
                    $results['errors'][] = "Failed to delete subscription: {$subscription['id']}";
                }
            }
        }
        
        $this->log_debug("Cleanup complete for External ID {$external_id}: {$results['deleted']} deleted, {$results['subscriptions_found']} found");
        
        return $results;
    }
    
    /**
     * Delete a subscription
     * 
     * @param string $subscription_id OneSignal subscription ID
     * @return bool Success
     */
    public function delete_subscription($subscription_id)
    {
        if (empty($subscription_id)) {
            return false;
        }
        
        $response = $this->make_request(
            'apps/' . $this->app_id . '/subscriptions/' . $subscription_id,
            'DELETE'
        );
        
        // DELETE returns empty response on success
        return $response !== false;
    }
    
    /**
     * Enable a subscription (set notification_types to 1)
     * 
     * @param string $subscription_id OneSignal subscription ID
     * @return bool Success
     */
    public function enable_subscription($subscription_id)
    {
        return $this->update_subscription($subscription_id, [
            'notification_types' => 1
        ]);
    }
    
    /**
     * Disable a subscription (set notification_types to -2 for user opt-out)
     * 
     * @param string $subscription_id OneSignal subscription ID
     * @return bool Success
     */
    public function disable_subscription($subscription_id)
    {
        return $this->update_subscription($subscription_id, [
            'notification_types' => -2  // User opted out (not -31 API disabled)
        ]);
    }
    
    /**
     * Update a subscription
     * 
     * @param string $subscription_id OneSignal subscription ID
     * @param array $data Update data
     * @return bool Success
     */
    public function update_subscription($subscription_id, $data)
    {
        if (empty($subscription_id)) {
            return false;
        }
        
        $response = $this->make_request(
            'apps/' . $this->app_id . '/subscriptions/' . $subscription_id,
            'PATCH',
            $data
        );
        
        return $response !== false;
    }
    
    /**
     * Set or update External ID alias for a subscription
     * 
     * @param string $subscription_id OneSignal subscription ID
     * @param string|int $external_id WordPress user ID
     * @return bool Success
     */
    public function set_external_id($subscription_id, $external_id)
    {
        if (empty($subscription_id) || empty($external_id)) {
            return false;
        }
        
        // Create/update alias
        $response = $this->make_request(
            'apps/' . $this->app_id . '/users/by/subscription_id/' . $subscription_id . '/identity',
            'PATCH',
            [
                'identity' => [
                    'external_id' => (string) $external_id
                ]
            ]
        );
        
        return $response !== false;
    }
    
    /**
     * Verify if a subscription is active and properly configured
     * 
     * @param string $subscription_id OneSignal subscription ID
     * @param string|int $expected_external_id Expected WordPress user ID
     * @return array ['is_valid' => bool, 'issues' => array]
     */
    public function verify_subscription($subscription_id, $expected_external_id = null)
    {
        $result = [
            'is_valid' => false,
            'issues' => [],
            'subscription' => null
        ];
        
        // Get subscription details
        $response = $this->make_request(
            'apps/' . $this->app_id . '/subscriptions/' . $subscription_id,
            'GET'
        );
        
        if (!$response) {
            $result['issues'][] = 'Subscription not found';
            return $result;
        }
        
        $result['subscription'] = $response;
        
        // Check if subscription is enabled
        if (!isset($response['notification_types']) || $response['notification_types'] <= 0) {
            $result['issues'][] = 'Subscription is disabled';
        }
        
        // Check External ID if provided
        if ($expected_external_id !== null) {
            $user = $this->get_user_by_external_id($expected_external_id);
            
            if (!$user) {
                $result['issues'][] = 'External ID not found';
            } else {
                // Check if this subscription belongs to the user
                $found = false;
                if (isset($user['subscriptions'])) {
                    foreach ($user['subscriptions'] as $sub) {
                        if ($sub['id'] === $subscription_id) {
                            $found = true;
                            break;
                        }
                    }
                }
                
                if (!$found) {
                    $result['issues'][] = 'Subscription does not belong to External ID';
                }
            }
        }
        
        $result['is_valid'] = empty($result['issues']);
        
        return $result;
    }
    
    /**
     * Make API request to OneSignal
     * 
     * @param string $endpoint API endpoint (without base URL)
     * @param string $method HTTP method
     * @param array $data Request body data
     * @return array|false Response data or false on error
     */
    private function make_request($endpoint, $method = 'GET', $data = null)
    {
        $url = $this->api_base_url . $endpoint;
        
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Basic ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ];
        
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = wp_json_encode($data);
        }
        
        $this->log_debug("API Request: {$method} {$endpoint}");
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $this->log_error('API request failed: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Log response for debugging
        $this->log_debug("API Response ({$code}): " . substr($body, 0, 500));
        
        // DELETE requests return empty body on success
        if ($method === 'DELETE' && $code === 204) {
            return true;
        }
        
        // Parse JSON response
        $data = json_decode($body, true);
        
        // Check for errors
        if ($code >= 400) {
            $error = isset($data['errors']) ? json_encode($data['errors']) : $body;
            $this->log_error("API error ({$code}): {$error}");
            return false;
        }
        
        return $data;
    }
    
    /**
     * Log debug message
     */
    private function log_debug($message)
    {
        if (get_option('dne_debug_mode') === '1') {
            error_log('[DNE OneSignal API] ' . $message);
        }
    }
    
    /**
     * Log error message
     */
    private function log_error($message)
    {
        error_log('[DNE OneSignal API ERROR] ' . $message);
    }
    
    /**
     * Check if API is properly configured
     * 
     * @return bool
     */
    public function is_configured()
    {
        return !empty($this->app_id) && !empty($this->api_key);
    }
    
    /**
     * Test API connection
     * 
     * @return array ['success' => bool, 'message' => string, 'app_name' => string]
     */
    public function test_connection()
    {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => 'OneSignal API not configured'
            ];
        }
        
        // Get app details
        $response = $this->make_request('apps/' . $this->app_id, 'GET');
        
        if (!$response) {
            return [
                'success' => false,
                'message' => 'Failed to connect to OneSignal API'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Connected successfully',
            'app_name' => $response['name'] ?? 'Unknown',
            'players' => $response['players'] ?? 0
        ];
    }
    
    /**
     * Cleanup subscriptions before resubscription
     * This is the critical method to prevent subscription poisoning
     * 
     * @param int $user_id WordPress user ID
     * @return bool Success
     */
    public function prepare_for_resubscription($user_id)
    {
        $this->log_debug("Preparing resubscription for user {$user_id}");
        
        // Cleanup any disabled subscriptions
        $cleanup_result = $this->cleanup_disabled_subscriptions($user_id);
        
        if ($cleanup_result['deleted'] > 0) {
            $this->log_debug("Cleaned up {$cleanup_result['deleted']} disabled subscriptions for user {$user_id}");
        }
        
        return true;
    }
}