/**
 * Deal Notification Engine - Frontend JavaScript
 * Handles notification preference forms, Telegram verification, and OneSignal integration
 * FIXED: Proper OneSignal initialization timing
 */

jQuery(document).ready(function($) {
    
    // Handle notification preference save button (no form element in theme)
    $('#save-notification-preferences').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        var webPushChecked = $('#delivery_webpush').is(':checked');
        
        // Check if we need to handle OneSignal subscription
        if (webPushChecked) {
            // Handle OneSignal subscription with save
            handleSaveWithOneSignal($button, originalText);
        }
    });
    
    // Handle save with OneSignal - UPDATED to wait for proper initialization
    async function handleSaveWithOneSignal($button, originalText) {
        
        // Wait a moment for the original save to complete
        setTimeout(async function() {
            
            // Use the proper OneSignal initialization method
            if (window.OneSignalDeferred) {
                window.OneSignalDeferred.push(async function(OneSignal) {
                    try {
                        if (typeof dne_onesignal !== 'undefined' && dne_onesignal.debug_mode === '1') {
                            console.log('[DNE] OneSignal ready, checking subscription...');
                        }
                        
                        const isSubscribed = await OneSignal.User.PushSubscription.optedIn;
                        
                        if (!isSubscribed) {
                            // Prompt for subscription
                            try {
                                await OneSignal.Slidedown.promptPush();
                                const nowSubscribed = await OneSignal.User.PushSubscription.optedIn;
                                
                                if (nowSubscribed) {
                                    await setOneSignalExternalId();
                                    showNotice('success', 'Push notifications enabled!');
                                } else {
                                    showNotice('warning', 'Push notifications require permission. Enable them in your browser.');
                                }
                            } catch (error) {
                                console.error('[DNE] OneSignal prompt error:', error);
                                showNotice('warning', 'Enable notifications in your browser to receive push alerts.');
                            }
                        } else {
                            // Already subscribed, just ensure External ID is set
                            await setOneSignalExternalId();
                        }
                    } catch (error) {
                        console.error('[DNE] Error in OneSignal handling:', error);
                    }
                });
            }
        }, 1000);
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
                // Reload to show connected status
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
                // Reload to show disconnected status
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
        
        // Remove any existing notices
        $('.dne-notice').remove();
        
        // Add new notice
        $('.deal-notification-fields').before($notice);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    // OneSignal Integration - COMPLETELY REWRITTEN for proper initialization
    if (typeof dne_onesignal !== 'undefined' && dne_onesignal.user_id && dne_onesignal.user_id !== '0') {
        initializeOneSignal();
    }
    
    /**
     * Initialize OneSignal integration - UPDATED to use proper OneSignal patterns
     */
    function initializeOneSignal() {
        var userId = dne_onesignal.user_id;
        var isDebug = dne_onesignal.debug_mode === '1';
        
        if (isDebug) console.log('[DNE] Initializing OneSignal for user:', userId);
        
        // PROPER OneSignal initialization - wait for the SDK to be fully ready
        window.OneSignalDeferred = window.OneSignalDeferred || [];
        
        OneSignalDeferred.push(async function(OneSignal) {
            try {
                if (isDebug) console.log('[DNE] OneSignal SDK Fully Loaded and Ready');
                
                // Wait a bit more to ensure everything is initialized
                await new Promise(resolve => setTimeout(resolve, 1000));
                
                // Now check current subscription status
                const isSubscribed = await OneSignal.User.PushSubscription.optedIn;
                const playerId = await OneSignal.User.PushSubscription.id;
                const externalId = await OneSignal.User.externalId;
                
                if (isDebug) {
                    console.log('[DNE] OneSignal Status Check:');
                    console.log('  - Subscription status:', isSubscribed);
                    console.log('  - Player ID:', playerId);
                    console.log('  - External ID:', externalId);
                }
                
                // If already subscribed, set External ID
                if (isSubscribed && playerId) {
                    await setOneSignalExternalId();
                    
                    // Update UI if push notification checkbox exists
                    updatePushStatusIndicator(true);
                } else {
                    updatePushStatusIndicator(false);
                }
                
                // Listen for subscription changes
                OneSignal.User.PushSubscription.addEventListener('change', async function(event) {
                    if (isDebug) console.log('[DNE] OneSignal subscription changed:', event);
                    
                    if (event.current.optedIn === true && event.current.id) {
                        // User just subscribed
                        if (isDebug) console.log('[DNE] User subscribed, setting External ID');
                        
                        await setOneSignalExternalId();
                        updatePushStatusIndicator(true);
                        
                        // Track subscription in WordPress
                        $.post(dne_ajax.ajax_url, {
                            action: 'dne_onesignal_subscribed',
                            user_id: userId,
                            player_id: event.current.id,
                            nonce: dne_ajax.nonce
                        });
                        
                        showNotice('success', 'Push notifications enabled successfully!');
                    } else if (event.current.optedIn === false) {
                        if (isDebug) console.log('[DNE] User unsubscribed');
                        updatePushStatusIndicator(false);
                    }
                });
                
            } catch (error) {
                if (isDebug) console.error('[DNE] Error in OneSignal initialization:', error);
            }
        });
        
        // Fallback check - if OneSignal doesn't load within 10 seconds, show warning
        setTimeout(function() {
            if (typeof OneSignal === 'undefined') {
                console.warn('[DNE] OneSignal SDK failed to load after 10 seconds');
            }
        }, 10000);
    }
    
    /**
     * Set External User ID in OneSignal - UPDATED for new SDK
     */
    async function setOneSignalExternalId() {
        if (typeof dne_onesignal === 'undefined' || !dne_onesignal.user_id) {
            return false;
        }
        
        var userId = dne_onesignal.user_id;
        var isDebug = dne_onesignal.debug_mode === '1';
        
        try {
            if (isDebug) console.log('[DNE] Setting OneSignal External ID:', userId);
            
            // Set the external user ID using OneSignal's login method
            await OneSignal.login(String(userId));
            
            // Also set tags for additional targeting options
            await OneSignal.User.addTags({
                wordpress_user_id: String(userId),
                wordpress_user: 'true'
            });
            
            if (isDebug) console.log('[DNE] OneSignal External ID and tags set successfully');
            
            return true;
        } catch (error) {
            if (isDebug) console.error('[DNE] Failed to set OneSignal External ID:', error);
            return false;
        }
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
            indicator.html(' <span style="color: orange;">⚠ Click Save to enable</span>');
        }
        
        webPushLabel.find('.push-status-indicator').remove();
        webPushLabel.append(indicator);
    }
    
    // Debug mode checks - IMPROVED
    if (window.location.href.indexOf('dne_debug=1') !== -1 || (typeof dne_onesignal !== 'undefined' && dne_onesignal.debug_mode === '1')) {
        console.log('[DNE] Debug mode active - Check console for detailed logs');
        
        // Enhanced OneSignal debugging
        setTimeout(function() {
            if (typeof OneSignal !== 'undefined') {
                console.log('[DNE] OneSignal SDK detected');
                
                // Use OneSignalDeferred for proper debugging
                window.OneSignalDeferred = window.OneSignalDeferred || [];
                OneSignalDeferred.push(async function(OneSignal) {
                    try {
                        const isSubscribed = await OneSignal.User.PushSubscription.optedIn;
                        const playerId = await OneSignal.User.PushSubscription.id;
                        const externalId = await OneSignal.User.externalId;
                        
                        console.log('[DNE Debug] OneSignal Status:');
                        console.log('  - Subscribed:', isSubscribed);
                        console.log('  - Player ID:', playerId);
                        console.log('  - External ID:', externalId);
                    } catch (error) {
                        console.log('[DNE Debug] Error getting OneSignal status:', error);
                    }
                });
            } else {
                console.log('[DNE] OneSignal SDK not found - is the OneSignal plugin active?');
            }
        }, 2000); // Wait 2 seconds for SDK to load
    }
});

// Add some basic styles for notices
(function() {
    var style = document.createElement('style');
    style.innerHTML = `
        .dne-notice {
            padding: 12px;
            margin: 10px 0;
            border-radius: 4px;
            font-weight: bold;
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
        .push-status-indicator {
            font-size: 12px;
            margin-left: 10px;
        }
    `;
    document.head.appendChild(style);
})();