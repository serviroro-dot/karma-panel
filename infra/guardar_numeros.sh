#!/bin/bash
OUT=/var/lib/numeros_clientes/numeros.csv
DEL=/var/lib/numeros_clientes/borrados.csv
TMP=$(mktemp)

# 1) Numeros que aparecen en los registros de FreeSWITCH
grep -rhoE "[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}.*sofia/external/[0-9]{6,15}@" /var/log/freeswitch/ 2>/dev/null \
 | sed -E 's|^([0-9]{4}-[0-9]{2}-[0-9]{2}).*sofia/external/([0-9]{6,15})@.*|\2;\1|' >> "$TMP"

# 2) Numeros reales que quedan sin enmascarar en el registro de llamadas
#    (solo los ultimos 2 dias: la tarea corre cada hora, asi no relee 676.000 filas)
sudo -u postgres psql -d fusionpbx -t -A -c \
 "select distinct caller_id_number || ';' || to_char(start_stamp,'YYYY-MM-DD') \
  from v_xml_cdr \
  where start_stamp > now() - interval '2 days' \
    and caller_id_number ~ '^[0-9]{6,15}\$' \
    and caller_id_number not like '000000%';" 2>/dev/null \
 | grep -E '^[0-9]{6,15};[0-9]{4}-[0-9]{2}-[0-9]{2}$' >> "$TMP"

sort -u "$TMP" -o "$TMP"
touch "$OUT" "$DEL"
sort -u -o "$DEL" "$DEL"
comm -23 "$TMP" "$DEL" > "$TMP.filtrado"
sort -u "$OUT" "$TMP.filtrado" -o "$OUT"
chown www-data:www-data "$OUT" "$DEL"; chmod 640 "$OUT" "$DEL"
rm -f "$TMP" "$TMP.filtrado"
