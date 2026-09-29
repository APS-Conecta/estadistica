#!/usr/bin/env bash
#
# Proves the wiring no unit test can see, against the throwaway instance
# (compose.dev.yaml — `make instance-up` first). farmacia's smoke doctrine:
#
#   - the app page and the admin section answer 200 with the establishment
#     UNSET (the dev instance never sets it — only gestion's phase 16 does),
#     and the admin section carries the Spanish no-establishment notice (a1)
#   - the delegated admin write: a member of a group the section is delegated
#     to gets 200, a user outside it gets 403, and an out-of-range year gets
#     the Spanish 422 — no group name lives in the app (a10, docs/adr/0003)
#   - the REM job is registered by Nextcloud's cron (a18), the ingest state is
#     delegated like every admin action, and the drop door resolves a path in
#     the caller's own Files and refuses a non-zip in Spanish (a3, a4)
#   - the PIV: a FONASA CSV that adds a RUT column is refused whole through
#     the same door, and typing the establishment's figures is delegated:
#     refused in Spanish with no establishment, 403 to an outsider (a10, a11)
#   - the OCS surface answers every user, attribute routes and all: the
#     fuentes list with the PIV from FONASA, a Spanish 400 for a year DEIS
#     never published, a 404 for a fuente that does not exist (a14)
#   - a consulta posted as JSON is answered in the OCS envelope, and a
#     tampered spec or DEIS code is a Spanish 422, never a SQL error (a14, a16)
#   - preguntas and tableros: any user saves one, a second user reads it and
#     is refused a change (403), a delegate of the section and an admin change
#     it, and its creator deletes it (a8, a14)
#   - the icon serves, the .htaccess holes stay shut, the bundle matches src/,
#     and openapi.json regenerates without drift once the OCS surface exists
#
# Output of every command is captured before it is matched, never piped into
# `grep -q` (epidemiologia's SIGPIPE lesson), and every curl carries --max-time.
# A body is matched on ASCII only: Nextcloud's JSON escapes the rest («año»
# arrives as «a\u00f1o», measured on the nextcloud:34 image).
#
# Usage: scripts/smoke.sh     (override with ESTADISTICA_CONTAINER=…)
set -uo pipefail

CONTAINER="${ESTADISTICA_CONTAINER:-estadistica-dev-nextcloud-1}"
APP=estadistica
BASE=http://localhost
RUN="smoke$(date +%s)"
GROUP="estadistica-$RUN"
DELEGATE="delegado-$RUN"
OUTSIDER="ajeno-$RUN"
READER="lector-$RUN"
SETTINGS_CLASS='OCA\Estadistica\Settings\AdminSettings'

# stderr is KEPT (epidemiologia B-003: a container that is down must not read
# as a check that failed for the app's own reasons).
occ() { docker exec -u www-data "$CONTAINER" php occ "$@"; }

# The credential arrives on STDIN as a curl config file, never argv or env —
# both are readable from /proc for the length of the run. $1 user, $2 token.
web_as() {
	local user="$1" token="$2"
	shift 2
	printf 'user = "%s:%s"\n' "$user" "$token" \
		| docker exec -i -u www-data "$CONTAINER" curl -sS --max-time 30 -K - "$@" 2>/dev/null
}

failures=0
pass() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
# $1 haystack, $2 needle, $3 message on success, $4 message on failure.
# A failing check names what it actually got (farmacia 976592d): 200 bytes, one line.
expect() {
	case "$1" in
		*"$2"*) pass "$3" ;;
		*) fail "$4 — got: $(printf '%s' "$1" | head -c 200 | tr '\n' ' ')" ;;
	esac
}
# Status codes are compared whole, never found inside a body: a multi-line
# page whose markup happens to hold "200" must not pass a 200 check.
# $1 actual, $2 expected, $3 message on success, $4 message on failure.
is() {
	if [ "$1" = "$2" ]; then pass "$3"; else fail "$4 — got: $1"; fi
}
# $1 user, $2 token, rest curl args → BODY and CODE, split the way
# gestion/scripts/smoke.sh:44-46 splits them: the trailing status line is cut
# off exactly, so the body is checked for content and the code for equality.
fetch() {
	local out
	out="$(web_as "$@" -w '\n%{http_code}')"
	CODE="${out##*$'\n'}"
	BODY="${out%$'\n'*}"
}

