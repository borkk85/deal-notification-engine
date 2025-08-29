/**
 * Deal Notification Engine - Frontend JavaScript
 * Handles notification preference forms, Telegram verification, and OneSignal integration
 */

jQuery(document).ready(function ($) {
  //   if (window.OneSignal) {
  //     if (typeof OneSignal !== "undefined") {
  //       OneSignal.push(function () {
  //         OneSignal.on("subscriptionChange", function (isSubscribed) {
  //           if (isSubscribed) {
  //             OneSignal.getUserId().then(function (playerId) {
  //               jQuery.post(dne_ajax.ajax_url, {
  //                 action: "save_onesignal_player_id",
  //                 player_id: playerId,
  //                 user_id: dne_ajax.current_user_id,
  //                 nonce: dne_ajax.save_player_nonce,
  //               });
  //             });
  //           }
  //         });
  //       });
  //     }
  //   }
  // Handle notification preference save button (no form element in theme)
  //   $("#save-notification-preferences").on("click", function (e) {
  //     e.preventDefault();

  //     var $button = $(this);
  //     var originalText = $button.text();
  //     var webPushChecked = $("#delivery_webpush").is(":checked");

  //     // Check if we need to handle OneSignal subscription
  //     if (webPushChecked && typeof OneSignal !== "undefined") {
  //       // Handle OneSignal subscription with save
  //       handleSaveWithOneSignal($button, originalText);
  //     }
  //   });

  $("#save-notification-preferences").on("click", function (e) {
  e.preventDefault();

  const wantsWebPush = $("#delivery_webpush").is(":checked");

  if (wantsWebPush) {
    // Use whichever queue exists on the page
    const queue = (window.OneSignalDeferred || window.OneSignal);
    if (queue && typeof queue.push === "function") {
      queue.push(async function (OneSignal) {
        try {
          // If v16 API surface isn't ready, this will be undefined until the SDK fully boots.
          if (!OneSignal || !OneSignal.User || !OneSignal.User.PushSubscription) {
            console.warn("[DNE] OneSignal v16 API not ready. Check service worker and site URL.");
            // As a fallback, try showing Slidedown if available
            if (OneSignal && OneSignal.Slidedown && OneSignal.Slidedown.promptPush) {
              await OneSignal.Slidedown.promptPush();
            }
            return;
          }

          console.log(
            "[DNE] Before enable:",
            "perm=", (window.Notification && Notification.permission),
            "optedIn=", await OneSignal.User.PushSubscription.optedIn
          );

          // This triggers the native permission prompt when needed.
          await OneSignal.User.Push.enable();

          if (await OneSignal.User.PushSubscription.optedIn) {
            const playerId = await OneSignal.User.PushSubscription.id;
            if (playerId) {
              jQuery.post(dne_ajax.ajax_url, {
                action: "save_onesignal_player_id",
                player_id: playerId,
                user_id: (dne_ajax.current_user_id || dne_ajax.user_id || 0),
                nonce: dne_ajax.save_player_nonce
              });
              console.log("[DNE] Saved OneSignal Player ID:", playerId);
            }
          } else {
            console.log("[DNE] User did not opt in after enable()");
          }
        } catch (err) {
          console.log("[DNE] enable() error:", err);
        }
      });
    }
  }
});

//   $(document).ajaxSuccess(function (event, xhr, settings) {
//     var data = settings.data || "";
//     if (typeof data !== "string") {
//       data = $.param(data);
//     }

//     if (data.indexOf("action=save_deal_notification_preferences") !== -1) {
//       var deliveryMethods = $('input[name="delivery_methods[]"]:checked')
//         .map(function () {
//           return this.value;
//         })
//         .get();

//       var response = xhr.responseJSON || {};

//       if (
//         window.OneSignal &&
//         response.success &&
//         deliveryMethods.includes("webpush")
//       ) {
//         OneSignal.push(function () {
//           // First show the permission prompt
//           OneSignal.Slidedown.promptPush().then(async () => {
//             // If granted, enable and get Player ID
//             const isEnabled = await OneSignal.User.PushSubscription.optedIn;
//             if (isEnabled) {
//               const playerId = await OneSignal.User.PushSubscription.id;
//               if (playerId) {
//                 // Save playerId to WP via AJAX
//                 jQuery.post(dne_ajax.ajax_url, {
//                   action: "save_onesignal_player_id",
//                   player_id: playerId,
//                   user_id: dne_ajax.current_user_id,
//                   nonce: dne_ajax.save_player_nonce,
//                 });
//               }
//             }
//           });
//         });
//       }
//     }
//   });

  // Handle save with OneSignal
//   async function handleSaveWithOneSignal($button, originalText) {
//     // The theme's save button already collects and sends the data
//     // We just need to hook into it for OneSignal
//     // Let the original save happen first, then prompt for OneSignal

//     // Wait a moment for the original save to complete
//     setTimeout(async function () {
//       if (window.OneSignalDeferred) {
//         window.OneSignalDeferred.push(async function (OneSignal) {
//           const isSubscribed = await OneSignal.User.PushSubscription.optedIn;

//           if (!isSubscribed) {
//             // Prompt for subscription
//             try {
//               await OneSignal.Slidedown.promptPush();
//               const nowSubscribed = await OneSignal.User.PushSubscription
//                 .optedIn;

//               if (nowSubscribed) {
//                 await setOneSignalExternalId();
//                 showNotice("success", "Push notifications enabled!");
//               } else {
//                 showNotice(
//                   "warning",
//                   "Push notifications require permission. Enable them in your browser."
//                 );
//               }
//             } catch (error) {
//               console.error("[DNE] OneSignal prompt error:", error);
//               showNotice(
//                 "warning",
//                 "Enable notifications in your browser to receive push alerts."
//               );
//             }
//           } else {
//             // Already subscribed, just ensure External ID is set
//             await setOneSignalExternalId();
//           }
//         });
//       }
//     }, 1000);
//   }

  $(document).ajaxSuccess(function (event, xhr, settings) {
    if (
      settings.data &&
      settings.data.indexOf("action=save_deal_notification_preferences") !== -1
    ) {
      var response = xhr.responseJSON;
      if (!response) {
        try {
          response = JSON.parse(xhr.responseText);
        } catch (e) {
          response = null;
        }
      }
      if (!response || !response.success) {
        return;
      }

      var deliveryMethods = $('input[name="delivery_methods[]"]:checked')
        .map(function () {
          return $(this).val();
        })
        .get();

      if (deliveryMethods.indexOf("webpush") === -1) {
        if (typeof OneSignal !== "undefined") {
          OneSignal.push(function () {
            OneSignal.User.Push.disable();
          });
        }
        if (typeof dne_onesignal !== "undefined" && dne_onesignal.user_id) {
          $.post(dne_ajax.ajax_url, {
            action: "remove_onesignal_player_id",
            user_id: dne_onesignal.user_id,
            nonce: dne_ajax.remove_player_id_nonce,
          });
        }
      }
    }
  });

  // Handle Telegram verification
  $("#dne-telegram-verify").on("click", function () {
    var code = $("#telegram-verification-code").val();
    var userId = $(this).data("user-id");

    if (!code) {
      showNotice("error", "Please enter a verification code");
      return;
    }

    var $button = $(this);
    var originalText = $button.text();
    $button.prop("disabled", true).text("Verifying...");

    $.post(
      dne_ajax.ajax_url,
      {
        action: "verify_telegram_connection",
        verification_code: code,
        user_id: userId,
        nonce: dne_ajax.nonce,
      },
      function (response) {
        if (response.success) {
          showNotice("success", response.data);
          // Reload to show connected status
          setTimeout(function () {
            window.location.reload();
          }, 1500);
        } else {
          showNotice("error", response.data);
        }
      }
    ).always(function () {
      $button.prop("disabled", false).text(originalText);
    });
  });

  // Handle Telegram disconnection
  $("#dne-telegram-disconnect").on("click", function () {
    if (
      !confirm("Are you sure you want to disconnect Telegram notifications?")
    ) {
      return;
    }

    var userId = $(this).data("user-id");
    var $button = $(this);
    var originalText = $button.text();

    $button.prop("disabled", true).text("Disconnecting...");

    $.post(
      dne_ajax.ajax_url,
      {
        action: "disconnect_telegram",
        user_id: userId,
        nonce: dne_ajax.nonce,
      },
      function (response) {
        if (response.success) {
          showNotice("success", response.data);
          // Reload to show disconnected status
          setTimeout(function () {
            window.location.reload();
          }, 1500);
        } else {
          showNotice("error", response.data);
        }
      }
    ).always(function () {
      $button.prop("disabled", false).text(originalText);
    });
  });

  /**
   * Show notice message
   */
  function showNotice(type, message) {
    var $notice = $(
      '<div class="dne-notice dne-notice-' + type + '">' + message + "</div>"
    );

    // Remove any existing notices
    $(".dne-notice").remove();

    // Add new notice
    $(".deal-notification-fields").before($notice);

    // Auto-hide after 5 seconds
    setTimeout(function () {
      $notice.fadeOut(function () {
        $(this).remove();
      });
    }, 5000);
  }

  // OneSignal Integration
  if (
    typeof dne_onesignal !== "undefined" &&
    dne_onesignal.user_id &&
    dne_onesignal.user_id !== "0"
  ) {
    initializeOneSignal();
  }

  /**
   * Initialize OneSignal integration
   */
  function initializeOneSignal() {
    var userId = dne_onesignal.user_id;
    var isDebug = dne_onesignal.debug_mode === "1";

    if (isDebug) console.log("[DNE] Initializing OneSignal for user:", userId);

    // Check if OneSignal is already loaded
    if (window.OneSignal && window.OneSignal.User) {
      // OneSignal already initialized, use it directly
      if (isDebug)
        console.log("[DNE] OneSignal already loaded, using directly");

      (async function () {
        // Check current subscription status
        const isSubscribed = await OneSignal.User.PushSubscription.optedIn;
        if (isDebug)
          console.log("[DNE] OneSignal subscription status:", isSubscribed);

        // If already subscribed, set External ID
        if (isSubscribed) {
          await setOneSignalExternalId();
        }

        // Listen for subscription changes
        OneSignal.User.PushSubscription.addEventListener(
          "change",
          function (event) {
            if (isDebug)
              console.log("[DNE] OneSignal subscription changed:", event);

            if (event.current.optedIn === true) {
              // User just subscribed
              setOneSignalExternalId();

              // Track subscription in WordPress
              $.post(dne_ajax.ajax_url, {
                action: "dne_onesignal_subscribed",
                user_id: userId,
                nonce: dne_ajax.nonce,
              });
            }
          }
        );

        // Add visual indicator for push notification status
        if ($("#delivery_webpush").length > 0) {
          updatePushStatusIndicator(isSubscribed);
        }
      })();
    } else {
      // Wait for OneSignal to be ready
      window.OneSignalDeferred = window.OneSignalDeferred || [];

      OneSignalDeferred.push(async function (OneSignal) {
        if (isDebug) console.log("[DNE] OneSignal SDK Loaded via deferred");

        // Check current subscription status
        const isSubscribed = await OneSignal.User.PushSubscription.optedIn;
        if (isDebug)
          console.log("[DNE] OneSignal subscription status:", isSubscribed);

        // If already subscribed, set External ID
        if (isSubscribed) {
          await setOneSignalExternalId();
        }

        // Listen for subscription changes
        OneSignal.User.PushSubscription.addEventListener(
          "change",
          function (event) {
            if (isDebug)
              console.log("[DNE] OneSignal subscription changed:", event);

            if (event.current.optedIn === true) {
              // User just subscribed
              setOneSignalExternalId();

              // Track subscription in WordPress
              $.post(dne_ajax.ajax_url, {
                action: "dne_onesignal_subscribed",
                user_id: userId,
                nonce: dne_ajax.nonce,
              });
            }
          }
        );

        // Add visual indicator for push notification status
        if ($("#delivery_webpush").length > 0) {
          updatePushStatusIndicator(isSubscribed);
        }
      });
    }
  }

  /**
   * Set External User ID in OneSignal
   */
  async function setOneSignalExternalId() {
    if (typeof dne_onesignal === "undefined" || !dne_onesignal.user_id) {
      return false;
    }

    var userId = dne_onesignal.user_id;
    var isDebug = dne_onesignal.debug_mode === "1";

    try {
      if (isDebug) console.log("[DNE] Setting OneSignal External ID:", userId);

      // Set the external user ID using OneSignal's login method
      await OneSignal.login(String(userId));

      // Also set tags for additional targeting options
      await OneSignal.User.addTags({
        wordpress_user_id: String(userId),
        wordpress_user: "true",
      });

      if (isDebug)
        console.log("[DNE] OneSignal External ID and tags set successfully");

      return true;
    } catch (error) {
      if (isDebug)
        console.error("[DNE] Failed to set OneSignal External ID:", error);
      return false;
    }
  }

  /**
   * Update visual indicator for push subscription status
   */
  function updatePushStatusIndicator(isSubscribed) {
    var indicator = $('<span class="push-status-indicator"></span>');
    var webPushLabel = $("#delivery_webpush").parent().parent();

    if (isSubscribed) {
      indicator.html(' <span style="color: green;">✓ Connected</span>');
    } else {
      indicator.html(
        ' <span style="color: orange;">⚠ Click Save to enable</span>'
      );
    }

    webPushLabel.find(".push-status-indicator").remove();
    webPushLabel.append(indicator);
  }

  // Debug mode indicator
  if (window.location.href.indexOf("dne_debug=1") !== -1) {
    console.log("[DNE] Debug mode active - Check console for detailed logs");

    // Log OneSignal status if available
    if (typeof OneSignal !== "undefined") {
      console.log("[DNE] OneSignal SDK detected");
      // Use OneSignalDeferred for v16 compatibility
      if (window.OneSignalDeferred) {
        OneSignalDeferred.push(async function (OneSignal) {
          try {
            const isEnabled = await OneSignal.User.PushSubscription.optedIn;
            console.log("[DNE] OneSignal push enabled:", isEnabled);
            if (isEnabled) {
              const id = await OneSignal.User.PushSubscription.id;
              console.log("[DNE] OneSignal Subscription ID:", id);
            }
          } catch (error) {
            console.error("[DNE] Error checking OneSignal status:", error);
          }
        });
      }
    } else {
      console.log(
        "[DNE] OneSignal SDK not found - is the OneSignal plugin active?"
      );
    }
  }
});

// Add some basic styles for notices
(function () {
  var style = document.createElement("style");
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
