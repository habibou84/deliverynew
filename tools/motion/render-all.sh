#!/bin/sh
# Toutes les vidéos dans les trois formats (tools/motion/out/)
cd "$(dirname "$0")/../.." || exit 1
for scene in deux-applications entreprise-caisse entreprise-nouveaux-clients marchand-livreur-fiable marchand-suivi-argent marchand-boutique \
  marchand-assistant marchand-ia-commande marchand-ia-photo marchand-ia-reponses marchand-ia-reseaux marchand-ia-chiffres marchand-essai-pro; do
  node tools/motion/render.cjs "$scene" v f w || exit 1
done