# A throwaway app password; prints "<token> <token id>". tail, not head
# (farmacia's lesson): older runs may have left rows of the same name.
mint() {
	local user="$1" token id
	token="$(occ user:add-app-password "$user" --name="$RUN" -n | tail -1 | tr -d '\r')"
	id="$(occ user:auth-tokens:list "$user" | grep -F "| $RUN " | tail -1 \
		| awk -F'|' '{gsub(/[^0-9]/, "", $2); print $2}')"
	printf '%s %s' "$token" "$id"
}

# Everything this run made is removed however it leaves: what it saved, the
# credentials, the three throwaway users (and with them the file the drop
# check put in Files), the group and its delegation, and the window it moved.
cleanup() {
	# A run that died halfway leaves what it saved: the admin deletes it first.
	[ -n "${TABLERO_ID:-}" ] && web_as admin "$ADMIN_TOKEN" -H 'OCS-APIRequest: true' -o /dev/null \
		-X DELETE "$BASE/ocs/v2.php/apps/$APP/api/v1/tableros/$TABLERO_ID"
	[ -n "${PREGUNTA_ID:-}" ] && web_as admin "$ADMIN_TOKEN" -H 'OCS-APIRequest: true' -o /dev/null \
		-X DELETE "$BASE/ocs/v2.php/apps/$APP/api/v1/preguntas/$PREGUNTA_ID"
	[ -n "${ADMIN_TOKEN_ID:-}" ] && occ user:auth-tokens:delete admin "$ADMIN_TOKEN_ID" >/dev/null 2>&1
	occ admin-delegation:remove "$SETTINGS_CLASS" "$GROUP" >/dev/null 2>&1
	occ user:delete "$DELEGATE" >/dev/null 2>&1
	occ user:delete "$OUTSIDER" >/dev/null 2>&1
	occ user:delete "$READER" >/dev/null 2>&1
	occ group:delete "$GROUP" >/dev/null 2>&1
	occ config:app:delete "$APP" first_year >/dev/null 2>&1
	return 0
}
trap cleanup EXIT

printf '\nEstadística — checking %s\n\n' "$CONTAINER"

occ app:enable "$APP" >/dev/null 2>&1
expect "$(occ app:list --enabled)" " $APP:" \
	"the app is installed and enabled" \
	"the app does not show as enabled"

read -r ADMIN_TOKEN ADMIN_TOKEN_ID <<< "$(mint admin)"
if [ -z "${ADMIN_TOKEN:-}" ]; then
	fail "could not create an app password for admin"
	printf '\n%d check(s) failed\n\n' "$failures"
	exit 1
fi

# --- an unconfigured install: no page crashes, the section says why -------
# The dev instance never sets the establishment keys (phase 16 is gestion's),
# so this is exactly the fresh-install case a1 names. Deleted first anyway, so
# a key left by a manual test cannot turn this into a check of something else.
for key in deis_code establishment_type comuna_cut; do
	occ config:app:delete "$APP" "$key" >/dev/null 2>&1
done

is "$(web_as admin "$ADMIN_TOKEN" -o /dev/null -w '%{http_code}' "$BASE/index.php/apps/$APP/")" '200' \
	"the app page answers 200 with no establishment configured" \
	"the app page does NOT answer 200 with no establishment configured"

fetch admin "$ADMIN_TOKEN" "$BASE/index.php/settings/admin/$APP"
is "$CODE" '200' \
	"the admin section answers 200 with no establishment configured" \
	"the admin section does NOT answer 200 with no establishment configured"
