"""
Musique de fond douce et originale (accords tenus, basse, petites notes de cloche, réverbération), composée par le
programme : aucun droit à payer. Sert sous les voix off des vidéos.
  python3 tools/motion/musique/fond-doux.py <durée en secondes> <sortie.wav>
"""
import sys
import wave
import numpy as np

SR = 44100
dur = float(sys.argv[1]) if len(sys.argv) > 1 else 250
out = sys.argv[2] if len(sys.argv) > 2 else 'fond-doux.wav'
n = int(SR * dur)
t = np.arange(n) / SR
rng = np.random.default_rng(7)

def hz(midi):
    return 440.0 * 2 ** ((midi - 69) / 12)

# Ré majeur, calme : Rémaj7 – Sim7 – Solmaj7 – La6sus, 6 secondes chacun
CHORDS = [[62, 66, 69, 73], [59, 62, 66, 69], [55, 59, 62, 66], [57, 62, 64, 66]]
STEP = 6.0
pad = np.zeros(n)
bass = np.zeros(n)
bells = np.zeros(n)

def env(length, attack, release):
    e = np.ones(length)
    a, r = min(int(attack * SR), length // 2), min(int(release * SR), length // 2)
    e[:a] = np.sin(np.linspace(0, np.pi / 2, a)) ** 2
    e[-r:] *= np.cos(np.linspace(0, np.pi / 2, r)) ** 2
    return e

k = 0
start = 0.0
while start < dur:
    chord = CHORDS[k % 4]
    s = int(start * SR)
    length = min(int((STEP + 2.0) * SR), n - s)
    if length <= 0:
        break
    tt = np.arange(length) / SR
    e = env(length, 1.8, 2.2)[:length]
    for note in chord:
        f = hz(note)
        for detune in (-0.12, 0.12):
            ff = f * 2 ** (detune / 12)
            pad[s:s + length] += e * (np.sin(2 * np.pi * ff * tt) + 0.18 * np.sin(2 * np.pi * 2 * ff * tt)) / 8
    f = hz(chord[0] - 12)
    bass[s:s + length] += e * np.sin(2 * np.pi * f * tt) * 0.5
    # Petites notes de cloche, une toutes les 1,5 s, prises dans l'accord à l'octave du dessus
    for j in range(4):
        b0 = int((start + 0.4 + j * 1.5) * SR)
        if b0 >= n:
            break
        bl = min(int(2.5 * SR), n - b0)
        bt = np.arange(bl) / SR
        f = hz(chord[rng.integers(0, 4)] + 12)
        bells[b0:b0 + bl] += np.sin(2 * np.pi * f * bt) * np.exp(-bt * 2.2) * (1 - np.exp(-bt * 60)) * 0.10
    k += 1
    start += STEP

mix = pad * 0.55 + bass * 0.35 + bells

def fftconv(x, h):
    size = 1 << int(np.ceil(np.log2(len(x) + len(h))))
    return np.fft.irfft(np.fft.rfft(x, size) * np.fft.rfft(h, size), size)[:len(x)]

# Adoucir (passe-bas vers 3 kHz) puis réverbération (bruit qui s'éteint en 3 s)
fc = 3000 / SR
m = np.arange(-200, 201)
lp = np.sinc(2 * fc * m) * np.hanning(len(m))
mix = fftconv(mix, lp / lp.sum())
ir_t = np.arange(int(3 * SR)) / SR
ir = rng.standard_normal(len(ir_t)) * np.exp(-ir_t * 2.3)
ir = fftconv(ir, lp / lp.sum())
wet = fftconv(mix, ir / np.abs(ir).sum() * 40)
mix = 0.7 * mix + 0.3 * wet / (np.abs(wet).max() + 1e-9) * np.abs(mix).max()

fade_in, fade_out = int(3 * SR), int(5 * SR)
mix[:fade_in] *= np.linspace(0, 1, fade_in)
mix[-fade_out:] *= np.linspace(1, 0, fade_out)
mix = mix / np.abs(mix).max() * 0.89
stereo = np.stack([mix, np.roll(mix, int(0.012 * SR))], axis=1)
with wave.open(out, 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes((stereo * 32767).astype('<i2').tobytes())
print(out, dur, 's')
