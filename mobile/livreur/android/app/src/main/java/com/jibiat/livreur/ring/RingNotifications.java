package com.jibiat.livreur.ring;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.media.AudioAttributes;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;

import androidx.core.app.NotificationCompat;

import com.jibiat.livreur.MainActivity;
import com.jibiat.livreur.R;

/**
 * Sonnerie d'une nouvelle mission : notification « appel entrant » (sonnerie du téléphone en boucle, vibration,
 * écran plein écran par-dessus le verrouillage), arrêtée par la réponse du livreur ou au bout d'une minute.
 */
public final class RingNotifications {

    public static final String EXTRA_PATH = "jibiat_ring_path";

    private static final String CHANNEL = "missions_ring_v1";
    // Même sonnerie, autorisée à sonner en mode « Ne pas déranger » (accès accordé par le livreur)
    private static final String CHANNEL_DND = "missions_ring_dnd_v1";
    private static final int NOTIFICATION_ID = 4242;
    private static final long TIMEOUT_MS = 60_000;
    private static final long[] VIBRATION = {0, 800, 600, 800, 600, 800};
    private static final String PREFS = "jibiat_ring";

    private RingNotifications() {}

    public static void show(Context context, String ids, String title, String text, String path) {
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager == null) return;
        String channel = ensureChannel(context, manager);

        Intent intent = new Intent(context, MainActivity.class)
            .putExtra(EXTRA_PATH, path)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent open = PendingIntent.getActivity(context, NOTIFICATION_ID, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, channel)
            .setSmallIcon(R.drawable.ic_stat_mission)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(new NotificationCompat.BigTextStyle().bigText(text))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setOngoing(true)
            .setAutoCancel(true)
            .setTimeoutAfter(TIMEOUT_MS)
            .setContentIntent(open)
            .setFullScreenIntent(open, true)
            // Avant Android 8, le son et la vibration se règlent sur la notification
            .setSound(ringtone(), android.media.AudioManager.STREAM_RING)
            .setVibrate(VIBRATION);

        Notification notification = builder.build();
        notification.flags |= Notification.FLAG_INSISTENT;
        manager.notify(NOTIFICATION_ID, notification);
        prefs(context).edit().putString("ids", ids).apply();
    }

    /** Arrêt de la sonnerie ; avec des missions précisées, seulement si c'est elles qui sonnent */
    public static void stop(Context context, String ids) {
        String current = prefs(context).getString("ids", "");
        if (ids != null && !ids.isEmpty() && !current.isEmpty() && !overlaps(current, ids)) return;
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager != null) manager.cancel(NOTIFICATION_ID);
        prefs(context).edit().remove("ids").apply();
    }

    public static void saveToken(Context context, String token) {
        prefs(context).edit().putString("token", token).apply();
    }

    private static boolean overlaps(String a, String b) {
        for (String id : b.split(",")) {
            for (String other : a.split(",")) {
                if (id.trim().equals(other.trim())) return true;
            }
        }
        return false;
    }

    private static String ensureChannel(Context context, NotificationManager manager) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return CHANNEL;
        boolean dnd = manager.isNotificationPolicyAccessGranted();
        String id = dnd ? CHANNEL_DND : CHANNEL;
        if (manager.getNotificationChannel(id) == null) {
            NotificationChannel channel = new NotificationChannel(id, "Nouvelles missions", NotificationManager.IMPORTANCE_HIGH);
            channel.setDescription("Sonne comme un appel pour un nouveau ramassage ou une nouvelle livraison");
            channel.setSound(ringtone(), new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build());
            channel.enableVibration(true);
            channel.setVibrationPattern(VIBRATION);
            channel.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
            if (dnd) channel.setBypassDnd(true);
            manager.createNotificationChannel(channel);
        }
        return id;
    }

    private static Uri ringtone() {
        Uri uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE);
        return uri != null ? uri : RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);
    }

    private static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }
}
