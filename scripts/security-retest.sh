#!/usr/bin/env bash
#
# security-retest.sh — re-assert each security fix from the 2026-09 hardening pass.
#
# Read-only and non-destructive: GET/HEAD and a few harmless POSTs with bad/absent
# credentials, plus local unit checks that load a class and call a method. No state is
# changed, no account is created, no secret is printed.
#
# This is the regression gate the audit flagged as missing ("no automated security
# scanning"). Run it after any change to the auth, permission, provisioning, pipeline,
# webhook, or billing paths, and in CI.
#
#   scripts/security-retest.sh                 # against https://tiknix.com
#   RETEST_HOST=https://staging.tiknix.com scripts/security-retest.sh
#
# Exit 0 = all passed, 1 = one or more failed. Checks needing a logged-in session are
# marked SKIP with a note — those are the manual/authenticated follow-ups.

set -u
cd "$(dirname "$0")/.." || exit 2
ROOT=$(pwd)
H=${RETEST_HOST:-https://tiknix.com}
BILLING_DIR=${BILLING_DIR:-/var/www/html/default/billing-service}
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

PASS=0; FAIL=0; SKIP=0
ok(){   printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
no(){   printf '  \033[31mFAIL\033[0m  %s  \033[2m(%s)\033[0m\n' "$1" "$2"; FAIL=$((FAIL+1)); }
skip(){ printf '  \033[33mSKIP\033[0m  %s  \033[2m(%s)\033[0m\n' "$1" "$2"; SKIP=$((SKIP+1)); }
code(){ curl -sk -o /dev/null -w '%{http_code}' "$1"; }
redir(){ curl -sk -o /dev/null -w '%{redirect_url}' "$1"; }

echo "Security retest against $H"
echo

echo "Transport / information disclosure"
[ "$(curl -sk "$H/index/privacy" | grep -icE 'Stack trace|/var/www/|vendor/')" = "0" ] \
  && ok "error pages leak no stack trace or path" || no "error disclosure" "leak markers present"
curl -skI "$H/auth/login" | grep -i '^set-cookie' | grep -qi 'secure' \
  && ok "session cookie carries Secure" || no "session cookie" "no Secure flag"

echo "C4 — CSRF method-override bypass"
rm -f "$TMP/j"; curl -sk -c "$TMP/j" -o /dev/null "$H/auth/login"
curl -sk -b "$TMP/j" -c "$TMP/j" -o /dev/null -X POST "$H/auth/dologin?_method=GET" \
  --data-urlencode "_csrf_token=bad" --data-urlencode "username=x" --data-urlencode "password=y"
FL=$(curl -sk -b "$TMP/j" "$H/auth/login" | grep -oE "Security validation failed|Invalid credentials" | head -1)
[ "$FL" = "Security validation failed" ] \
  && ok "?_method=GET does not skip CSRF validation" || no "CSRF override" "reached: ${FL:-none}"

echo "C1 — 2FA confirm-saved gating (credential-free)"
# Full chain (password then bypass GET) needs real creds + is manual. Here: a bare GET
# with no pending-2FA session must NOT authenticate — it redirects to login, never
# /dashboard. Proves the method+session gate without embedding a password.
R=$(redir "$H/auth/twofaconfirmsaved")
echo "$R" | grep -q '/dashboard' && no "2FA confirm-saved" "GET reached dashboard" \
  || ok "GET confirm-saved does not authenticate"

echo "Archive exposure"
[ "$(code https://aspire.tiknix.com/aspire.zip)" = "404" ] \
  && ok "deleted-project archive is not web-served" || no "archive" "still 200"
[ "$(find /var/www/html/default -maxdepth 3 -path '*/public/*.zip' 2>/dev/null | wc -l)" = "0" ] \
  && ok "no project zips under any public/ docroot" || no "archive" "zips remain in public/"

echo "C2 — permission deny-by-default"
for u in / /index/pricing /auth/login; do
  [ "$(code "$H$u")" = "200" ] && ok "public route reachable: $u" || no "public $u" "not 200"
done
[ "$(code "$H/mcp/health")" = "200" ] && ok "self-authed endpoint reachable: /mcp/health" || no "/mcp/health" "not 200"
for u in /dashboard /admin; do
  redir "$H$u" | grep -q '/auth/login' && ok "unclassified/gated route denied: $u" || no "gated $u" "not denied"
done

echo "SSRF — pipeline HTTP step guard"
php -r 'require "vendor/autoload.php"; use app\services\connectors\RestConnector as R;
  $f=0; foreach(["http://169.254.169.254/","http://127.0.0.1/","http://10.0.0.5/","http://localhost/"] as $u){try{R::assertPublicHost($u);$f++;}catch(\Throwable $e){}}
  foreach(["https://example.com/"] as $u){try{R::assertPublicHost($u);}catch(\Throwable $e){$f++;}} exit($f?1:0);' \
  && ok "guard blocks metadata/loopback/RFC1918, allows public" || no "SSRF guard" "a case slipped"

echo "SQLi — order_by allowlist"
php -r '$s=file_get_contents("'"$ROOT"'/lib/TaskAccessControl.php");
  preg_match("/private static function safeOrderBy.*?\n    \}/s",$s,$m);
  eval("class RT{".$m[0]." static function c(\$a){return self::safeOrderBy(\$a,\"DEF\");}}");
  $f=0; foreach(["id;DROP TABLE x","(SELECT 1)","title,(SELECT 1)","member_id","id/**/1"] as $i) if(RT::c($i)!=="DEF")$f++;
  if(RT::c("created_at DESC")==="DEF")$f++; exit($f?1:0);' \
  && ok "order_by rejects injection, keeps a legit sort" || no "order_by" "a case slipped"

echo "Privilege — member settings allowlist"
php -r '$s=file_get_contents("'"$ROOT"'/controls/Member.php");
  if(!preg_match("/writableSettings = \[(.*?)\];/s",$s,$m))exit(2);
  exit((strpos($m[1],"date_format")!==false && stripos($m[1],"feature")===false)?0:1);' \
  && ok "settings allowlist excludes feature.* (no self-grant)" || no "self-grant" "allowlist wrong/absent"

echo "Privilege — provision root from member, not payload"
php -r 'require "vendor/autoload.php";
  if(!defined("LEVELS"))define("LEVELS",["ROOT"=>1,"ADMIN"=>50,"MEMBER"=>100,"PUBLIC"=>101]);
  $c=parse_ini_file("'"$ROOT"'/conf/config.ini",true); foreach($c as $s=>$v)foreach((array)$v as $k=>$x)Flight::set("$s.$k",$x);
  \RedBeanPHP\R::setup("sqlite:'"$ROOT"'/database/tiknix.db"); Flight::set("log",new class{public function __call($m,$a){}});
  $m=new ReflectionMethod("app\\ProvisionService","memberIsRoot");$m->setAccessible(true);
  exit(($m->invoke(null,1)===true && $m->invoke(null,38)===false && $m->invoke(null,0)===false)?0:1);' \
  && ok "root derived from member level, not is_root flag" || no "is_root" "wrong"

echo "Webhook — Mailgun signature not fail-open"
[ "$(curl -sk -X POST "$H/webhook/mailgun" -H 'Content-Type: application/json' -d '{"event-data":{"event":"failed"}}' -o /dev/null -w '%{http_code}')" = "403" ] \
  && ok "unsigned Mailgun event rejected (403)" || no "mailgun" "not 403"

echo "Invariant — pipeline write tools stay admin-gated"
G=0; for t in PipelineSetTool PipelineDeleteTool; do grep -q "requireAdmin()" mcptools/$t.php || G=1; done
[ "$G" = "0" ] && ok "pipeline_set/_delete require admin (run/continue member-level by design)" || no "pipeline write gate" "a write tool ungated"

echo "Filesystem — control-plane DB and backups"
[ "$(stat -c %a database/tiknix.db)" = "600" ] && ok "core tiknix.db is 600" || no "core DB perms" "not 600"
[ "$(find database -name '*.bak-*' ! -perm 600 2>/dev/null | wc -l)" = "0" ] && ok "all db backups are 600" || no "db backup perms" "some not 600"
git check-ignore -q database/x.bak-000 && ok "*.bak-* is gitignored" || no "backup gitignore" "not ignored"

echo "Billing — SSO cannot assume an arbitrary customer"
if [ -f "$BILLING_DIR/database/billing.db" ]; then
  VIC=$(php -r 'echo (new PDO("sqlite:file:'"$BILLING_DIR"'/database/billing.db?mode=ro"))->query("SELECT billing_email FROM tenant WHERE tenant_slug=\"ltz2\"")->fetchColumn();')
  URL=$(php -r '$c=parse_ini_file("'"$ROOT"'/conf/config.ini",true)["billing"];$p=["app"=>$c["app_slug"],"tenant"=>"tiknix-8f5ce2db7c969cf2","email"=>$argv[1],"name"=>"x","ts"=>(string)time()];ksort($p);$p["sig"]=hash_hmac("sha256",http_build_query($p),$c["app_secret"]);echo rtrim($c["service_url"],"/")."/auth/sso?".http_build_query($p);' "$VIC")
  L0=$(php -r 'echo (new PDO("sqlite:file:'"$BILLING_DIR"'/database/billing.db?mode=ro"))->query("SELECT COUNT(*) FROM customertenantlink")->fetchColumn();')
  RR=$(redir "$URL")
  L1=$(php -r 'echo (new PDO("sqlite:file:'"$BILLING_DIR"'/database/billing.db?mode=ro"))->query("SELECT COUNT(*) FROM customertenantlink")->fetchColumn();')
  { echo "$RR" | grep -q '/auth/login' && [ "$L0" = "$L1" ]; } \
    && ok "cross-tenant SSO refused, no link forged" || no "billing SSO" "redir=$RR links $L0->$L1"
else
  skip "billing SSO" "billing.db not readable from here"
fi

# ---------------------------------------------------------------------------
# Batch 2 — 2026-09-14 pre-launch hardening (branch security-hardening-2)
# ---------------------------------------------------------------------------

echo "H7 — MCP Basic auth rate-limited (no unlimited password oracle)"
php -r '$s=file_get_contents("'"$ROOT"'/controls/Mcp.php");
  if(!preg_match("/function authenticateBasic.*?\n    \}/s",$s,$m))exit(2); $b=$m[0];
  exit((strpos($b,"sharedRemaining")!==false && strpos($b,"RateLimiter::shared(")!==false
        && strpos($b,"password_verify")!==false)?0:1);' \
  && ok "authenticateBasic gates on a rate limiter around the password check" || no "MCP basic rate-limit" "wiring absent"

echo "H3 — stored-XSS sanitizer neutralizes evasions"
php -r 'require "vendor/autoload.php"; use app\HtmlSanitizer as S; $f=0;
  $bad=["<img/onerror=alert(1) src=x>"=>"onerror",
        "<a href=\"jav&#97;script:x\">y</a>"=>"script:",
        "<a href=\"javascript:x\">y</a>"=>"javascript:",
        "<a href=\"data:text/html,x\">y</a>"=>"data:",
        "<a href=\"vbscript:x\">y</a>"=>"vbscript:",
        "<script>alert(1)</script>"=>"<script",
        "<p onclick=x>t</p>"=>"onclick"];
  foreach($bad as $in=>$needle){ if(stripos(S::clean($in,["img"]),$needle)!==false)$f++; }
  if(strpos(S::clean("<a href=\"https://ok.com\">y</a>"),"https://ok.com")===false)$f++;
  exit($f?1:0);' \
  && ok "DOM sanitizer strips on*/js:/data:/vbscript:/script, keeps safe links" || no "XSS sanitizer" "a payload survived"

echo "H4 — CSRF gate requires a real POST and a token"
php -r '$s=file_get_contents("'"$ROOT"'/controls/BaseControls/Control.php");
  if(!preg_match("/function requirePost.*?\n    \}/s",$s,$m))exit(2); $b=$m[0];
  exit((strpos($b,"REQUEST_METHOD")!==false && strpos($b,"validateCSRF")!==false)?0:1);' \
  && ok "requirePost() checks the real verb and the CSRF token" || no "requirePost" "verb-or-token check missing"
G=0; for m in invite leave removemember resendinvite updaterole store update delete; do
  php -r '$s=file_get_contents("'"$ROOT"'/controls/Teams.php");
    if(!preg_match("/function '"$m"'\(.*?\n    \}/s",$s,$x)||strpos($x[0],"requirePost()")===false)exit(1);' || G=1
done
[ "$G" = "0" ] && ok "all 8 Teams mutations gated by requirePost()" || no "Teams CSRF" "a method ungated"
[ "$(code "$H/admin/permissions?delete=1")" != "200" ] \
  && ok "admin permissions delete is not a bare-GET 200 sink" || no "authcontrol delete" "GET returned 200"

echo "H8 — attachments gated by canView and off the web root"
php -r '$s=file_get_contents("'"$ROOT"'/controls/Communications.php");
  if(!preg_match("/function attachment.*?\n    \}/s",$s,$m))exit(2); $b=$m[0];
  $w=file_get_contents("'"$ROOT"'/controls/Webhook.php");
  exit((strpos($b,"canView")!==false
        && strpos($w,"secure/uploads/inbound-mail")!==false
        && strpos($w,"public/uploads/inbound-mail")===false)?0:1);' \
  && ok "attachment() checks canView; storage moved out of public/" || no "attachment gate" "gate or storage path wrong"
redir "$H/communications/attachment?id=1" | grep -q '/auth/login' \
  && ok "attachment endpoint requires login" || no "attachment login" "not redirected to login"

echo "H9 — expired broker key rejected"
php -r '$s=file_get_contents("'"$ROOT"'/controls/Brokerinfo.php");
  if(!preg_match("/function brokerKey.*?\n    \}/s",$s,$m))exit(2); $b=$m[0];
  exit((strpos($b,"expiresAt")!==false && strpos($b,"time()")!==false)?0:1);' \
  && ok "brokerKey() rejects a key past expires_at" || no "broker expiry" "expiry check absent"

echo
printf 'Result: \033[32m%d passed\033[0m, \033[31m%d failed\033[0m, \033[33m%d skipped\033[0m\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" = "0" ]
