/**
 * Deal Notification Engine - Frontend JavaScript
 * Handles notification preference forms and Telegram verification
 */

jQuery(document).ready(function($) {
    
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
        
        // Log OneSignal status if available
        if (typeof OneSignal !== 'undefined') {
            console.log('[DNE] OneSignal SDK detected');
            OneSignal.push(function() {
                OneSignal.isPushNotificationsEnabled(function(isEnabled) {
                    console.log('[DNE] OneSignal push enabled:', isEnabled);
                    if (isEnabled) {
                        OneSignal.getUserId(function(userId) {
                            console.log('[DNE] OneSignal Player ID:', userId);
                        });
                    }
                });
            });
        } else {
            console.log('[DNE] OneSignal SDK not found - is the OneSignal plugin active?');
        }
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