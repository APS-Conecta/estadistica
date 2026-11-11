#!/usr/bin/env python3
"""Drive the running app the way a person would, and say what actually happened.

    make instance-up && npm run build && make test-browser

territorio's browser check, the org's pattern: what only a browser can answer.
Here that is acceptance a15. A tablero card and the OCS API must give the same
local and national figures for the same pregunta, because a card that drew
something else would look exactly as right. So the check reads the figures
off the card's table and compares them with POST consultas, called with an
app password.

It also checks a8 in a browser: a second user, who is no admin and in no
delegated group, opens the same tablero and sees the same figures with no
controls to change it.

And the Resumen (docs/adr/0009), the view the app opens on: each card shows
the figure, or the state, GET resumen answers, its figures are GET
indicadores' local series for the card's month, a keyboard reaches every
card, and the cards fit a phone's width. The metas view still answers its
own year's figures.

And the shell (#84), in Chromium and in Firefox under the CSP Nextcloud
serves: every view opens from the navigation, marked as the open one,
with its title beside the toggle and its own chunk fetched; «Ajustes de
Estadística» takes an admin to the admin section and is not offered to the
second user; and on a phone the navigation folds away. No page logs a
console error or a CSP violation.

It makes everything it needs and removes it all however it ends:
- a few synthetic REM rows under a código of its own. They sit in 2009, the
  window's floor: outside the default window the REM job neither loads that
  year (which would replace its rows) nor forgets it (which reads the ingest
  state these rows have none of);
- for the metas, four rows in 2026 under the 2026 seed's Meta VI códigos,
  for the fabricated establishment only;
- the establishment code, set to a fabricated one and put back after;
- a pregunta, a tablero, an app password and a throwaway user.

Screenshots land in tools/.browser-check/ for a person to look at afterwards.

ponytail: one script, no framework, no page objects. It grows a function per
slice that has a screen; when that stops fitting, it wants a real suite.
"""

import base64
import json
import os
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

from playwright.sync_api import TimeoutError as PlaywrightTimeout
from playwright.sync_api import sync_playwright

BASE = os.environ.get("ESTADISTICA_URL", "http://localhost:8092")
CONTAINER = os.environ.get("ESTADISTICA_CONTAINER", "estadistica-dev-nextcloud-1")
DB_CONTAINER = os.environ.get("ESTADISTICA_DB_CONTAINER", "estadistica-dev-db-1")
ADMIN, ADMIN_PASSWORD = "admin", "admin"
SHOTS = Path(__file__).parent / ".browser-check"

RUN = uuid.uuid4().hex[:6]
YEAR = 2009
CODE = f"BC{RUN}"
READER = f"lector-{RUN}"
READER_PASSWORD = secrets.token_urlsafe(18) + "Aa1!"

# The local establishment and two others, all fabricated (acceptance a2).
LOCAL = "900001"
# (estab, mes, col01): the third establishment reports January only, so
# February's national promedio divides by the two that reported.
ROWS = [(900001, 1, 4), (900001, 2, 6), (900002, 1, 3), (900002, 2, 5), (900003, 1, 2)]

# The metas rows sit in 2026, the year whose shipped seed names their códigos:
METAS_YEAR = 2026  # the shipped 2026 seed's Meta VI: A0200002 over A0200001, col06
# (código, mes, col06): 5 de 8 by February.
METAS_ROWS = [("A0200002", 1, 3), ("A0200001", 1, 4), ("A0200002", 2, 2), ("A0200001", 2, 4)]

SPEC = {
    "fuente": "rem-a",
    "periodo": {"desde": f"{YEAR}-01", "hasta": f"{YEAR}-02"},
    "filtros": {"codigos": [CODE], "columnas": ["col01"]},
    "agrupar": ["mes"],
    "metrica": "promedio_establecimiento",
    "comparar": {"nacional": True},
}

