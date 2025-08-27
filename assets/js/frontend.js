/**
 * Deal Notification Engine - Frontend JavaScript
 * Handles OneSignal integration and other frontend functionality
 */

jQuery(document).ready(function($) {
    
    // Initialize OneSignal if configured
    if (typeof OneSignal !== 'undefined') {
        OneSignal.push(function() {
            // Wait for OneSignal to be ready
            OneSignal.on('subscriptionChange', function(isSubscribed) {
                if (isSubscribed) {
                    // User has subscribed, get their player ID
                    OneSignal.getUserId(function(playerId) {
                        if (playerId) {
                            // Save player ID to user meta via AJAX
                            $.post(dne_ajax.ajax_url, {
                                action: 'dne_save_onesignal_player_id',
                                player_id: playerId,
                                nonce: dne_ajax.nonce
                            }, function(response) {
                                if (response.success) {
                                    console.log('[DNE] OneSignal player ID saved');
                                } else {
                                    console.error('[DNE] Failed to save OneSignal player ID:', response.data);
                                }
                            });
                        }
                    });
                }
            });
            
            // Check if user is already subscribed
            OneSignal.isPushNotificationsEnabled(function(isEnabled) {
                if (isEnabled) {
                    OneSignal.getUserId(function(playerId) {
                        if (playerId) {
                            // User is already subscribed, ensure their player ID is saved
                            $.post(dne_ajax.ajax_url, {
                                action: 'dne_save_onesignal_player_id',
                                player_id: playerId,
                                nonce: dne_ajax.nonce
                            });
                        }
                    });
                }
            });
        });
    }
    
    // Handle notification preference form if present
    $('#dne-preferences-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $button = $form.find('button[type="submit"]');
        var originalText = $button.text();
        
        $button.prop('disabled', true).text('Saving...');
        
        $.post(dne_ajax.ajax_url, $form.serialize() + '&action=save_deal_notification_preferences&nonce=' + dne_ajax.nonce, function(response) {
            if (response.success) {
                // Show success message
                showNotice('success', response.data || 'Preferences saved successfully');
            } else {
                // Show error message
                showNotice('error', response.data || 'Failed to save preferences');
            }
        }).always(function() {
            $button.prop('disabled', false).text(originalText);
        });
    });
    
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
        $('#dne-preferences-form').before($notice);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    // Debug mode indicator
    if (window.location.href.indexOf('dne_debug=1') !== -1) {
        console.log('[DNE] Debug mode active - Check console for detailed logs');
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
    `;
    document.head.appendChild(style);
})();