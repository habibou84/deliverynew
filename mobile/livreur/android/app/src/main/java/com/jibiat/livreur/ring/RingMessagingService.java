package com.jibiat.livreur.ring;

import androidx.annotation.NonNull;

import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * Messages Firebase envoyés par Jibiat : « ring » fait sonner le téléphone (nouvelle mission), « ring_stop »
 * arrête la sonnerie (le livreur a répondu sur un autre téléphone, ou la mission lui a été retirée).
 */
public class RingMessagingService extends FirebaseMessagingService {

    @Override
    public void onMessageReceived(@NonNull RemoteMessage message) {
        Map<String, String> data = message.getData();
        String type = data.get("type");
        if ("ring".equals(type)) {
            String line1 = value(data, "line1");
            String line2 = value(data, "line2");
            String text = line2.isEmpty() ? line1 : (line1.isEmpty() ? line2 : line1 + " · " + line2);
            if ("2".equals(data.get("attempt"))) text = "2e appel · " + text;
            RingNotifications.show(this, value(data, "ids"), value(data, "title"), text, value(data, "path"));
        } else if ("ring_stop".equals(type)) {
            RingNotifications.stop(this, value(data, "ids"));
        }
    }

    @Override
    public void onNewToken(@NonNull String token) {
        // Envoyé au serveur par l'application à sa prochaine ouverture
        RingNotifications.saveToken(this, token);
    }

    private static String value(Map<String, String> data, String key) {
        String value = data.get(key);
        return value == null ? "" : value;
    }
}