# The navigation's views, in its order: the chunk each fetches when it first
# opens, and what it shows once it has its figures.
VIEWS = [
    ("Resumen", "resumen", ".estadistica-resumen__meta"),
    ("Metas Sanitarias", "metas", ".estadistica-goals h2"),
    # Its steps, or «Todavía no hay cifras REM» on an instance that has loaded none.
    ("Consultar", "consultar", ".estadistica-builder :is(.estadistica-builder__steps, .empty-content)"),
    ("Preguntas", "preguntas", f".estadistica-questions__item strong:text-is('Navegador {RUN}')"),
    ("Tableros", "tableros", f".estadistica-dashboards__list button:has-text('Tablero navegador {RUN}')"),
]
SETTINGS = "Ajustes de Estadística"

MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto",
          "septiembre", "octubre", "noviembre", "diciembre"]


def occ(*args, env=None, check=True):
    command = ["docker", "exec", "-u", "www-data"]
    for key, value in (env or {}).items():
        command += ["-e", f"{key}={value}"]
    result = subprocess.run([*command, CONTAINER, "php", "occ", *args], capture_output=True, text=True, timeout=120)
    if check and result.returncode != 0:
        raise RuntimeError(f"occ {' '.join(args)} failed: {result.stdout}{result.stderr}")
    return result


def psql(sql):
    subprocess.run(["docker", "exec", "-i", DB_CONTAINER, "psql", "-v", "ON_ERROR_STOP=1", "-q",
                    "-U", "nextcloud", "-d", "nextcloud"], input=sql, text=True, check=True, timeout=60)


