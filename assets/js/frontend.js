/**
 * Deal Notification Engine - Frontend JavaScript
 * SDK v16 Compatible - Fixed subscription handling
 */

jQuery(document).ready(function($) {
    
    // Track previous delivery methods to detect changes
    var previousDeliveryMethods = [];
    
    // Store initial state on page load
    function captureInitialState() {
        previousDeliveryMethods = [];
        $('input[name="notification_delivery_methods[]"]:checked').each(function() {
            previousDeliveryMethods.push($(this).val());
        });
    }
    
    // Capture initial state
    captureInitialState();
    
    // Handle notification preference save button
    $('#save-notification-preferences').on('click', async function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        var webPushChecked = $('#delivery_webpush').is(':checked');
        var webPushWasChecked = previousDeliveryMethods.includes('webpush');
        
        // Check if user is trying to enable web push
        if (webPushChecked && !webPushWasChecked) {
            // Verify browser support first
            if (!('Notification' in window) || !('serviceWorker' in navigator)) {
                showNotice('error', 'Push notifications are not supported in your browser. Please use a modern desktop browser (Chrome, Firefox, Edge, or Safari).');
                $('#delivery_webpush').prop('checked', false);
                return false;
            }
            
            // Check if notifications are permanently blocked
            if (Notification.permission === 'denied') {
                showNotice('error', 'Push notifications are blocked in your browser. Please enable them in your browser settings and refresh the page.');
                $('#delivery_webpush').prop('checked', false);
                return false;
            }
            
            // Try to subscribe to OneSignal
            $button.text('Setting up push notifications...').prop('disabled', true);
            
            try {
                const subscriptionResult = await handleOneSignalSubscription();
                
                if (!subscriptionResult.success) {
                    // Failed to subscribe
                    showNotice('error', subscriptionResult.message || 'Failed to enable push notifications. Please try again.');
                    $('#delivery_webpush').prop('checked', false);
                    $button.text(originalText).prop('disabled', false);
                    return false;
                }
                
                // Success - continue with save
                showNotice('success', 'Push notifications enabled successfully!');
                
            } catch (error) {
                console.error('[DNE] Error enabling push notifications:', error);
                showNotice('error', 'Failed to enable push notifications. Please try again.');
                $('#delivery_webpush').prop('checked', false);
                $button.text(originalText).prop('disabled', false);
                return false;
            }
        }
        
        // Check if user is disabling web push
        if (!webPushChecked && webPushWasChecked) {
            // Properly unsubscribe from OneSignal
            $button.text('Disabling push notifications...').prop('disabled', true);
            
            try {
                await handleOneSignalUnsubscription();
                showNotice('info', 'Push notifications disabled.');
            } catch (error) {
                console.error('[DNE] Error disabling push notifications:', error);
                // Continue with save even if unsubscription fails
            }
        }
        
        // Now proceed with the actual save
        $button.text('Saving...').prop('disabled', true);
        
        // Gather form data
        var formData = {
            action: 'save_deal_notification_preferences',
            nonce: dne_ajax.nonce,
            user_id: $button.data('user-id') || dne_ajax.user_id,
            notifications_enabled: $('#notifications_enabled').is(':checked') ? '1' : '0',
            delivery_methods: [],
            user_discount_filter: $('#user_discount_filter').val(),
            user_category_filter: [],
            user_store_filter: []
        };
        
        // Get delivery methods
        $('input[name="notification_delivery_methods[]"]:checked').each(function() {
            formData.delivery_methods.push($(this).val());
        });
        
        // Get category filters
        $('input[name="user_category_filter[]"]:checked').each(function() {
            formData.user_category_filter.push($(this).val());
        });
        
        // Get store filters
        $('input[name="user_store_filter[]"]:checked').each(function() {
            formData.user_store_filter.push($(this).val());
        });
        
        // Save preferences
        $.post(dne_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                showNotice('success', 'Preferences saved successfully!');
                // Update previous state
                captureInitialState();
            } else {
                showNotice('error', response.data || 'Failed to save preferences');
            }
        }).fail(function() {
            showNotice('error', 'Network error. Please try again.');
        }).always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
    
    /**
     * Handle OneSignal subscription with SDK v16 methods
     */
    async function handleOneSignalSubscription() {
        return new Promise((resolve) => {
            if (typeof window.OneSignalDeferred === 'undefined') {
                resolve({ success: false, message: 'OneSignal is not loaded. Please ensure the OneSignal plugin is active.' });
                return;
            }
            
            window.OneSignalDeferred.push(async function(OneSignal) {
                try {
                    const isDebug = dne_ajax.debug_mode === '1';
                    
                    if (isDebug) console.log('[DNE] Starting OneSignal subscription process...');
                    
                    // First, clear any existing External ID to start fresh
                    try {
                        await OneSignal.logout();
                        if (isDebug) console.log('[DNE] Cleared any existing External ID');
                    } catch (e) {
                        // Ignore logout errors
                    }
                    
                    // Check browser permission state
                    const browserPermission = Notification.permission;
                    if (isDebug) console.log('[DNE] Browser permission:', browserPermission);
                    
                    // Check current subscription status
                    let isSubscribed = await OneSignal.User.PushSubscription.optedIn;
                    
                    if (browserPermission === 'granted' && !isSubscribed) {
                        // Permission already granted but not subscribed - use optIn()
                        if (isDebug) console.log('[DNE] Permission granted but not subscribed, using optIn()');
                        await OneSignal.User.PushSubscription.optIn();
                        
                        // Wait for subscription to complete
                        await new Promise(r => setTimeout(r, 1000));
                        
                        // Verify subscription
                        isSubscribed = await OneSignal.User.PushSubscription.optedIn;
                        
                    } else if (browserPermission === 'default') {
                        // Need to prompt for permission
                        if (isDebug) console.log('[DNE] Permission not granted, showing prompt');
                        
                        await OneSignal.Slidedown.promptPush();
                        
                        // Wait for user response
                        await new Promise(r => setTimeout(r, 1000));
                        
                        // Check if user subscribed
                        isSubscribed = await OneSignal.User.PushSubscription.optedIn;
                        
                        if (!isSubscribed) {
                            if (Notification.permission === 'denied') {
                                resolve({ 
                                    success: false, 
                                    message: 'You have blocked notifications. Please enable them in your browser settings.' 
                                });
                                return;
                            }
                            resolve({ 
                                success: false, 
                                message: 'Push notifications were not enabled. Please accept the notification prompt.' 
                            });
                            return;
                        }
                    } else if (browserPermission === 'denied') {
                        resolve({ 
                            success: false, 
                            message: 'Push notifications are blocked in your browser. Please enable them in browser settings.' 
                        });
                        return;
                    }
                    
                    // If already subscribed or just subscribed, set External ID
                    if (isSubscribed) {
                        // Get player ID
                        let playerId = await OneSignal.User.PushSubscription.id;
                        let attempts = 0;
                        
                        while (!playerId && attempts < 10) {
                            await new Promise(r => setTimeout(r, 500));
                            playerId = await OneSignal.User.PushSubscription.id;
                            attempts++;
                        }
                        
                        if (!playerId) {
                            if (isDebug) console.log('[DNE] No player ID available');
                            resolve({ 
                                success: false, 
                                message: 'Unable to complete push notification setup. Please try again.' 
                            });
                            return;
                        }
                        
                        if (isDebug) console.log('[DNE] User subscribed with player ID:', playerId);
                        
                        // Set External ID
                        const userId = dne_ajax.user_id;
                        await OneSignal.login(String(userId));
                        
                        // Set tags
                        await OneSignal.User.addTags({
                            wordpress_user_id: String(userId),
                            wordpress_user: 'true',
                            subscription_date: new Date().toISOString()
                        });
                        
                        if (isDebug) console.log('[DNE] External ID and tags set successfully');
                        
                        // Track in WordPress
                        $.post(dne_ajax.ajax_url, {
                            action: 'dne_onesignal_subscribed',
                            user_id: userId,
                            player_id: playerId,
                            nonce: dne_ajax.nonce
                        });
                        
                        updatePushStatusIndicator(true);
                        resolve({ success: true });
                    } else {
                        resolve({ 
                            success: false, 
                            message: 'Unable to enable push notifications.' 
                        });
                    }
                    
                } catch (error) {
                    console.error('[DNE] Error in subscription process:', error);
                    resolve({ 
                        success: false, 
                        message: 'An error occurred while setting up push notifications. Please try again.' 
                    });
                }
            });
        });
    }
    
    /**
     * Handle OneSignal unsubscription with SDK v16 methods
     */
    async function handleOneSignalUnsubscription() {
        return new Promise((resolve) => {
            if (typeof window.OneSignalDeferred === 'undefined') {
                resolve(false);
                return;
            }
            
            window.OneSignalDeferred.push(async function(OneSignal) {
                try {
                    const isDebug = dne_ajax.debug_mode === '1';
                    
                    if (isDebug) console.log('[DNE] Starting OneSignal unsubscription...');
                    
                    // First opt out from push
                    await OneSignal.User.PushSubscription.optOut();
                    if (isDebug) console.log('[DNE] Opted out from push notifications');
                    
                    // Then remove External ID
                    await OneSignal.logout();
                    if (isDebug) console.log('[DNE] Removed External ID');
                    
                    // Update UI
                    updatePushStatusIndicator(false);
                    
                    // Track in WordPress
                    $.post(dne_ajax.ajax_url, {
                        action: 'dne_onesignal_unsubscribed',
                        user_id: dne_ajax.user_id,
                        nonce: dne_ajax.nonce
                    });
                    
                    resolve(true);
                    
                } catch (error) {
                    console.error('[DNE] Error in unsubscription:', error);
                    resolve(false);
                }
            });
        });
    }
    
    // Handle Telegram verification
    $('#dne-telegram-verify').on('click', function() {
        var code = $('#telegram-verification-code').val();
        var userId = $(this).data('user-id');
        
        if (!code) {
            showNotice('error', 'Please enter a verification code');
            return;
        }
        
        var $button = $(this);
        var originalText = $button.text();
        $button.prop('disabled', true).text('Verifying...');
        
        $.post(dne_ajax.ajax_url, {
            action: 'verify_telegram_connection',
            verification_code: code,
            user_id: userId,
            nonce: dne_ajax.nonce
        }, function(response) {
            if (response.success) {
                showNotice('success', response.data);
                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            } else {
                showNotice('error', response.data);
            }
        }).always(function() {
            $button.prop('disabled', false).text(originalText);
        });
    });
    
    // Handle Telegram disconnection
    $('#dne-telegram-disconnect').on('click', function() {
        if (!confirm('Are you sure you want to disconnect Telegram notifications?')) {
            return;
        }
        
        var userId = $(this).data('user-id');
        var $button = $(this);
        var originalText = $button.text();
        
        $button.prop('disabled', true).text('Disconnecting...');
        
        $.post(dne_ajax.ajax_url, {
            action: 'disconnect_telegram',
            user_id: userId,
            nonce: dne_ajax.nonce
        }, function(response) {
            if (response.success) {
                showNotice('success', response.data);
                setTimeout(function() {
                    window.location.reload();
                }, 1500);
            } else {
                showNotice('error', response.data);
            }
        }).always(function() {
            $button.prop('disabled', false).text(originalText);
        });
    });
    
    /**
     * Show notice message
     */
    function showNotice(type, message) {
        var $notice = $('<div class="dne-notice dne-notice-' + type + '">' + message + '</div>');
        
        $('.dne-notice').remove();
        $('.deal-notification-fields').before($notice);
        
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    /**
     * Update visual indicator for push subscription status
     */
    function updatePushStatusIndicator(isSubscribed) {
        var indicator = $('<span class="push-status-indicator"></span>');
        var webPushLabel = $('#delivery_webpush').parent().parent();
        
        if (isSubscribed) {
            indicator.html(' <span style="color: green;">✓ Connected</span>');
        } else {
            indicator.html(' <span style="color: orange;">⚠ Not connected</span>');
        }
        
        webPushLabel.find('.push-status-indicator').remove();
        webPushLabel.append(indicator);
    }
    
    // Initialize OneSignal status check on page load
    if (typeof dne_ajax !== 'undefined' && dne_ajax.user_id && dne_ajax.user_id !== '0') {
        // Check if user has webpush in their saved preferences
        var hasWebPush = previousDeliveryMethods.includes('webpush') || $('#delivery_webpush').is(':checked');
        
        if (hasWebPush) {
            checkOneSignalStatus();
        } else {
            updatePushStatusIndicator(false);
        }
    }
    
    /**
     * Check OneSignal status on page load
     */
    function checkOneSignalStatus() {
        var isDebug = dne_ajax.debug_mode === '1';
        
        if (isDebug) console.log('[DNE] Checking OneSignal status for user:', dne_ajax.user_id);
        
        if (typeof window.OneSignalDeferred === 'undefined') {
            if (isDebug) console.log('[DNE] OneSignal SDK not loaded');
            updatePushStatusIndicator(false);
            return;
        }
        
        window.OneSignalDeferred.push(async function(OneSignal) {
            try {
                await new Promise(resolve => setTimeout(resolve, 1000));
                
                const isSubscribed = await OneSignal.User.PushSubscription.optedIn;
                const playerId = await OneSignal.User.PushSubscription.id;
                const externalId = await OneSignal.User.externalId;
                
                if (isDebug) {
                    console.log('[DNE] Current OneSignal Status:');
                    console.log('  - Subscribed:', isSubscribed);
                    console.log('  - Player ID:', playerId);
                    console.log('  - External ID:', externalId);
                }
                
                // Update UI based on status
                if (isSubscribed && externalId === String(dne_ajax.user_id)) {
                    updatePushStatusIndicator(true);
                } else if (playerId && !isSubscribed && Notification.permission === 'granted') {
                    // Zombie state - has player ID but not subscribed with permission granted
                    if (isDebug) console.log('[DNE] Detected zombie state, attempting to fix...');
                    
                    // Try to fix by opting in
                    try {
                        await OneSignal.User.PushSubscription.optIn();
                        await new Promise(r => setTimeout(r, 1000));
                        
                        // Set External ID
                        await OneSignal.login(String(dne_ajax.user_id));
                        
                        updatePushStatusIndicator(true);
                        
                        // Track in WordPress
                        $.post(dne_ajax.ajax_url, {
                            action: 'dne_onesignal_subscribed',
                            user_id: dne_ajax.user_id,
                            player_id: playerId,
                            nonce: dne_ajax.nonce
                        });
                        
                        if (isDebug) console.log('[DNE] Fixed zombie state');
                    } catch (e) {
                        if (isDebug) console.log('[DNE] Failed to fix zombie state:', e);
                        updatePushStatusIndicator(false);
                    }
                } else {
                    updatePushStatusIndicator(false);
                }
                
            } catch (error) {
                if (isDebug) console.error('[DNE] Error checking OneSignal status:', error);
                updatePushStatusIndicator(false);
            }
        });
    }
    
    // Browser compatibility check on page load
    $(window).on('load', function() {
        if ($('#delivery_webpush').length > 0) {
            if (!('Notification' in window) || !('serviceWorker' in navigator)) {
                $('#delivery_webpush').prop('disabled', true);
                $('#delivery_webpush').parent().parent().append(
                    '<span class="browser-warning" style="color: red; display: block; margin-top: 5px;">' +
                    'Push notifications are not supported in your browser</span>'
                );
            } else if (Notification.permission === 'denied') {
                $('#delivery_webpush').parent().parent().append(
                    '<span class="browser-warning" style="color: orange; display: block; margin-top: 5px;">' +
                    'Push notifications are blocked. Enable them in browser settings.</span>'
                );
            }
        }
    });
});

// Add styles for notices and indicators
(function() {
    var style = document.createElement('style');
    style.innerHTML = `
        .dne-notice {
            padding: 12px;
            margin: 10px 0;
            border-radius: 4px;
            font-weight: bold;
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .dne-notice-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .dne-notice-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .dne-notice-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .dne-notice-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .push-status-indicator {
            font-size: 12px;
            margin-left: 10px;
            font-weight: normal;
        }
        .browser-warning {
            font-size: 12px;
            font-style: italic;
        }
    `;
    document.head.appendChild(style);
})();