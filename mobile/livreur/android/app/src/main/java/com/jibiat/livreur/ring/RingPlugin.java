package com.jibiat.livreur.ring;

import android.Manifest;
import android.app.Activity;
import android.app.NotificationManager;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.view.WindowManager;

import androidx.core.app.NotificationManagerCompat;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.google.firebase.FirebaseApp;
import com.google.firebase.messaging.FirebaseMessaging;

/**
 * Module « Ring » des écrans de l'application : jeton Firebase, état des autorisations, réglages d'Android,
 * sonnerie de test, arrêt de la sonnerie, et page à ouvrir quand l'application est lancée par la sonnerie.
 */
@CapacitorPlugin(
    name = "Ring",
    permissions = { @Permission(strings = { Manifest.permission.POST_NOTIFICATIONS }, alias = "notifications") }
)
public class RingPlugin extends Plugin {

    private static RingPlugin instance;
    private static String pendingPath;

    @Override
    public void load() {
        instance = this;
    }

    /** Application ouverte par la sonnerie : au-dessus de l'écran verrouillé, page « appel entrant » */
    public static void handleIntent(Activity activity, Intent intent) {
        if (intent == null) return;
        String path = intent.getStringExtra(RingNotifications.EXTRA_PATH);
        if (path == null || path.isEmpty()) return;
        intent.removeExtra(RingNotifications.EXTRA_PATH);
        showOverLock(activity, true);
        if (instance != null && instance.hasListeners("ring")) {
            JSObject data = new JSObject();
            data.put("path", path);
            instance.notifyListeners("ring", data, true);
        } else {
            pendingPath = path;
        }
    }

    @PluginMethod
    public void consumePath(PluginCall call) {
        JSObject result = new JSObject();
        result.put("path", pendingPath);
        pendingPath = null;
        call.resolve(result);
    }

    @PluginMethod
    public void status(PluginCall call) {
        NotificationManager manager = getContext().getSystemService(NotificationManager.class);
        JSObject result = new JSObject();
        result.put("available", firebaseReady());
        result.put("notifications", NotificationManagerCompat.from(getContext()).areNotificationsEnabled());
        result.put("fullScreen", Build.VERSION.SDK_INT < 34 || (manager != null && manager.canUseFullScreenIntent()));
        result.put("dnd", manager != null && manager.isNotificationPolicyAccessGranted());
        call.resolve(result);
    }

    @PluginMethod
    public void getToken(PluginCall call) {
        if (!firebaseReady()) {
            call.reject("Sonnerie non activée dans cette version de l'application");
            return;
        }
        FirebaseMessaging.getInstance().getToken().addOnCompleteListener(task -> {
            if (!task.isSuccessful() || task.getResult() == null) {
                call.reject("Jeton indisponible");
                return;
            }
            RingNotifications.saveToken(getContext(), task.getResult());
            JSObject result = new JSObject();
            result.put("token", task.getResult());
            call.resolve(result);
        });
    }

    @PluginMethod
    public void openFullScreenSettings(PluginCall call) {
        Intent intent = Build.VERSION.SDK_INT >= 34
            ? new Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:" + getContext().getPackageName()))
            : new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, getContext().getPackageName());
        open(intent);
        call.resolve();
    }

    @PluginMethod
    public void openDndSettings(PluginCall call) {
        open(new Intent(Settings.ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS));
        call.resolve();
    }

    @PluginMethod
    public void test(PluginCall call) {
        RingNotifications.show(getContext(), "test", "Sonnerie de test", "Voici comment sonnera une nouvelle mission", "/livreur/sonnerie");
        new Handler(Looper.getMainLooper()).postDelayed(() -> RingNotifications.stop(getContext(), "test"), 8000);
        call.resolve();
    }

    @PluginMethod
    public void stop(PluginCall call) {
        RingNotifications.stop(getContext(), null);
        Activity activity = getActivity();
        if (activity != null) activity.runOnUiThread(() -> showOverLock(activity, false));
        call.resolve();
    }

    private void open(Intent intent) {
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            getContext().startActivity(intent);
        } catch (Exception e) {
            getContext().startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + getContext().getPackageName()))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        }
    }

    private boolean firebaseReady() {
        return !FirebaseApp.getApps(getContext()).isEmpty();
    }

    private static void showOverLock(Activity activity, boolean on) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            activity.setShowWhenLocked(on);
            activity.setTurnScreenOn(on);
        } else if (on) {
            activity.getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON);
        } else {
            activity.getWindow().clearFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON);
        }
    }
}