expect "$BODY" 'Todavía no se ha configurado el establecimiento' \
	"the admin section carries the Spanish no-establishment notice" \
	"the admin section does NOT carry the no-establishment notice"

# --- delegation: declared by attribute, enforced by core --------------------
PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -d '/+=')Aa1!"
occ group:add "$GROUP" >/dev/null
docker exec -e OC_PASS="$PASSWORD" -u www-data "$CONTAINER" php occ user:add --password-from-env "$DELEGATE" >/dev/null
docker exec -e OC_PASS="$PASSWORD" -u www-data "$CONTAINER" php occ user:add --password-from-env "$OUTSIDER" >/dev/null
occ group:adduser "$GROUP" "$DELEGATE" >/dev/null
delegated="$(occ admin-delegation:add "$SETTINGS_CLASS" "$GROUP" 2>&1)"
case "$delegated" in
	*rror*|*xception*) fail "occ admin-delegation:add refused the section — got: $(printf '%s' "$delegated" | head -c 200)" ;;
	*) pass "the section is delegable (occ admin-delegation:add accepted AdminSettings)" ;;
esac

read -r DELEGATE_TOKEN _ <<< "$(mint "$DELEGATE")"
read -r OUTSIDER_TOKEN _ <<< "$(mint "$OUTSIDER")"
WINDOW="$BASE/index.php/apps/$APP/admin/window"
YEAR="$(date -u +%Y)"
# OCS-APIRequest: true stands in for the CSRF token on any controller
# (Request::passesCSRFCheck); no Accept: html, so a refusal answers JSON.
OCSH='OCS-APIRequest: true'

is "$(web_as "$DELEGATE" "$DELEGATE_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' \
	-X PUT -d "firstYear=$((YEAR - 3))" "$WINDOW")" '200' \
	"a member of the delegated group saves the window (200)" \
	"a member of the delegated group was NOT let through — the delegation is not wired"

is "$(web_as "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' \
	-X PUT -d "firstYear=$((YEAR - 3))" "$WINDOW")" '403' \
	"a user outside the delegated group is refused (403)" \
	"a user outside the delegated group was NOT refused with 403"

fetch "$DELEGATE" "$DELEGATE_TOKEN" -H "$OCSH" -X PUT -d 'firstYear=1990' "$WINDOW"
is "$CODE" '422' \
	"a year before 2009 is refused with 422" \
	"a year before 2009 was NOT refused with 422"
expect "$BODY" 'debe estar entre 2009' \
	"the refusal is the Spanish sentence" \
	"the refusal does NOT carry the Spanish sentence"

# --- the REM ingest: the job, the state, the drop door ----------------------
# The job is Nextcloud's to schedule, from info.xml — never the host's (a18).
expect "$(occ background-job:list --class='OCA\Estadistica\BackgroundJob\RemJob' 2>&1)" 'RemJob' \
	"the REM job is registered with Nextcloud's cron from info.xml" \
	"the REM job is NOT registered — check <background-jobs> in info.xml"

INGESTS="$BASE/index.php/apps/$APP/admin/ingests"
DROP="$BASE/index.php/apps/$APP/admin/drop"

fetch "$DELEGATE" "$DELEGATE_TOKEN" -H "$OCSH" "$INGESTS"
is "$CODE" '200' \
	"a delegate reads the ingest state (200)" \
	"a delegate could NOT read the ingest state"
expect "$BODY" '"ingests"' \
	"the ingest state is the list the section renders" \
	"the ingest state does NOT carry the ingests list"

is "$(web_as "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' "$INGESTS")" '403' \
	"a user outside the delegated group cannot read the ingest state (403)" \
	"a user outside the delegated group was NOT refused the ingest state with 403"

# The drop door, as the FilePicker drives it: a file in the delegate's OWN
# Files, handed over by path. A text file must come back as a Spanish 422
# from the zip check, which proves the path resolved in the caller's tree.
is "$(web_as "$DELEGATE" "$DELEGATE_TOKEN" -o /dev/null -w '%{http_code}' \
	-X PUT --data-binary 'esto no es un zip' "$BASE/remote.php/dav/files/$DELEGATE/nota.txt")" '201' \
	"a file lands in the delegate's Files (WebDAV 201)" \
	"could NOT put a file in the delegate's Files"

