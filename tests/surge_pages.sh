#!/bin/bash
# Surge — page tests on a GLPI test instance (renders every page and posts every action as a logged-in user).
# Usage: surge_pages.sh iq|g12   (run surge_test.php ENV keep first: it leaves a surge with tickets behind)
E=${1:-iq}; cd ~/$E/t
if [ "$E" = iq ]; then Q=iq_q.php; P=iq_page2w.php; else Q=g12_q.php; P=g12_page2w.php; fi
req() { printf '{"plug":"surge","rel":"front/%s.php","method":"%s","get":%s,"post":%s,"uid":%s}' "$1" "$2" "$3" "$4" "${5:-2}" > /tmp/srp.json; php $P /tmp/srp.json 2>&1; }
n=0; bad=0
chk() { n=$((n+1)); local code=$(echo "$1" | grep -ao '@@CODE=[0-9]*' | tail -1); local w=$(echo "$1" | grep -a -E '@@(WARN|ERROR)=' | sort -u | head -3 | cut -c1-240);
  if [ -n "$w" ] || ! echo "$code" | grep -qE "=(${3:-200|302})$"; then bad=$((bad+1)); echo "SORUN $2 $code"; echo "$w"; else echo "  ok  $2"; fi; }
has() { n=$((n+1)); if echo "$1" | grep -aq -- "$2"; then echo "  ok  $3"; else bad=$((bad+1)); echo "SORUN $3"; fi; }
SID=$(php $Q "select id from glpi_plugin_surge_surges where name like 'ST·%' order by id desc limit 1")
RID=$(php $Q "select id from glpi_plugin_surge_rules where name='ST rule'")
php $Q "update glpi_plugin_surge_surges set status='new', tickets_id_master=0, users_id_ack=0 where id=$SID" >/dev/null  # surge_test leaves it resolved
for spec in "surge|{}" "surge|{\"status\":\"all\"}" "surge|{\"status\":\"resolved\"}" "surge.form|{\"id\":\"$SID\"}" "rules|{}" "rules|{\"id\":\"$RID\",\"edit\":\"1\"}" "rules|{\"id\":\"0\",\"edit\":\"1\"}" "rules|{\"id\":\"$RID\",\"edit\":\"1\",\"test\":\"1\"}" "config|{}"; do
  p=${spec%%|*}; g=${spec#*|}; o=$(req $p GET "$g" '{}'); chk "$o" "GET $p $g"
done
o=$(req surge GET '{"status":"all"}' '{}'); has "$o" "surge-spark" "list: arrival sparkline"; has "$o" "ST·" "list: test surge listed"
o=$(req surge.form GET "{\"id\":\"$SID\"}" '{}'); has "$o" "Create master ticket\|Ana kayıt aç" "detail: master ticket action"; has "$o" "Tell everyone\|Herkesi bilgilendir" "detail: broadcast action"
o=$(req rules GET "{\"id\":\"$RID\",\"edit\":\"1\",\"test\":\"1\"}" '{}'); has "$o" "Test on current tickets\|Mevcut kayıtlarla dene" "rule test (dry run) block"
o=$(req surge.form POST '{}' "{\"id\":\"$SID\",\"do\":\"ack\"}"); chk "$o" "POST ack"
n=$((n+1)); [ "$(php $Q "select status from glpi_plugin_surge_surges where id=$SID")" = active ] && echo "  ok  ack → in progress" || { bad=$((bad+1)); echo "SORUN ack status"; }
o=$(req surge.form POST '{}' "{\"id\":\"$SID\",\"do\":\"broadcast\",\"text\":\"\"}"); chk "$o" "POST broadcast empty"; has "$o" "Write the message first\|Önce mesajı yazın" "empty broadcast refused"
o=$(req surge.form POST '{}' "{\"id\":\"$SID\",\"do\":\"master\",\"title\":\"ST· [ST-MAJOR] page test\"}"); chk "$o" "POST master"
M=$(php $Q "select tickets_id_master from glpi_plugin_surge_surges where id=$SID"); n=$((n+1)); [ "$M" -gt 0 ] && echo "  ok  master #$M" || { bad=$((bad+1)); echo "SORUN master"; }
o=$(req surge.form POST '{}' "{\"id\":\"$SID\",\"do\":\"dismiss\"}"); chk "$o" "POST dismiss"
o=$(req surge.form POST '{}' "{\"id\":\"$SID\",\"do\":\"reopen\"}"); chk "$o" "POST reopen"
o=$(req surge POST '{}' '{"detect":"1"}'); chk "$o" "POST run detection"; has "$o" "Detection finished\|Tespit tamamlandı" "detection message"
o=$(req config POST '{}' '{"master_urgency":"5","master_impact":"3","cascade_solution":"1","link_new":"1","active_hours":"12","webhook_url":"ftp://x","webhook_format":"slack"}'); chk "$o" "POST settings"
n=$((n+1)); [ "$(php $Q "select concat(value) from glpi_configs where context='plugin:surge' and name='webhook_url'")" = "" ] && echo "  ok  non-http webhook URL rejected" || { bad=$((bad+1)); echo "SORUN webhook url"; }
php $Q "update glpi_configs set value='4' where context='plugin:surge' and name in ('master_urgency','master_impact')" >/dev/null
php $Q "update glpi_configs set value='24' where context='plugin:surge' and name='active_hours'" >/dev/null
php $Q "update glpi_configs set value='generic' where context='plugin:surge' and name='webhook_format'" >/dev/null
# 0.2.0 pages: statistics, on-call, notice
for spec in "stats|{}" "stats|{\"days\":\"365\"}" "oncall|{}"; do p=${spec%%|*}; g=${spec#*|}; o=$(req $p GET "$g" '{}'); chk "$o" "GET $p $g"; done
o=$(req stats GET '{}' '{}'); has "$o" "surge-bars" "stats: weekly bars"
o=$(req oncall POST '{}' '{"id":"0","users_id":"4","days":["1","2"],"time_start":"18:00","time_end":"08:00","is_active":"1","comment":"SP"}'); chk "$o" "POST on-call slot"
OC=$(php $Q "select id from glpi_plugin_surge_oncall where comment='SP'"); n=$((n+1)); [ -n "$OC" ] && echo "  ok  on-call slot saved" || { bad=$((bad+1)); echo "SORUN on-call slot"; }
o=$(req oncall POST '{}' "{\"id\":\"$OC\",\"delete\":\"1\"}"); chk "$o" "POST on-call delete"
o=$(req surge.form POST '{}' "{\"id\":\"$SID\",\"do\":\"publish\",\"text\":\"SP notice text\"}"); chk "$o" "POST publish notice"
o=$(req notice GET '{}' '{}'); has "$o" "SP notice text" "notice.php returns the published notice"
o=$(req surge.form POST '{}' "{\"id\":\"$SID\",\"do\":\"unpublish\"}"); chk "$o" "POST withdraw notice"
o=$(req config POST '{}' '{"notify_oncall":"0","notice_central":"0","embed_enabled":"0","embed_url":"","embed_model":"","embed_key":"","embed_weight":"0.5"}'); chk "$o" "POST settings (checkbox off)"
n=$((n+1)); [ "$(php $Q "select value from glpi_configs where context='plugin:surge' and name='notify_oncall'")" = 0 ] && echo "  ok  unticked box saved as off" || { bad=$((bad+1)); echo "SORUN notify_oncall"; }
php $Q "update glpi_configs set value='1' where context='plugin:surge' and name='notify_oncall'" >/dev/null
# ticket banner (rendered through the hook)
T=$(php $Q "select tickets_id from glpi_plugin_surge_items where surges_id=$SID and is_removed=0 limit 1")
cat > /tmp/srb.php <<PHP
<?php
require '$HOME/$E/t/$( [ $E = iq ] && echo iq_boot.php || echo g12_boot.php )';
iq_login(2);
\$t = new Ticket(); \$t->getFromDB($T);
ob_start(); GlpiPlugin\Surge\TicketHooks::banner(['item' => \$t]); echo ob_get_clean();
PHP
o=$(php /tmp/srb.php 2>&1); rm -f /tmp/srb.php; has "$o" "surge-banner" "ticket banner rendered"; has "$o" "Master ticket: #\|Ana kayıt: #" "banner mentions the master ticket"
# rights: user without the right (post-only = Self-Service)
PO=$(php $Q "select id from glpi_users where name='post-only'")
if [ -n "$PO" ]; then o=$(req surge GET '{}' '{}' "$PO"); chk "$o" "post-only: list 403" "403"; o=$(req rules GET '{}' '{}' "$PO"); chk "$o" "post-only: rules 403" "403"; fi
echo; echo "$n test, $bad sorun"
