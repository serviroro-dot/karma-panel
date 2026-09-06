#!/bin/bash
OUT=/var/lib/numeros_clientes/numeros.csv
DEL=/var/lib/numeros_clientes/borrados.csv
TMP=$(mktemp)
grep -rhoE "[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}.*sofia/external/[0-9]{6,15}@" /var/log/freeswitch/ 2>/dev/null \
 | sed -E 's|^([0-9]{4}-[0-9]{2}-[0-9]{2}).*sofia/external/([0-9]{6,15})@.*|\2;\1|' \
 | sort -u > "$TMP"
touch "$OUT" "$DEL"
sort -u -o "$DEL" "$DEL"
comm -23 "$TMP" "$DEL" > "$TMP.filtrado"
sort -u "$OUT" "$TMP.filtrado" -o "$OUT"
chown www-data:www-data "$OUT" "$DEL"; chmod 640 "$OUT" "$DEL"
rm -f "$TMP" "$TMP.filtrado"
