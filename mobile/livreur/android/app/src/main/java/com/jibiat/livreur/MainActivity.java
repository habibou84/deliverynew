package com.jibiat.livreur;

import android.content.Intent;
import android.os.Bundle;

import com.getcapacitor.BridgeActivity;
import com.jibiat.livreur.ring.RingPlugin;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Sonnerie des missions : module natif des écrans
        registerPlugin(RingPlugin.class);
        super.onCreate(savedInstanceState);
        RingPlugin.handleIntent(this, getIntent());
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        RingPlugin.handleIntent(this, intent);
    }
}
