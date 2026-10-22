#!/bin/sh
# Copies légères des vidéos pour les sites (720 px, démarrage rapide) et leurs affiches. Sans son, sauf avec
# AVEC_SON=1 (vidéos avec voix off).
#   tools/motion/site-copies.sh <dossier de destination> <scène-format> ...
#   ex. tools/motion/site-copies.sh public/marketing/videos deux-applications-v deux-applications-w
cd "$(dirname "$0")/../.." || exit 1
dest="$1"; shift
mkdir -p "$dest"
for name in "$@"; do
  src="tools/motion/out/$name.mp4"
  case "$name" in *-w) scale='1280:-2' ;; *) scale='720:-2' ;; esac
  if [ "${AVEC_SON:-0}" = 1 ]; then audio='-c:a aac -b:a 96k'; else audio='-an'; fi
  # shellcheck disable=SC2086
  ffmpeg -loglevel error -y -i "$src" $audio -vf "scale=$scale" -c:v libx264 -preset slow -crf 27 -pix_fmt yuv420p -movflags +faststart "$dest/$name.mp4"
  ffmpeg -loglevel error -y -ss 1.2 -i "$src" -frames:v 1 -vf "scale=$scale" -q:v 4 "$dest/$name.jpg"
  echo "✓ $dest/$name.mp4 ($(du -h "$dest/$name.mp4" | cut -f1))"
done