fetch "$DELEGATE" "$DELEGATE_TOKEN" -H "$OCSH" -X POST -d 'path=/nota.txt' "$DROP"
is "$CODE" '422' \
	"a dropped file that is not a zip is refused with 422" \
	"a dropped file that is not a zip was NOT refused with 422"
expect "$BODY" 'no es un ZIP' \
	"the drop refusal is the Spanish sentence" \
	"the drop refusal does NOT carry the Spanish sentence"

is "$(web_as "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' \
	-X POST -d 'path=/nota.txt' "$DROP")" '403' \
	"a user outside the delegated group cannot drop a file (403)" \
	"a user outside the delegated group was NOT refused the drop with 403"

# --- the PIV: FONASA's export through the same door (a10, a11) ---------------
# A CSV that adds a RUT to FONASA's eleven columns is refused whole: the header
# lock is what keeps patients' data out (D5). Nothing is stored, so there is
# nothing to clean up. The dev instance has no establishment, so a delegate's
# typed figures are refused in Spanish, and an outsider gets core's 403.
PIV_HEADER='"PERIODO","SERVICIO_SALUD","REGIÓN","COMUNA","COD_CENTRO","NOMBRE_CENTRO","DEPENDENCIA_ADMINISTRATIVA","TRAMO_FONASA","EDAD_TRAMO","SEXO","TOTAL_INSCRITOS","RUT"'
is "$(web_as "$DELEGATE" "$DELEGATE_TOKEN" -o /dev/null -w '%{http_code}' \
	-X PUT --data-binary "$PIV_HEADER" "$BASE/remote.php/dav/files/$DELEGATE/inscritos.csv")" '201' \
	"a CSV lands in the delegate's Files (WebDAV 201)" \
	"could NOT put the CSV in the delegate's Files"

fetch "$DELEGATE" "$DELEGATE_TOKEN" -H "$OCSH" -X POST -d 'path=/inscritos.csv' "$DROP"
is "$CODE" '422' \
	"a FONASA CSV with a RUT column is refused with 422" \
	"a FONASA CSV with a RUT column was NOT refused with 422"
expect "$BODY" 'Sobra: RUT' \
	"the refusal names the column that is not FONASA's" \
	"the PIV refusal does NOT name the RUT column"

PIV="$BASE/index.php/apps/$APP/admin/piv"
CELLS="{\"year\":$((YEAR - 1)),\"cells\":[{\"edad\":0,\"sexo\":\"M\",\"inscritos\":1}]}"

fetch "$DELEGATE" "$DELEGATE_TOKEN" -H "$OCSH" -H 'Content-Type: application/json' -X PUT --data "$CELLS" "$PIV"
is "$CODE" '422' \
	"a delegate's typed PIV is refused with 422 while no establishment is configured" \
	"a typed PIV with no establishment configured was NOT refused with 422"
expect "$BODY" 'no se ha configurado el establecimiento' \
	"the refusal is the Spanish sentence" \
	"the typed-PIV refusal does NOT carry the Spanish sentence"

is "$(web_as "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H 'Content-Type: application/json' -o /dev/null -w '%{http_code}' \
	-X PUT --data "$CELLS" "$PIV")" '403' \
	"a user outside the delegated group cannot type PIV figures (403)" \
	"a user outside the delegated group was NOT refused the PIV with 403"

# --- the OCS surface: every user reads the fuentes (a14) ----------------------
# The outsider is in no delegated group: reading is everyone's (D6). The routes
# exist only if ApiController's attributes registered them, which is the
# wiring no unit test can see.
FUENTES="$BASE/ocs/v2.php/apps/$APP/api/v1/fuentes"
JSONH='Accept: application/json'

fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" "$FUENTES"
is "$CODE" '200' \
	"any user reads the fuentes over OCS (200)" \
	"the fuentes did NOT answer 200 to a plain user — is ApiController routed?"
expect "$BODY" '"status":"ok"' \
	"the fuentes answer in the OCS envelope with status ok" \
	"the fuentes answer does NOT carry status ok"
expect "$BODY" '"id":"establecimientos"' \
	"the fuentes list REM and the establishments" \
	"the fuentes list does NOT carry the establishments"
expect "$BODY" '"id":"piv"' \
	"the fuentes list the PIV" \
	"the fuentes list does NOT carry the PIV"
expect "$BODY" '"origen":"FONASA"' \
	"the PIV names FONASA as its origen" \
	"the PIV does NOT name FONASA as its origen"

fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" "$FUENTES/rem-a?anio=1990"
is "$CODE" '400' \
	"a year DEIS never published is refused with 400" \
	"a year DEIS never published was NOT refused with 400"
expect "$BODY" 'debe estar entre 2009' \
	"the 400 is the Spanish sentence" \
	"the 400 does NOT carry the Spanish sentence"

is "$(web_as "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" -o /dev/null -w '%{http_code}' "$FUENTES/nada")" '404' \
	"a fuente that does not exist is 404" \
	"a fuente that does not exist was NOT 404"

# --- consultas: one spec, answered or refused in Spanish (a14, a16) ----------
# Posted as JSON, the way a suite app or M3 sends it. The spec reaches the
# controller only if the attribute registered the route AND Nextcloud mapped
# the JSON object onto its array parameter: the wiring no unit test can see.
# The dev instance holds no establishment and maybe no REM, so the local
# series is null and the national one may be empty; both are answers.
CONSULTAS="$BASE/ocs/v2.php/apps/$APP/api/v1/consultas"
CTYPE='Content-Type: application/json'
SPEC="{\"fuente\":\"rem-a\",\"periodo\":{\"desde\":\"$YEAR-01\",\"hasta\":\"$YEAR-01\"},\"filtros\":{\"codigos\":[\"02010420\"],\"columnas\":[\"col01\"]},\"comparar\":{\"nacional\":true}}"

fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -X POST --data "{\"consulta\":$SPEC}" "$CONSULTAS"
is "$CODE" '200' \
	"any user's consulta is answered (200)" \
	"a consulta was NOT answered with 200 — is the route registered, and the JSON body mapped?"
expect "$BODY" '"status":"ok"' \
	"the consulta answers in the OCS envelope with status ok" \
	"the consulta answer does NOT carry status ok"
expect "$BODY" '"local":null,"nacional":[' \
	"the series are local (none configured) and nacional" \
	"the consulta answer does NOT carry the local and nacional series"

for tampering in '"sql":"DROP TABLE x"|no admite' '"metrica":"max"|no existe'; do
	key="${tampering%%|*}"
	sentence="${tampering#*|}"
	fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -X POST \
		--data "{\"consulta\":${SPEC%\}},$key}}" "$CONSULTAS"
	is "$CODE" '422' \
		"a tampered spec ($key) is refused with 422" \
		"a tampered spec ($key) was NOT refused with 422"
	expect "$BODY" "$sentence" \
		"the refusal is the Spanish sentence, not a SQL error" \
		"the refusal does NOT carry the Spanish sentence"
done

fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -X POST \
	--data "{\"consulta\":$SPEC,\"establecimiento\":\"12\"}" "$CONSULTAS"
is "$CODE" '422' \
	"a DEIS code that is not one is refused with 422" \
	"a DEIS code that is not one was NOT refused with 422"
expect "$BODY" 'se esperan seis' \
	"the DEIS code refusal is the Spanish sentence" \
	"the DEIS code refusal does NOT carry the Spanish sentence"

