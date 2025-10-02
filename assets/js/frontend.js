/**
 * Deal Notification Engine - Frontend JavaScript
 * OneSignal SDK v16 Complete Fix with Cleanup
 */
console.log('[DNE] Script loaded at:', Date.now());
jQuery(document).ready(function ($) {
  // Track previous delivery methods to detect changes
  var previousDeliveryMethods = [];
  var DNE_ENFORCING = false;

  jQuery(function ($) {
    var tier = parseInt(
      $(".deal-notification-fields").data("tier-level") || "3",
      10
    );
    // UI visibility only (server clamps authoritatively)
    // New mapping: 1=webpush; 2=webpush+telegram; 3=email+webpush+telegram
    var $email   = $(".dne-channel--email");
    var $webpush = $(".dne-channel--webpush");
    var $tg      = $(".dne-channel--telegram");
    $email.show(); $webpush.show(); $tg.show();
    if (tier === 1) { $email.hide(); $tg.hide(); }
    else if (tier === 2) { $email.hide(); }
    // tier 3 shows all
  });

  // Store initial state on page load
  function captureInitialState() {
    previousDeliveryMethods = [];
    $('input[name="notification_delivery_methods[]"]:checked').each(
      function () {
        previousDeliveryMethods.push($(this).val());
      }
    );
  }

  // Capture initial state
  captureInitialState();

  var tierLevel =
    window.dne_ajax && dne_ajax.tier_level
      ? parseInt(dne_ajax.tier_level, 10)
      : null;
  if (!tierLevel || isNaN(tierLevel)) {
    var catLimit = parseInt(
      $("#user_category_filter").data("tier-limit") || "999",
      10
    );
    var storeLimit = parseInt(
      $("#user_store_filter").data("tier-limit") || "999",
      10
    );
    if (catLimit === 1 && storeLimit === 1) tierLevel = 1;
    else if (catLimit === 3 && storeLimit === 3) tierLevel = 2;
    else tierLevel = 3;
  }

  // Align with server Filter::validate_tier_limits()
  var tierLimits = {
    1: { total: 1, categories: 1, stores: 1 },
    2: { total: 7, categories: 3, stores: 3 },
    3: { total: 999, categories: 999, stores: 999 },
  };

  function getMultiVal($sel) {
    // Works with native and Select2.
    var v = $sel.val();
    return Array.isArray(v) ? v : v ? [v] : [];
  }

  function clampArray(arr, max) {
    arr = Array.from(new Set(arr)); // unique
    return arr.length > max ? arr.slice(0, max) : arr;
  }

  function showTierWarning(message) {
    var $container = $(".deal-notification-fields");
    if ($container.length === 0) {
      // Fallbacks for UM/other templates
      $container = $("#save-notification-preferences").closest("form");
      if ($container.length === 0) $container = $("form").first();
    }
    var $warning = $("#tier-warning");
    if ($warning.length === 0) {
      $warning = $(
        '<div id="tier-warning" style="background:#ffebee;color:#c62828;padding:10px;margin:10px 0;border-radius:3px;border-left:4px solid #c62828;"></div>'
      );
      $container.prepend($warning);
    }
    $warning.html("<strong>⚠ Tier limit:</strong> " + message).show();
  }
  function hideTierWarning() {
    $("#tier-warning").hide();
  }

  // Auto-enforce (clamp) + warn
  function enforceTierLimits() {
    var limits = tierLimits[tierLevel] || tierLimits[3];

    var $discount = $("#user_discount_filter");
    var $cats = $("#user_category_filter");
    var $stores = $("#user_store_filter");

    var hasDiscount = !!(
      $discount.val() && String($discount.val()).trim() !== ""
    );
    var cats = getMultiVal($cats);
    var stores = getMultiVal($stores);

    // Per-bucket clamping
    var clampedCats = clampArray(cats, limits.categories);
    var clampedStores = clampArray(stores, limits.stores);

    var changed =
      clampedCats.length !== cats.length ||
      clampedStores.length !== stores.length;

    // Recalculate total (discount counts as 1 if present)
    var total =
      (hasDiscount ? 1 : 0) + clampedCats.length + clampedStores.length;

    // If over total limit, drop newest from stores first, then categories, then discount last.
    function dropLast(list) {
      list.pop();
      return list;
    }

    var messages = [];
    if (clampedCats.length < cats.length)
      messages.push("Max " + limits.categories + " categories for your tier.");
    if (clampedStores.length < stores.length)
      messages.push("Max " + limits.stores + " stores for your tier.");

    while (total > limits.total && clampedStores.length) {
      dropLast(clampedStores);
      total--;
      changed = true;
      if (
        !messages.includes(
          "Max total selections is " + limits.total + " for your tier."
        )
      )
        messages.push(
          "Max total selections is " + limits.total + " for your tier."
        );
    }
    while (total > limits.total && clampedCats.length) {
      dropLast(clampedCats);
      total--;
      changed = true;
      if (
        !messages.includes(
          "Max total selections is " + limits.total + " for your tier."
        )
      )
        messages.push(
          "Max total selections is " + limits.total + " for your tier."
        );
    }
    if (total > limits.total && hasDiscount) {
      $discount.val("");
      hasDiscount = false;
      total--;
      changed = true;
      if (
        !messages.includes(
          "Max total selections is " + limits.total + " for your tier."
        )
      )
        messages.push(
          "Max total selections is " + limits.total + " for your tier."
        );
    }

    if (changed) {
      DNE_ENFORCING = true;
      $cats.val(clampedCats).trigger("change.select2");
      $stores.val(clampedStores).trigger("change.select2");
      DNE_ENFORCING = false;
    }

    if (messages.length) showTierWarning(messages.join(" "));
    else hideTierWarning();

    // Return whether state is valid (never blocks submit because we self-clamp)
    return true;
  }

  // Wire up on input/selection changes
  $("#user_discount_filter, #user_category_filter, #user_store_filter").on(
    "change input",
    function () {
      if (DNE_ENFORCING) return;
      enforceTierLimits();
    }
  );

  // Also enforce right before save button fires (in case user pasted values etc.)
  $(document).on(
    "click",
    "#deal-preferences-save, .deal-preferences-save",
    function () {
      enforceTierLimits();
    }
  );

  // Handle notification preference save button
  $("#save-notification-preferences").on("click", async function (e) {
    e.preventDefault();

    var $button = $(this);
    var originalText = $button.text();
    var webPushChecked = $("#delivery_webpush").is(":checked");
    var webPushWasChecked = previousDeliveryMethods.includes("webpush");

    // Check if user is trying to enable web push
    if (webPushChecked && !webPushWasChecked) {
      // Verify browser support first
      if (!("Notification" in window) || !("serviceWorker" in navigator)) {
        showNotice(
          "error",
          "Push notifications are not supported in your browser. Please use a modern desktop browser (Chrome, Firefox, Edge, or Safari)."
        );
        $("#delivery_webpush").prop("checked", false);
        return false;
      }

      // Check if notifications are permanently blocked
      if (Notification.permission === "denied") {
        showNotice(
          "error",
          "Push notifications are blocked in your browser. Please enable them in your browser settings and refresh the page."
        );
        $("#delivery_webpush").prop("checked", false);
        return false;
      }

      // Try to subscribe to OneSignal
      $button.text("Setting up push notifications...").prop("disabled", true);

      try {
        const subscriptionResult = await handleOneSignalSubscription();

        if (!subscriptionResult.success) {
          // Failed to subscribe
          showNotice(
            "error",
            subscriptionResult.message ||
              "Failed to enable push notifications. Please try again."
          );
          $("#delivery_webpush").prop("checked", false);
          $button.text(originalText).prop("disabled", false);
          return false;
        }

        // Success - continue with save
        showNotice("success", "Push notifications enabled successfully!");
      } catch (error) {
        console.error("[DNE] Error enabling push notifications:", error);
        showNotice(
          "error",
          "Failed to enable push notifications. Please try again."
        );
        $("#delivery_webpush").prop("checked", false);
        $button.text(originalText).prop("disabled", false);
        return false;
      }
    }

    // Check if user is disabling web push
    if (!webPushChecked && webPushWasChecked) {
      // Properly unsubscribe from OneSignal
      $button.text("Disabling push notifications...").prop("disabled", true);

      try {
        await handleOneSignalUnsubscription();
        showNotice("info", "Push notifications disabled.");
      } catch (error) {
        console.error("[DNE] Error disabling push notifications:", error);
        // Continue with save even if unsubscription fails
      }
    }

    // Now proceed with the actual save
    $button.text("Saving...").prop("disabled", true);

    // Gather form data
    var formData = {
      action: "save_deal_notification_preferences",
      nonce: dne_ajax.nonce,
      user_id: $button.data("user-id") || dne_ajax.user_id,
      notifications_enabled: $("#notifications_enabled").is(":checked")
        ? "1"
        : "0",
      delivery_methods: [],
      user_discount_filter: $("#user_discount_filter").val(),
      user_category_filter: [],
      user_store_filter: [],
    };

    // Get delivery methods
    $('input[name="notification_delivery_methods[]"]:checked').each(
      function () {
        formData.delivery_methods.push($(this).val());
      }
    );

    // Get category filters
    $("#user_category_filter option:selected").each(function () {
      formData.user_category_filter.push($(this).val());
    });

    // Get store filters from the SELECT element, not checkboxes
    $("#user_store_filter option:selected").each(function () {
      formData.user_store_filter.push($(this).val());
    });

    // Save preferences
    $.post(dne_ajax.ajax_url, formData, function (response) {
      if (response.success) {
        showNotice("success", "Preferences saved successfully!");
        // Update previous state
        captureInitialState();
      } else {
        showNotice("error", response.data || "Failed to save preferences");
      }
    })
      .fail(function () {
        showNotice("error", "Network error. Please try again.");
      })
      .always(function () {
        $button.text(originalText).prop("disabled", false);
      });
  });

  // Quick toggle for notifications_enabled without full form submit
  $(document).on('change', '#notifications_enabled', function () {
    if (typeof dne_ajax === 'undefined') return;
    var on = $(this).is(':checked') ? '1' : '0';
    $.post(dne_ajax.ajax_url, {
      action: 'dne_set_notifications_enabled',
      nonce: dne_ajax.nonce,
      user_id: dne_ajax.user_id,
      enabled: on,
    }, function (res) {
      if (res && res.success) {
        showNotice('info', on === '1' ? 'Notifications enabled' : 'Notifications disabled');
      } else {
        showNotice('error', (res && res.data) ? res.data : 'Failed to update setting');
      }
    }).fail(function(){
      showNotice('error', 'Network error updating setting');
    });
  });

  /**
   * Handle OneSignal subscription with SDK v16 methods
   * Includes cleanup of disabled subscriptions
   */
  async function handleOneSignalSubscription() {
    return new Promise((resolve) => {
      if (typeof window.OneSignalDeferred === "undefined") {
        console.log('[DNE] OneSignal detected, will initialize');
        resolve({
          success: false,
          message:
            "OneSignal is not loaded. Please ensure the OneSignal plugin is active.",
        });
        return;
      }

      window.OneSignalDeferred.push(async function (OneSignal) {
        try {
          const isDebug = dne_ajax.debug_mode === "1";
          const userId = dne_ajax.user_id;

          if (isDebug)
            console.log("[DNE] Starting OneSignal subscription process...");

          // Step 1: (Simple mode) Do not cleanup via server; proceed directly
          if (isDebug)
            console.log("[DNE] Simple mode: skipping server-side cleanup");

          // Step 2: Identity sanity check (avoid unnecessary logout)
          let currentExternalId = null;
          try {
            currentExternalId = await OneSignal.User.externalId;
          } catch (e) {}
          if (isDebug)
            console.log("[DNE] Current External ID:", currentExternalId);
          if (
            currentExternalId &&
            String(currentExternalId) !== String(userId)
          ) {
            try {
              if (isDebug)
                console.log(
                  "[DNE] External ID mismatch; logging out to switch identity"
                );
              await OneSignal.logout();
              if (isDebug) console.log("[DNE] Cleared previous External ID");
            } catch (e) {}
          }

          // Step 3: Check browser permission
          const browserPermission = Notification.permission;
          if (isDebug)
            console.log("[DNE] Browser permission:", browserPermission);

          if (browserPermission === "denied") {
            resolve({
              success: false,
              message:
                "Push notifications are blocked in your browser settings.",
            });
            return;
          }

          // Step 4: Check current subscription status
          let isSubscribed = await OneSignal.User.PushSubscription.optedIn;
          const token = await OneSignal.User.PushSubscription.token;

          if (isDebug) {
            console.log("[DNE] Current subscription status:", isSubscribed);
            console.log("[DNE] Has token:", !!token);
          }

          // Step 5: Subscribe if needed
          if (!isSubscribed) {
            if (browserPermission === "granted") {
              // Permission already granted - just opt in
              if (isDebug)
                console.log("[DNE] Permission granted, opting in...");
              await OneSignal.User.PushSubscription.optIn();
            } else {
              // Need to prompt for permission
              if (isDebug) console.log("[DNE] Prompting for permission...");

              // Show the slidedown prompt
              await OneSignal.Slidedown.promptPush({ force: true });

              // Wait for user response
              await new Promise((r) => setTimeout(r, 1000));

              // Check if user accepted
              const newPermission = Notification.permission;
              if (newPermission !== "granted") {
                resolve({
                  success: false,
                  message:
                    "Please accept the notification prompt to enable push notifications.",
                });
                return;
              }
            }

            // Wait for subscription to complete
            await new Promise((r) => setTimeout(r, 2000));

            // Verify subscription
            isSubscribed = await OneSignal.User.PushSubscription.optedIn;

            if (!isSubscribed) {
              resolve({
                success: false,
                message: "Failed to complete subscription. Please try again.",
              });
              return;
            }
          }

          // Step 6: Get subscription ID
          let subscriptionId = await OneSignal.User.PushSubscription.id;
          let attempts = 0;

          while (!subscriptionId && attempts < 10) {
            await new Promise((r) => setTimeout(r, 500));
            subscriptionId = await OneSignal.User.PushSubscription.id;
            attempts++;
          }

          if (!subscriptionId) {
            if (isDebug) console.log("[DNE] No subscription ID available");
            resolve({
              success: false,
              message: "Unable to get subscription ID. Please try again.",
            });
            return;
          }

          if (isDebug) console.log("[DNE] Subscription ID:", subscriptionId);

          // Step 7: Set External ID (critical for targeting)
          currentExternalId = await OneSignal.User.externalId;
          if (String(currentExternalId) !== String(userId)) {
            await OneSignal.login(String(userId));
            if (isDebug) console.log("[DNE] External ID set to:", userId);
          } else if (isDebug) {
            console.log("[DNE] External ID already correct; skipping login");
          }

          // Step 8: Set tags for additional targeting
          await OneSignal.User.addTags({
            dne_user_id: String(userId),
            dne_deals_enabled: "1",
            dne_subscribed_at: new Date().toISOString(),
          });

          if (isDebug) console.log("[DNE] Tags set successfully");

          // Step 9: Track subscription in WordPress
          $.post(dne_ajax.ajax_url, {
            action: "dne_onesignal_subscribed",
            user_id: userId,
            subscription_id: subscriptionId,
            nonce: dne_ajax.nonce,
          });

          // Update UI
          updatePushStatusIndicator(true);

          resolve({ success: true });
        } catch (error) {
          console.error("[DNE] Error in subscription process:", error);
          resolve({
            success: false,
            message:
              "An error occurred while setting up push notifications. Please try again.",
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
      if (typeof window.OneSignalDeferred === "undefined") {
        resolve(false);
        return;
      }

      window.OneSignalDeferred.push(async function (OneSignal) {
        try {
          const isDebug = dne_ajax.debug_mode === "1";

          if (isDebug)
            console.log("[DNE] Starting OneSignal unsubscription...");

          // Opt out from push (sets notification_types to -2)
          await OneSignal.User.PushSubscription.optOut();
          if (isDebug) console.log("[DNE] Opted out from push notifications");

          // Remove External ID
          await OneSignal.logout();
          if (isDebug) console.log("[DNE] Removed External ID");

          // Update UI
          updatePushStatusIndicator(false);

          // Track in WordPress
          $.post(dne_ajax.ajax_url, {
            action: "dne_onesignal_unsubscribed",
            user_id: dne_ajax.user_id,
            nonce: dne_ajax.nonce,
          });

          resolve(true);
        } catch (error) {
          console.error("[DNE] Error in unsubscription:", error);
          resolve(false);
        }
      });
    });
  }

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

    $(".dne-notice").remove();
    $(".deal-notification-fields").before($notice);

    setTimeout(function () {
      $notice.fadeOut(function () {
        $(this).remove();
      });
    }, 5000);
  }

  /**
   * Update visual indicator for push subscription status
   */
  function updatePushStatusIndicator(isSubscribed) {
    // Find the webpush checkbox container
    var $webPushField = $("#delivery_webpush").closest(".um-field-area");
    if ($webPushField.length === 0) return;

    // Remove any existing status
    $("#webpush-connection-status").remove();

    if (isSubscribed) {
      // Add status HTML exactly like Telegram
      var statusHtml =
        '<div id="webpush-connection-status" style="margin-top: 10px; margin-left: 28px;">' +
        '<div style="color: #28a745; font-size: 14px;">' +
        "✓ Browser push connected successfully" +
        '<button type="button" id="disconnect-webpush" style="margin-left: 10px; font-size: 12px; background: #dc3545; color: white; border: none; padding: 2px 8px; border-radius: 3px; cursor: pointer;">' +
        "Disconnect" +
        "</button>" +
        "</div>" +
        "</div>";
      $webPushField.append(statusHtml);
    }
    // If not subscribed, we don't show anything (just like Telegram doesn't show anything when not connected)
  }

  // Initialize OneSignal status check on page load
  if (
    typeof dne_ajax !== "undefined" &&
    dne_ajax.user_id &&
    dne_ajax.user_id !== "0"
  ) {
    // Check if user has webpush in their saved preferences
    var hasWebPush =
      previousDeliveryMethods.includes("webpush") ||
      $("#delivery_webpush").is(":checked");

    // Check OneSignal status if user might use web push
    if (hasWebPush) {
      checkOneSignalStatus();
    } else {
      updatePushStatusIndicator(false);
    }
  }

  // Handle disconnect button for web push (like Telegram)
  $(document).on("click", "#disconnect-webpush", function () {
    if (
      !confirm(
        "Are you sure you want to disconnect browser push notifications?"
      )
    ) {
      return;
    }

    var $button = $(this);
    var originalText = $button.text();
    $button.prop("disabled", true).text("Disconnecting...");

    handleOneSignalUnsubscription()
      .then(function () {
        $("#delivery_webpush").prop("checked", false);
        showNotice("info", "Browser push disconnected.");
        // Reload to update UI (like Telegram does)
        setTimeout(function () {
          window.location.reload();
        }, 1500);
      })
      .catch(function (error) {
        console.error("[DNE] Error disconnecting:", error);
        showNotice("error", "Failed to disconnect browser push.");
        $button.prop("disabled", false).text(originalText);
      });
  });

  // When Email/Telegram toggles change, show a notice
  $('input[name="notification_delivery_methods[]"]').on("change", function () {
    var chan = $(this).val();
    var on = $(this).is(":checked");

    if (!on) {
      if (chan === "email") {
        showNotice(
          "info",
          "Email disabled (pending) — click Save to apply."
        );
      }
      if (chan === "telegram") {
        showNotice(
          "info",
          "Telegram notifications disabled. You will no longer receive Telegram messages. (Disconnect is optional—use it only to revoke the bot.)"
        );
      }
    }
  });

  /**
   * Check OneSignal status on page load
   */
  function checkOneSignalStatus() {
    var isDebug = dne_ajax.debug_mode === "1";

    if (isDebug)
      console.log(
        "[DNE] Checking OneSignal status for user:",
        dne_ajax.user_id
      );

    if (typeof window.OneSignalDeferred === "undefined") {
      if (isDebug) console.log("[DNE] OneSignal SDK not loaded");
      updatePushStatusIndicator(false);
      return;
    }

    window.OneSignalDeferred.push(async function (OneSignal) {
      try {
        // Wait for SDK initialization
        await new Promise((resolve) => setTimeout(resolve, 1000));

        const isSubscribed = await OneSignal.User.PushSubscription.optedIn;
        const subscriptionId = await OneSignal.User.PushSubscription.id;
        const externalId = await OneSignal.User.externalId;

        if (isDebug) {
          console.log("[DNE] Current OneSignal Status:");
          console.log("  - Subscribed:", isSubscribed);
          console.log("  - Subscription ID:", subscriptionId);
          console.log("  - External ID:", externalId);
        }

        // Update UI based on status
        if (isSubscribed && externalId === String(dne_ajax.user_id)) {
          updatePushStatusIndicator(true);
        } else {
          updatePushStatusIndicator(false);

          // If subscribed but no External ID, fix it
          if (isSubscribed && !externalId) {
            if (isDebug) console.log("[DNE] Fixing missing External ID...");

            await OneSignal.login(String(dne_ajax.user_id));

            // Track in WordPress
            $.post(dne_ajax.ajax_url, {
              action: "dne_onesignal_subscribed",
              user_id: dne_ajax.user_id,
              subscription_id: subscriptionId,
              nonce: dne_ajax.nonce,
            });

            updatePushStatusIndicator(true);
          }
        }
      } catch (error) {
        if (isDebug)
          console.error("[DNE] Error checking OneSignal status:", error);
        updatePushStatusIndicator(false);
      }
    });
  }

  // Browser compatibility check on page load
  $(window).on("load", function () {
    if ($("#delivery_webpush").length > 0) {
      if (!("Notification" in window) || !("serviceWorker" in navigator)) {
        $("#delivery_webpush").prop("disabled", true);
        $("#delivery_webpush")
          .parent()
          .parent()
          .append(
            '<span class="browser-warning" style="color: red; display: block; margin-top: 5px;">' +
              "Push notifications are not supported in your browser</span>"
          );
      } else if (Notification.permission === "denied") {
        $("#delivery_webpush")
          .parent()
          .parent()
          .append(
            '<span class="browser-warning" style="color: orange; display: block; margin-top: 5px;">' +
              "Push notifications are blocked. Enable them in browser settings.</span>"
          );
      }
    }
  });
});

// Add styles for notices and indicators
(function () {
  var style = document.createElement("style");
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
console.log('[DNE] Script finished loading');