def ocs(method, path, token, body=None):
    """One OCS call as ADMIN with an app password; the payload inside the envelope."""
    request = urllib.request.Request(
        f"{BASE}/ocs/v2.php/apps/estadistica/api/v1/{path}",
        method=method,
        data=None if body is None else json.dumps(body).encode(),
        headers={
            "Authorization": "Basic " + base64.b64encode(f"{ADMIN}:{token}".encode()).decode(),
            "OCS-APIRequest": "true",
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)["ocs"]["data"]


def es_cl(value):
    """formatValue (src/charts.js): es-CL, at most one decimal. The figures here stay under 1000."""
    text = f"{round(float(value), 1):.1f}"
    return text[:-2] if text.endswith(".0") else text.replace(".", ",")


def month_name(year_month):
    year, month = year_month.split("-")
    return f"{MONTHS[int(month) - 1]} de {year}"


def dismiss_first_run_wizard():
    """Nextcloud's welcome modal swallows every click on a fresh instance (territorio's lesson)."""
    for args in (["app:disable", "firstrunwizard"], ["user:setting", ADMIN, "firstrunwizard", "show", "0"]):
        occ(*args, check=False)


def log_in(page, user, password):
    page.goto(f"{BASE}/index.php/apps/estadistica/", wait_until="domcontentloaded")
    if "/login" in page.url:
        page.wait_for_selector("#user", timeout=30_000)
        page.fill("#user", user)
        page.fill("#password", password)
        page.click('button[type="submit"]')
        page.wait_for_url(f"{BASE}/index.php/apps/estadistica/**", timeout=30_000)


# Nextcloud's own noise on a fresh instance, not the app's: the header asks for
# the person's user status before one exists, gets a 404, and logs it twice
# (measured on nextcloud:34.0.4). Matched by where it came from, so a 404 of
# the app's own still fails the check.
CORE_NOISE = ("/ocs/v2.php/apps/user_status/api/", "Failed to load user status")

# What was let through as CORE_NOISE, said at the end: an ignored line is never silent.
IGNORED = []


def console_errors(page):
    """Everything logged as an error, with where it came from; stacks, not just messages (territorio's).
    And every CSP violation: the page reports each one through a listener added before its own scripts."""
    found = []
    page.expose_function("reportViolation", lambda text: found.append(f"CSP violation: {text}"))
    page.add_init_script("document.addEventListener('securitypolicyviolation', (e) => window.reportViolation("
                         "`${e.effectiveDirective} blocked ${e.blockedURI} [${e.sourceFile}:${e.lineNumber}]`))")

    def console(message):
        text = f"{message.text} [{message.location.get('url', '')}]"
        if message.type != "error":
            return
        if any(noise in text for noise in CORE_NOISE):
            IGNORED.append(text)
        else:
            found.append(text)

    page.on("console", console)
    page.on("pageerror", lambda e: found.append((e.stack or str(e))[:2000]))
    return found


def chunks_fetched(page):
    """The names of the app's js/ files the page fetched and got."""
    fetched = set()
    page.on("response", lambda r: fetched.add(r.url.split("/js/")[-1].split("?")[0])
            if "/estadistica/js/" in r.url and r.ok else None)
    return fetched


def navigation_entry(page, name):
    return page.locator(".app-navigation").get_by_role("link", name=name, exact=True)


def title_clears_the_toggle(page, where):
    """The view's title sits beside NcAppNavigation's toggle, not under it."""
    toggle = page.locator("button.app-navigation-toggle").bounding_box()
    title = page.locator(".estadistica-heading h2").bounding_box()
    overlaps = (toggle["x"] < title["x"] + title["width"] and title["x"] < toggle["x"] + toggle["width"]
                and toggle["y"] < title["y"] + title["height"] and title["y"] < toggle["y"] + toggle["height"])
    return [f"{where}: the title sits under the navigation toggle"] if overlaps else []


def walk_the_navigation(page, fetched, browser):
    """Every view from the navigation, as a person clicks through, then «Ajustes de Estadística»."""
    failures = []
    for name, chunk, shown in VIEWS:
        navigation_entry(page, name).click()
        try:
            page.locator(shown).first.wait_for(timeout=30_000)
        except PlaywrightTimeout:
            failures.append(f"{browser}: {name} opened from the navigation never showed {shown}")
        page.screenshot(path=str(SHOTS / f"{browser}-{chunk}.png"))
        if navigation_entry(page, name).get_attribute("aria-current") != "page":
            failures.append(f"{browser}: the navigation does not mark {name} as the open view")
        if page.locator(".estadistica-heading h2").inner_text().strip() != name:
            failures.append(f"{browser}: {name}'s title reads {page.locator('.estadistica-heading h2').inner_text()!r}")
        if f"estadistica-{chunk}.js" not in fetched:
            failures.append(f"{browser}: {name} opened without fetching its chunk estadistica-{chunk}.js")
    failures += title_clears_the_toggle(page, browser)

    navigation_entry(page, SETTINGS).click()
    try:
        page.wait_for_url("**/settings/admin/estadistica", timeout=30_000)
        page.locator("#estadistica-establishment").wait_for(timeout=30_000)
    except PlaywrightTimeout:
        failures.append(f"{browser}: «{SETTINGS}» did not open the admin section (at {page.url})")
    return failures


def open_the_tablero(page, title):
    """Tableros → the tablero → its one card's table, as a person clicks through."""
    navigation_entry(page, "Tableros").click()
    page.get_by_role("button", name=title, exact=True).click()
    rows = page.locator(".estadistica-card .estadistica-answer__table tbody tr")
    rows.first.wait_for(timeout=30_000)
    return rows


def card_figures(rows):
    """{month: (local, nacional)} as the card's table shows them; a series' informantes stay out."""
    figures = {}
    for i in range(rows.count()):
        row = rows.nth(i)
        cells = [row.locator("td").nth(j).inner_text().split(" (")[0].strip() for j in range(row.locator("td").count())]
        figures[row.locator("th").first.inner_text().strip()] = tuple(cells)
    return figures


def api_figures(answer):
    """The same, from POST consultas: month → (local, nacional), formatted as the card formats them."""
    local = {point["grupo"][0]: point["valor"] for point in answer["series"]["local"]}
    nacional = {point["grupo"][0]: point["valor"] for point in answer["series"]["nacional"]}
    return {month_name(month): (es_cl(local[month]) if month in local else "—", es_cl(value))
            for month, value in sorted(nacional.items())}


def resumen_figures(page):
    """{card title: (percentage, «N de D», state)} as the Resumen's cards show them; None where a card has none."""
    cards = page.locator(".estadistica-resumen__meta")
    cards.first.wait_for(timeout=30_000)
    figures = {}
    for i in range(cards.count()):
        card = cards.nth(i)
        shown = card.locator(".estadistica-resumen__porcentaje")
        state = card.locator(".estadistica-resumen__estado")
        figures[card.locator("h3").inner_text().strip()] = (
            (shown.inner_text().strip(), card.locator(".estadistica-resumen__cuenta").inner_text().strip())
            if shown.count() else (None, None)) + (state.inner_text().strip() if state.count() else None,)
    return figures


def resumen_state(meta):
    """The line a card shows instead of a figure (Resumen.vue), or None when it shows one."""
    if meta["estado"]:
        return f"Sin porcentaje: {meta['estado']}."
    if meta["manual"]:
        return ("Todavía no se ha informado su cumplimiento." if meta["porcentaje"] is None
                else f"Cumplimiento informado: {es_cl(meta['porcentaje'])} %")
    return None if meta["mes"] is not None else "Sin informar"


def resumen_api_figures(answer):
    """The same, from GET resumen, formatted as the cards format them."""
    return {f"Meta {meta['codigo']} {answer['anio']}": (
        (f"{es_cl(meta['porcentaje'])} %", f"{es_cl(meta['numerador'])} de {es_cl(meta['denominador'])}")
        if meta["mes"] is not None else (None, None)) + (resumen_state(meta),) for meta in answer["metas"]}


def resumen_against_indicadores(resumen, indicadores):
    """Each card's N, D and % are GET indicadores' local series for its month; a card with no
    month is a manual meta, one waiting for its ruta oficial, or one whose local series has no
    porcentaje (AC 2)."""
    series = {meta["codigo"]: {mes["mes"]: mes for mes in meta["meses"]} for meta in indicadores["indicadores"]}
    failures = []
    for card in resumen["metas"]:
        meses = series.get(card["codigo"], {})
        if card["mes"] is not None:
            local = meses.get(card["mes"], {})
            shown = {key: card[key] for key in ("numerador", "denominador", "porcentaje")}
            expected = {key: local.get(key) for key in shown}
            if shown != expected:
                failures.append(f"Meta {card['codigo']}'s card shows {shown} for {card['mes']}, GET indicadores' local series {expected}")
        elif not card["manual"] and card["estado"] is None and any(mes["porcentaje"] is not None for mes in meses.values()):
            failures.append(f"Meta {card['codigo']}'s card has no month, while GET indicadores' local series has a porcentaje")
    return failures


def keyboard_reaches(page, count):
    """The card titles a keyboard focuses, tabbing from the top of the page."""
    reached = []
    for _ in range(80 + count):
        page.keyboard.press("Tab")
        title = page.evaluate("() => document.activeElement.matches('.estadistica-resumen__meta') ? document.activeElement.querySelector('h3').innerText.trim() : null")
        if title and title not in reached:
            reached.append(title)
        if len(reached) == count:
            break
    return reached


def main():
    SHOTS.mkdir(exist_ok=True)
    failures = []
    made = {}
    previous_code = occ("config:app:get", "estadistica", "deis_code", check=False)

    try:
        dismiss_first_run_wizard()
        psql("INSERT INTO oc_estadistica_rem (serie, anio, mes, estab, codigo, col01) VALUES "
             + ", ".join(f"('A', {YEAR}, {mes}, {estab}, '{CODE}', {valor})" for estab, mes, valor in ROWS) + ";")
        made["rows"] = True
        occ("config:app:set", "estadistica", "deis_code", f"--value={LOCAL}")

        token = occ("user:add-app-password", ADMIN, f"--name=browser-{RUN}", "-n").stdout.strip().splitlines()[-1]
        made["token"] = True

        psql("INSERT INTO oc_estadistica_rem (serie, anio, mes, estab, codigo, col06) VALUES "
             + ", ".join(f"('A', {METAS_YEAR}, {mes}, {LOCAL}, '{codigo}', {valor})" for codigo, mes, valor in METAS_ROWS) + ";")
        made["metas"] = True

        # App config values reach the web workers within seconds, not at once:
        # ask until the local series is the one just configured.
        deadline = time.monotonic() + 30
        answer = ocs("POST", "consultas", token, {"consulta": SPEC})
        while answer["etiquetas"]["local"] != LOCAL and time.monotonic() < deadline:
            time.sleep(1)
            answer = ocs("POST", "consultas", token, {"consulta": SPEC})
        if answer["etiquetas"]["local"] != LOCAL:
            failures.append(f"the web workers never saw deis_code {LOCAL}: the local series is {answer['etiquetas']['local']!r}")

        pregunta = ocs("POST", "preguntas", token, {"titulo": f"Navegador {RUN}", "consulta": SPEC, "tipo": "barra"})
        made["pregunta"] = pregunta["id"]
        tablero = ocs("POST", "tableros", token, {"titulo": f"Tablero navegador {RUN}", "tarjetas": [{"pregunta": pregunta["id"], "ancho": 3}]})
        made["tablero"] = tablero["id"]

        occ("user:add", "--password-from-env", READER, env={"OC_PASS": READER_PASSWORD})
        made["reader"] = True

        expected = api_figures(answer)
        meta_vi = next(meta for meta in ocs("GET", f"indicadores?anio={METAS_YEAR}&pares=", token)["indicadores"] if meta["codigo"] == "VI")
        february = next(mes for mes in meta_vi["meses"] if mes["mes"] == f"{METAS_YEAR}-02")
        if (february["numerador"], february["denominador"], february["porcentaje"]) != (5, 8, 62.5):
            failures.append(f"the metas view did not answer the seeded {METAS_YEAR} figures for Meta VI: {february}")
        resumen_answer = ocs("GET", "resumen", token)
        resumen = resumen_api_figures(resumen_answer)
        failures += resumen_against_indicadores(resumen_answer, ocs("GET", f"indicadores?anio={resumen_answer['anio']}&pares=", token))
        if expected != {"enero de 2009": ("4", "3"), "febrero de 2009": ("6", "5,5")}:
            failures.append(f"the API did not answer the seeded figures: {expected}")

        with sync_playwright() as p:
            browser = p.chromium.launch()

            # a15: the creator's card shows the API's figures.
            page = browser.new_page(viewport={"width": 1400, "height": 900})
            errors = console_errors(page)
            fetched = chunks_fetched(page)
            log_in(page, ADMIN, ADMIN_PASSWORD)

            # docs/adr/0009: the app opens on the Resumen, whose cards show GET resumen's figures.
            shown_resumen = resumen_figures(page)
            page.screenshot(path=str(SHOTS / "resumen.png"), full_page=True)
            if shown_resumen != resumen:
                failures.append(f"the Resumen's cards show {shown_resumen}, the API answers {resumen}")
            reached = keyboard_reaches(page, len(resumen))
            if sorted(reached) != sorted(resumen):
                failures.append(f"a keyboard reaches only these Resumen cards: {reached}")

            rows = open_the_tablero(page, f"Tablero navegador {RUN}")
            shown = card_figures(rows)
            page.screenshot(path=str(SHOTS / "tablero.png"), full_page=True)
            if shown != expected:
                failures.append(f"a15: the card shows {shown}, the API answers {expected}")
            if page.locator(".estadistica-card canvas").count() != 1:
                failures.append("the card drew no chart above its table")
            if page.get_by_role("button", name="Subir").count() == 0:
                failures.append("the creator sees no controls on their own tablero")
            failures += walk_the_navigation(page, fetched, "chromium")
            failures += [f"console error for the creator: {e}" for e in errors]

            # a8: a second user who administers nothing reads the same tablero.
            reader = browser.new_context(viewport={"width": 1400, "height": 900}).new_page()
            reader_errors = console_errors(reader)
            log_in(reader, READER, READER_PASSWORD)
            rows = open_the_tablero(reader, f"Tablero navegador {RUN}")
            if card_figures(rows) != expected:
                failures.append(f"a8: the second user's card shows {card_figures(rows)}, the API answers {expected}")
            if reader.get_by_role("button", name="Subir").count() != 0:
                failures.append("a8: the second user sees controls to change someone else's tablero")
            if navigation_entry(reader, SETTINGS).count() != 0:
                failures.append(f"the second user, no admin, is offered «{SETTINGS}»")
            reader.screenshot(path=str(SHOTS / "tablero-lector.png"), full_page=True)
            failures += [f"console error for the second user: {e}" for e in reader_errors]

            # A phone: the cards stack, and nothing is wider than the screen.
            phone = browser.new_context(viewport={"width": 375, "height": 800}).new_page()
            phone_errors = console_errors(phone)
            log_in(phone, ADMIN, ADMIN_PASSWORD)
            resumen_figures(phone)
            phone.screenshot(path=str(SHOTS / "resumen-telefono.png"), full_page=True)
            if phone.evaluate("() => { const r = document.querySelector('.estadistica-resumen'); return r.scrollWidth > r.clientWidth }"):
                failures.append("the Resumen is wider than a 375 px screen")
            if not phone.locator(".app-navigation").evaluate("(n) => n.classList.contains('app-navigation--closed')"):
                failures.append("on a phone the navigation stays open over the Resumen")
            failures += title_clears_the_toggle(phone, "a phone")
            failures += [f"console error on a phone: {e}" for e in phone_errors]

            browser.close()

            # The shell again in Firefox, whose CSP enforcement and module loading are its own.
            firefox = p.firefox.launch()
            page = firefox.new_page(viewport={"width": 1400, "height": 900})
            firefox_errors = console_errors(page)
            fetched = chunks_fetched(page)
            log_in(page, ADMIN, ADMIN_PASSWORD)
            failures += walk_the_navigation(page, fetched, "firefox")
            failures += [f"console error in Firefox: {e}" for e in firefox_errors]
            firefox.close()
    finally:
        if "tablero" in made:
            ocs("DELETE", f"tableros/{made['tablero']}", token)
        if "pregunta" in made:
            ocs("DELETE", f"preguntas/{made['pregunta']}", token)
        if made.get("token"):
            listed = occ("user:auth-tokens:list", ADMIN, check=False).stdout
            for line in listed.splitlines():
                if f"| browser-{RUN} " in line:
                    occ("user:auth-tokens:delete", ADMIN, line.split("|")[1].strip(), check=False)
        if made.get("reader"):
            occ("user:delete", READER, check=False)
        if made.get("rows"):
            psql(f"DELETE FROM oc_estadistica_rem WHERE codigo = '{CODE}';")
        if made.get("metas"):
            psql(f"DELETE FROM oc_estadistica_rem WHERE estab = {LOCAL} AND anio = {METAS_YEAR} AND codigo IN ('A0200002', 'A0200001');")
        if previous_code.returncode == 0:
            occ("config:app:set", "estadistica", "deis_code", f"--value={previous_code.stdout.strip()}", check=False)
        else:
            occ("config:app:delete", "estadistica", "deis_code", check=False)

    if IGNORED:
        print(f"note  ignored {len(IGNORED)} console error(s) of Nextcloud's own user status: {IGNORED}")
    for failure in failures:
        print(f"FAIL  {failure}")
    if failures:
        print(f"\n{len(failures)} check(s) failed")
        return 1
    print("ok    a15: the tablero card's local and national figures are the API's")
    print("ok    a8: a second user reads the tablero, with no control to change it")
    print("ok    the Resumen opens first, its cards show GET resumen's figures, which are GET indicadores' local months, a keyboard reaches each one, and it fits a phone")
    print(f"ok    Chromium and Firefox open every view from the navigation, each with its chunk, and «{SETTINGS}» opens the admin section for an admin only")
    print("ok    the title sits beside the navigation toggle, which folds the navigation away on a phone; no page logged a CSP violation or console error")
    print("\nAll good.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