# --- preguntas y tableros: everyone reads, the creator or an administrator changes (a8, a14)
# The outsider saves; the reader, in no delegated group, reads and is refused a
# change; the delegate moderates, which only a real instance can prove (core
# answers «may this person administer Estadística» from its own delegations,
# owner decision, Slice 7); the admin and the creator delete. The sentences
# are matched on their ASCII part: Nextcloud escapes «creó».
docker exec -e OC_PASS="$PASSWORD" -u www-data "$CONTAINER" php occ user:add --password-from-env "$READER" >/dev/null
read -r READER_TOKEN _ <<< "$(mint "$READER")"
PREGUNTAS="$BASE/ocs/v2.php/apps/$APP/api/v1/preguntas"
TABLEROS="$BASE/ocs/v2.php/apps/$APP/api/v1/tableros"
# The id from `"data":{"id":N,` — the first key of a pregunta and a tablero.
id_of() { printf '%s' "$1" | sed -n 's/.*"data":{"id":\([0-9][0-9]*\),.*/\1/p'; }

fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -X POST \
	--data "{\"titulo\":\"Smoke $RUN\",\"consulta\":$SPEC,\"tipo\":\"linea\"}" "$PREGUNTAS"
is "$CODE" '201' \
	"any user saves a pregunta, posted as JSON (201)" \
	"a pregunta was NOT saved with 201 — is the route registered, and the JSON body mapped?"
expect "$BODY" '"editable":true' \
	"its creator may change it" \
	"the saved pregunta does NOT say its creator may change it"
PREGUNTA_ID="$(id_of "$BODY")"

fetch "$READER" "$READER_TOKEN" -H "$OCSH" -H "$JSONH" "$PREGUNTAS/$PREGUNTA_ID"
is "$CODE" '200' \
	"a second user reads it (200)" \
	"a second user could NOT read the pregunta"
expect "$BODY" '"editable":false' \
	"and may not change it" \
	"the pregunta does NOT tell a second user they may not change it"

fetch "$READER" "$READER_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -X PUT --data '{"titulo":"Otro"}' "$PREGUNTAS/$PREGUNTA_ID"
is "$CODE" '403' \
	"the second user's edit is refused (403)" \
	"the second user's edit was NOT refused with 403"
expect "$BODY" 'Solo quien cre' \
	"the refusal is the Spanish sentence" \
	"the 403 does NOT carry the Spanish sentence"

is "$(web_as "$DELEGATE" "$DELEGATE_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -o /dev/null -w '%{http_code}' \
	-X PUT --data '{"tipo":"tabla"}' "$PREGUNTAS/$PREGUNTA_ID")" '200' \
	"a delegate of the section changes someone else's pregunta (200)" \
	"a delegate of the section was NOT let change the pregunta"

fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -X POST \
	--data "{\"titulo\":\"Smoke $RUN\",\"consulta\":${SPEC%\}},\"sql\":\"x\"}}" "$PREGUNTAS"
is "$CODE" '422' \
	"a pregunta whose consulta is tampered with is refused (422)" \
	"a tampered pregunta was NOT refused with 422"
expect "$BODY" 'no admite' \
	"the refusal is QuerySpec's Spanish sentence" \
	"the tampered pregunta's refusal does NOT carry the Spanish sentence"

fetch "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -H "$JSONH" -H "$CTYPE" -X POST \
	--data "{\"titulo\":\"Smoke $RUN\",\"tarjetas\":[{\"pregunta\":$PREGUNTA_ID,\"ancho\":2}]}" "$TABLEROS"
is "$CODE" '201' \
	"any user saves a tablero with the pregunta on it (201)" \
	"a tablero was NOT saved with 201"
TABLERO_ID="$(id_of "$BODY")"

fetch "$READER" "$READER_TOKEN" -H "$OCSH" -H "$JSONH" "$TABLEROS/$TABLERO_ID"
expect "$BODY" "\"tarjetas\":[{\"pregunta\":$PREGUNTA_ID,\"ancho\":2}]" \
	"a second user reads the tablero and its card" \
	"a second user does NOT read the tablero's card"

is "$(web_as "$READER" "$READER_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' -X DELETE "$TABLEROS/$TABLERO_ID")" '403' \
	"the second user's delete is refused (403)" \
	"the second user's delete was NOT refused with 403"

is "$(web_as admin "$ADMIN_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' -X DELETE "$TABLEROS/$TABLERO_ID")" '200' \
	"an admin deletes someone else's tablero (200)" \
	"an admin could NOT delete the tablero"
TABLERO_ID=''

is "$(web_as "$OUTSIDER" "$OUTSIDER_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' -X DELETE "$PREGUNTAS/$PREGUNTA_ID")" '200' \
	"the creator deletes their pregunta (200)" \
	"the creator could NOT delete their pregunta"
is "$(web_as "$READER" "$READER_TOKEN" -H "$OCSH" -o /dev/null -w '%{http_code}' "$PREGUNTAS/$PREGUNTA_ID")" '404' \
	"a deleted pregunta is 404" \
	"a deleted pregunta is NOT 404"
PREGUNTA_ID=''

# --- the icon ----------------------------------------------------------------
icon="$(web_as admin "$ADMIN_TOKEN" -o /dev/null -w '%{http_code} %{content_type} %{size_download}' \
	"$BASE/custom_apps/$APP/img/app.svg")"
case "$icon" in
	'200 image/svg+xml'*) pass "the app icon is served ($icon)" ;;
	*) fail "the icon is not served ($icon) — the menu would show the generic placeholder" ;;
esac

# --- what Apache hands to ANYONE --------------------------------------------
# Measured with no credentials at all: an authenticated probe would pass while
# the hole was open (epidemiologia's lesson).
for path in package.json openapi.json src/main.js scripts/smoke.sh lib/AppInfo/Application.php; do
	code="$(docker exec -u www-data "$CONTAINER" \
		curl -sS --max-time 30 -o /dev/null -w '%{http_code}' "$BASE/custom_apps/$APP/$path")"
	case "$code" in
		404|403) pass "$path is not served to anonymous callers" ;;
		000) fail "$path did not answer within 30 s — that is the instance, not the .htaccess" ;;
		*) fail "$path answers $code with NO credentials — check .htaccess" ;;
	esac
done

# --- the bundle is the deployment --------------------------------------------
# CI runs the same assertion and does NOT skip; this copy is the local answer.
if command -v npm >/dev/null 2>&1 && [ -d node_modules ]; then
	npm run build --silent >/dev/null 2>&1
	drift="$(git status --porcelain js/ 2>/dev/null)"
	if [ -z "$drift" ]; then
		pass "the js/ bundle matches src/"
	else
		fail "js/ is behind src/ — run 'npm run build' and commit the result"
		printf '%s\n' "$drift" | sed 's/^/        /'
	fi
else
	printf '  --    no node_modules: not checking whether the bundle is current\n'
fi

# --- the contract is generated, not hand-edited ------------------------------
# openapi.json is born with the OCS surface (Slice 4); from then on
# MetadataTest and ApiContractTest read it, so a deleted spec fails there
# instead of skipping here. A failed regeneration is not a clean tree.
if [ ! -f openapi.json ]; then
	printf '  --    no OCS surface yet: openapi.json arrives with ApiController\n'
elif scripts/openapi.sh >/dev/null 2>&1; then
	drift="$(git status --porcelain openapi.json 2>/dev/null)"
	if [ -z "$drift" ]; then
		pass "openapi.json regenerates without drift"
	else
		fail "openapi.json drifted from ApiController — run scripts/openapi.sh and commit the result"
		printf '%s\n' "$drift" | sed 's/^/        /'
	fi
else
	fail "scripts/openapi.sh did not run — the extractor needs the instance up (and, on a first run, the network)"
fi

if [ "$failures" -eq 0 ]; then
	printf '\nAll good.\n\n'
else
	printf '\n%d check(s) failed\n\n' "$failures"
	exit 1
fi
