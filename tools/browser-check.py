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
the figure and the status GET resumen answers, its figures are GET
indicadores' local series for the card's month, a keyboard reaches every
card's buttons, each count tile filters the cards and says how many it
shows, the note on metas waiting for their rutas opens the previous year's
Metas Sanitarias, it renders in the light and the dark theme with each
status in Nextcloud's own colours (#85), and the cards fit a phone's width.
The metas view still answers its own year's figures, and lists its metas as
rows whose badges take the light and the dark theme's colours and which each
open to their month table (#87).

And a meta's detail (#86), in Chromium and Firefox, light and dark: a card's
title opens it on Evolución, whose chart draws at its container's width with
11 px text and the newest year in the theme's primary, and reads a month by
keyboard; the Origen tab shows the ruta; Escape gives the focus back; the
card's figure opens it on Origen. The newest metas wait for their rutas
oficiales, so that page alone gets GET resumen with one meta given a figure
and two years of histórico (with_a_historico).

And the shell (#84), in Chromium and in Firefox under the CSP Nextcloud
serves: every view opens from the navigation, marked as the open one,
with its title beside the toggle and its own chunk fetched; «Ajustes de
Estadística» takes an admin to the admin section and is not offered to the
second user; and on a phone the navigation folds away. No page logs a
console error or a CSP violation. Every view opens so again in the dark
theme (#92), set as the admin's theming user setting through occ.

And Consultar (#88), in Chromium, light and dark, and in Firefox: the search
finds the synthetic prestación by its código, and the consulta shows the
API's figures. At Nextcloud's narrowest desktop width every control stays in
its step, with the answer beside the steps; on a phone the answer follows
them.

And every chart the app draws for an answer (#91): Consultar's, a pregunta's
and a tablero card's, in the light and the dark theme, and in Firefox. Each
draws as SVG at its container's width, our establishment in the theme's
primary and the country in its muted text colour, with a legend naming both;
a column is a keyboard stop that says its value, and a line chart reads a
point by the arrow keys.

It makes everything it needs and removes it all however it ends:
- a few synthetic REM rows under a código of its own. They sit in 2009, the
  window's floor: outside the default window the REM job neither loads that
  year (which would replace its rows) nor forgets it (which reads the ingest
  state these rows have none of);
- for Consultar, that código's catálogo: a 2009 diccionario and ingest row,
  which GET fuentes reads. The REM job would forget that year on its next
  tick, which a run is too short to reach among Nextcloud's own jobs;
- for the metas, four rows in 2026 under the 2026 seed's Meta VI códigos,
  for the fabricated establishment only;
- the establishment code, set to a fabricated one and put back after;
- a pregunta, a tablero, an app password and a throwaway user;
- the admin's dark theme, for one Consultar run and for each browser's walk
  through the views.

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

# Its catálogo, as Catalog::tree serves it: one hoja, one sección, one column.
HOJA, SECCION, PRESTACION = "BC", "SECCIÓN A: NAVEGADOR", f"Prueba del navegador {RUN}"
# Nextcloud keeps the navigation open from 1024 px: the narrowest desktop.
NARROWEST_DESKTOP = {"width": 1024, "height": 768}

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
    ("Resumen", "resumen", ".estadistica-meta"),
    ("Metas Sanitarias", "metas", ".estadistica-goals h2"),
    # Its steps, or «Todavía no hay cifras REM» on an instance that has loaded none.
    ("Consultar", "consultar", ".estadistica-builder :is(.estadistica-builder__steps, .empty-content)"),
    ("Preguntas", "preguntas", f".estadistica-questions__item strong:text-is('Navegador {RUN}')"),
    ("Tableros", "tableros", ".estadistica-dashboards__picker"),
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


FIRST_LOG_IN_MS = 90_000


def log_in(page, user, password):
    page.goto(f"{BASE}/index.php/apps/estadistica/", wait_until="domcontentloaded")
    if "/login" in page.url:
        page.wait_for_selector("#user", timeout=30_000)
        page.fill("#user", user)
        page.fill("#password", password)
        # A user's first log-in does Nextcloud's first-run work (home folder, app hooks) before the redirect; on a
        # loaded host that took longer than 30 s, so the log-in gets its own budget.
        page.click('button[type="submit"]', timeout=FIRST_LOG_IN_MS)
        page.wait_for_url(f"{BASE}/index.php/apps/estadistica/**", timeout=FIRST_LOG_IN_MS)


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


def ask_in_consultar(page, where):
    """Consultar end to end (#88): the search finds the prestación by its código, col01 is summed; the answer's figures."""
    navigation_entry(page, "Consultar").click()
    page.get_by_label("Buscar una prestación").fill(CODE)
    # By text, not accessible name: NcSelect draws an option's label in two flex items, and a browser
    # joins those with a space ("Total ( col01)").
    page.get_by_role("option").filter(has_text=f"{PRESTACION} ({CODE}) — {HOJA}").click()
    page.get_by_label("Columnas que se suman").click()
    page.get_by_role("option").filter(has_text="Total (col01)").click()
    page.get_by_role("button", name="Consultar", exact=True).click()
    rows = page.locator(".estadistica-builder__answer .estadistica-answer__table tbody tr")
    rows.first.wait_for(timeout=30_000)
    page.screenshot(path=str(SHOTS / f"{where}-consultar.png"), full_page=True)
    return card_figures(rows)


def steps_beside_the_answer(page, where, beside):
    """Every control inside its step, nothing wider than the view, and the answer beside the steps or under them."""
    found = page.evaluate("""() => {
        const box = (el) => el.getBoundingClientRect()
        const outside = []
        for (const step of document.querySelectorAll('.estadistica-builder__step')) {
            for (const control of step.querySelectorAll('.v-select, .checkbox-radio-switch')) {
                if (box(control).left < box(step).left - 1 || box(control).right > box(step).right + 1) {
                    outside.push(control.textContent.trim().slice(0, 40))
                }
            }
        }
        const builder = document.querySelector('.estadistica-builder')
        const steps = box(document.querySelector('.estadistica-builder__steps'))
        const answer = box(document.querySelector('.estadistica-builder__answer'))
        return { outside, wide: builder.scrollWidth > builder.clientWidth, beside: answer.left >= steps.right, under: answer.top >= steps.bottom }
    }""")
    page.screenshot(path=str(SHOTS / f"{where}-consultar-ancho.png"), full_page=True)
    failures = [f"{where}: Consultar's control «{text}» leaves its step" for text in found["outside"]]
    if found["wide"]:
        failures.append(f"{where}: Consultar is wider than the view")
    if beside and not found["beside"]:
        failures.append(f"{where}: Consultar's answer is not beside its steps")
    if not beside and not found["under"]:
        failures.append(f"{where}: Consultar's answer does not follow its steps")
    return failures


def open_the_tablero(page, title):
    """Tableros → the tablero, chosen in the picker → its one card's table, as a person clicks through."""
    navigation_entry(page, "Tableros").click()
    page.locator(".estadistica-dashboards__picker input[role='combobox']").click()
    # By its text: NcSelect splits a long label into two parts, which its accessible name spaces apart.
    page.get_by_role("option").filter(has_text=title).click()
    rows = page.locator(".estadistica-card .estadistica-answer__table tbody tr")
    rows.first.wait_for(timeout=30_000)
    return rows


def arrange_the_card(page, pregunta):
    """The card's actions menu: its width radio shows the card's three columns and «2 columnas» saves two;
    «Eliminar el tablero» asks in a dialog first, and «Cancelar» keeps the tablero."""
    failures = []
    page.get_by_role("button", name=f"Acciones de la tarjeta «{pregunta}»").click()
    page.get_by_role("menuitemradio", name="3 columnas").hover()  # waits until the menu sits in place
    if page.get_by_role("menuitemradio", name="3 columnas").get_attribute("aria-checked") != "true":
        failures.append("the card's menu does not mark the card's 3 columns")
    for name in ("Subir", "Bajar", "Quitar"):
        if page.get_by_role("menuitem", name=name, exact=True).count() != 1:
            failures.append(f"the card's menu offers no «{name}»")
    page.screenshot(path=str(SHOTS / "tablero-acciones.png"))
    page.get_by_role("menuitemradio", name="2 columnas").click()
    try:
        page.locator(".estadistica-dashboards__cell--span-2").wait_for(timeout=30_000)
    except PlaywrightTimeout:
        failures.append("«2 columnas» did not lay the card out two columns wide")

    # A click outside the menu closes it, as it does for a person.
    page.get_by_role("button", name="Eliminar el tablero", exact=True).click()
    dialog = page.get_by_role("dialog", name="Eliminar el tablero")
    try:
        dialog.get_by_text("Sus preguntas no se eliminan.").wait_for(timeout=30_000)
        page.wait_for_function("(d) => getComputedStyle(d).opacity === '1'", arg=dialog.element_handle())  # faded in
        page.screenshot(path=str(SHOTS / "tablero-eliminar.png"))
        dialog.get_by_role("button", name="Cancelar").click()
        dialog.wait_for(state="hidden", timeout=30_000)
    except PlaywrightTimeout:
        failures.append("«Eliminar el tablero» did not ask in a dialog that «Cancelar» closes")
    return failures


def answer_chart(page, scope, where):
    """The answer's chart in `scope` (#91): SVG at its container's width, ours in the theme's primary and the country
    in its muted text colour, a legend naming both, and its first value read by keyboard above the plot."""
    chart = page.locator(f"{scope} .estadistica-chart").first
    try:
        chart.locator(".estadistica-chart__plot svg").wait_for(timeout=30_000)
        page.wait_for_function("(svg) => Math.abs(svg.getAttribute('width') - svg.parentElement.getBoundingClientRect().width) <= 1",
                               arg=chart.locator(".estadistica-chart__plot svg").element_handle(), timeout=10_000)
    except PlaywrightTimeout:
        return [f"{where}: the answer's chart never drew at its container's width"]
    failures = []
    legend = [key.strip() for key in chart.locator(".estadistica-chart__legend li").all_inner_texts()]
    if legend != ["Su establecimiento", "Nacional"]:
        failures.append(f"{where}: the chart's legend reads {legend}")
    columns = chart.locator(".estadistica-chart__column[role=img]").count() > 0
    # A column is filled; a line is its path's stroke.
    ours, country = ((".estadistica-chart__column.estadistica-chart__line--emphasis", ".estadistica-chart__column.estadistica-chart__line--context")
                     if columns else (".estadistica-chart__line--emphasis path", ".estadistica-chart__line--context path"))
    painted = chart.evaluate("""(figure, [ours, country]) => {
        const tone = (name) => { const probe = document.createElement('span'); probe.style.color = `var(${name})`;
            figure.append(probe); const color = getComputedStyle(probe).color; probe.remove(); return color }
        const paint = (selector) => { const style = getComputedStyle(figure.querySelector(`.estadistica-chart__plot ${selector}`));
            return style.fill === 'none' ? style.stroke : style.fill }
        return { primary: tone('--color-primary-element'), muted: tone('--color-text-maxcontrast'), ours: paint(ours), country: paint(country) }
    }""", [ours, country])
    if painted["ours"] != painted["primary"] or painted["country"] != painted["muted"]:
        failures.append(f"{where}: ours is painted {painted['ours']} and the country {painted['country']}, "
                        f"not the primary {painted['primary']} and the muted {painted['muted']}")
    if columns:
        first = chart.locator(".estadistica-chart__column[role=img]").first
        first.focus()
        said = first.get_attribute("aria-label")
        if said != f"enero de {YEAR}: 4 Su establecimiento":
            failures.append(f"{where}: the first column says {said!r}")
    else:
        chart.locator("svg[role=img]").focus()
        page.keyboard.press("ArrowLeft")
    readout = " ".join(chart.locator(".estadistica-chart__readout").inner_text().split())
    if not readout.startswith(f"enero de {YEAR}: 4 Su establecimiento 3 Nacional"):
        failures.append(f"{where}: the chart's readout says {readout!r}")
    page.screenshot(path=str(SHOTS / f"{where.replace(' ', '-')}-grafico.png"), full_page=True)
    return failures


def open_the_pregunta(page, title, where):
    """Preguntas → «Ver» the pregunta → its answer's chart."""
    navigation_entry(page, "Preguntas").click()
    page.get_by_role("button", name=f"Ver la pregunta «{title}»").click()
    return answer_chart(page, ".estadistica-questions__item", where)


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
    """{card: (percentage, «N de D», status)} as the Resumen's cards show them; None where a card has none."""
    cards = page.locator(".estadistica-meta")
    cards.first.wait_for(timeout=30_000)
    figures = {}
    for i in range(cards.count()):
        card = cards.nth(i)
        shown = [card.locator(part) for part in (".estadistica-meta__porcentaje", ".estadistica-meta__cuenta")]
        figures[card.locator(".estadistica-meta__codigo").inner_text().strip()] = tuple(
            part.inner_text().strip() if part.count() else None for part in shown) + (card.locator(".estadistica-estado").inner_text().strip(),)
    return figures


def resumen_status(meta):
    """The word a card's status says (status.js): the engine's estado first, then the engine's own cumple."""
    if (meta["estado"] or "").startswith("esperando ruta oficial"):
        return "Esperando ruta oficial"
    if meta["estado"]:
        return "Falta el denominador oficial"
    if meta["porcentaje"] is None:
        return "Sin informar"
    return "Cumple" if meta["cumple"] else "No cumple"


def resumen_api_figures(answer):
    """The same, from GET resumen, formatted as the cards format them."""
    figures = {}
    for meta in answer["metas"]:
        judged = meta["estado"] is None and meta["porcentaje"] is not None
        figures[f"Meta {meta['codigo']} {answer['anio']}"] = (
            f"{es_cl(meta['porcentaje'])} %" if judged else None,
            f"{es_cl(meta['numerador'])} de {es_cl(meta['denominador'])}" if judged and not meta["manual"] else None,
            resumen_status(meta))
    return figures


def resumen_controls(answer):
    """The cards with a button: every one, as each title opens its detail (#86)."""
    return sorted(f"Meta {meta['codigo']} {answer['anio']}" for meta in answer["metas"])


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
    """The cards whose buttons a keyboard focuses, tabbing from the top of the page."""
    reached = []
    for _ in range(80 + 2 * count):
        page.keyboard.press("Tab")
        card = page.evaluate("() => document.activeElement.matches('.estadistica-meta button')"
                             " ? document.activeElement.closest('.estadistica-meta').querySelector('.estadistica-meta__codigo').innerText.trim() : null")
        if card and card not in reached:
            reached.append(card)
        if len(reached) == count:
            break
    # NcContent's skip links, focused by the first Tab, cover the page until the focus leaves them.
    page.evaluate("() => document.activeElement.blur()")
    return reached


def resumen_filters(page):
    """Each count tile, pressed: the cards it shows and what the live region says, against its count (#85)."""
    failures = []
    tiles = page.get_by_role("group", name="Filtrar metas por estado").get_by_role("button")
    for i in range(tiles.count()):
        tile = tiles.nth(i)
        tile.click()
        count = int(tile.locator(".estadistica-tiles__count").inner_text())
        said = page.locator(".estadistica-resumen [role=status]").inner_text().strip()
        shown = page.locator(".estadistica-meta").count()
        if tile.get_attribute("aria-pressed") != "true" or shown != count or not said.startswith(f"{count} meta"):
            failures.append(f"the Resumen's tile «{tile.inner_text()}» shows {shown} cards and says {said!r}")
    tiles.first.click()
    return failures


def resumen_theme(page, theme):
    """Each status badge in Nextcloud's own pair, in this theme: a background the theme gives, and text unlike it (#85)."""
    page.locator(".estadistica-estado").first.wait_for(timeout=30_000)
    painted = page.evaluate("() => [...document.querySelectorAll('.estadistica-estado')].map((badge) => {"
                            " const style = getComputedStyle(badge); return [badge.innerText.trim(), style.backgroundColor, style.color] })")
    return [f"{theme} theme: the «{word}» badge has no colour of the theme's ({background} behind {color})"
            for word, background, color in painted if background in ("rgba(0, 0, 0, 0)", "transparent") or background == color]


def resumen_waiting_note(page, answer):
    """While metas wait for their rutas oficiales, the note opens the previous year's Metas Sanitarias (#85)."""
    if not any((meta["estado"] or "").startswith("esperando ruta oficial") for meta in answer["metas"]):
        return []
    previous = answer["anio"] - 1
    page.get_by_role("button", name=f"Ver las Metas Sanitarias {previous}").click()
    try:
        page.locator(f".estadistica-goals h2:has-text('{previous}')").wait_for(timeout=30_000)
    except PlaywrightTimeout:
        return [f"the Resumen's note did not open the Metas Sanitarias {previous}"]
    navigation_entry(page, "Resumen").click()
    page.locator(".estadistica-meta").first.wait_for(timeout=30_000)
    return []


def metas_rows(page, where):
    """Metas Sanitarias as rows (#87): each badge in the theme's pair, and a row opens the panel it controls."""
    navigation_entry(page, "Metas Sanitarias").click()
    row = page.locator(".estadistica-goals__row").first
    try:
        row.wait_for(timeout=30_000)
    except PlaywrightTimeout:
        return [f"{where}: Metas Sanitarias shows no meta row"]
    failures = resumen_theme(page, f"{where} Metas Sanitarias")
    row.click()
    if row.get_attribute("aria-expanded") != "true" or not page.locator(f"#{row.get_attribute('aria-controls')}").is_visible():
        failures.append(f"{where}: a meta's row did not open its panel")
    page.screenshot(path=str(SHOTS / f"{where}-metas-abierta.png"), full_page=True)
    return failures


def dark_resumen(browser, name):
    """The Resumen and Metas Sanitarias in the dark theme, which Nextcloud's default theme follows from the system."""
    page = browser.new_context(viewport={"width": 1400, "height": 900}, color_scheme="dark").new_page()
    errors = console_errors(page)
    log_in(page, ADMIN, ADMIN_PASSWORD)
    resumen_figures(page)
    page.screenshot(path=str(SHOTS / f"{name}-resumen-oscuro.png"), full_page=True)
    failures = resumen_theme(page, f"{name} dark")
    card = page.locator(".estadistica-meta").first.evaluate("(card) => getComputedStyle(card).backgroundColor")
    if sum(int(part) for part in card[card.index("(") + 1:card.index(")")].split(",")[:3]) > 3 * 128:
        failures.append(f"{name}: in the dark theme a Resumen card is still light ({card})")
    failures += metas_rows(page, f"{name}-oscuro")
    return failures + [f"console error in the {name} dark theme: {e}" for e in errors]


def dark_views(browser, name):
    """Every view from the navigation in the admin's dark theme, a theming user setting set through occ (#92)."""
    occ("user:setting", ADMIN, "theming", "enabled-themes", '["dark"]')
    try:
        page = browser.new_context(viewport={"width": 1400, "height": 900}).new_page()
        errors = console_errors(page)
        fetched = chunks_fetched(page)
        log_in(page, ADMIN, ADMIN_PASSWORD)
        failures = [] if "dark" in page.evaluate("() => document.body.dataset.themes ?? ''") else [
            f"{name}: the admin's dark theme did not reach the page"]
        failures += walk_the_navigation(page, fetched, f"{name}-oscuro")
        page.context.close()
        return failures + [f"console error in the {name} dark theme's views: {e}" for e in errors]
    finally:
        occ("user:setting", ADMIN, "theming", "enabled-themes", "--delete", check=False)


def with_a_historico(answer):
    """GET resumen with its first REM meta given a figure for March and two comparable years, for the detail alone."""
    anio = answer["anio"]
    meta = next(meta for meta in answer["metas"] if not meta["manual"])
    months = lambda year, count: [{"mes": f"{year}-{m:02d}", "numerador": m, "denominador": 12, "porcentaje": round(100 * m / 12, 2)}
                                  for m in range(1, count + 1)]
    codigo = {"codigo": "A0200002", "hoja": "A02", "seccion": "SECCIÓN A: NAVEGADOR", "prestacion": "Prueba del navegador",
              "columnas": {"col06": "Total"}, "nota": None}
    ruta = lambda year: {"numerador": {"fuente": "rem-a", "nombre": "REM Serie A", "desde": f"{year}-01", "hasta": f"{year}-12",
                                       "sumar": [[codigo]], "restar": []}, "denominador": None}
    meta.update(estado=None, mes=f"{anio}-03", numerador=3, denominador=12, porcentaje=25.0, cumple=False,
                origen="datos públicos DEIS", fecha_corte=f"{anio}-04", historico=[
                    {"anio": anio - 1, "estado": None, "cierre": 100.0, "meses": months(anio - 1, 12), "ruta": ruta(anio - 1)},
                    {"anio": anio, "estado": None, "cierre": None, "meses": months(anio, 3), "ruta": ruta(anio)}])
    return answer, meta


def slid_in(page):
    """Waits out NcAppSidebar's slide-in, whose end moves the focus to its header."""
    page.wait_for_function("() => { const aside = document.querySelector('aside.app-sidebar');"
                           " return aside !== null && !/slide-right-enter/.test(aside.className) }", timeout=10_000)


def resumen_detail(browser, name, scheme):
    """A meta's detail beside the Resumen (#86), both tabs, in this browser and colour scheme."""
    where = f"{name} {scheme}"
    page = browser.new_context(viewport={"width": 1400, "height": 900}, color_scheme=scheme).new_page()
    errors = console_errors(page)
    fetched = chunks_fetched(page)
    shown = {}

    def synthetic(route):
        response = route.fetch()
        body = response.json()
        body["ocs"]["data"], shown["meta"] = with_a_historico(body["ocs"]["data"])
        route.fulfill(response=response, json=body)

    page.route("**/apps/estadistica/api/v1/resumen*", synthetic)
    log_in(page, ADMIN, ADMIN_PASSWORD)
    page.locator(".estadistica-meta").first.wait_for(timeout=30_000)
    failures = []
    sidebar = page.locator("aside.app-sidebar")
    selected = lambda: sidebar.locator("[role=tab][aria-selected=true]").inner_text().strip()
    title = page.get_by_role("button", name=shown["meta"]["nombre"], exact=True)
    title.click()
    plot = sidebar.locator(".estadistica-chart__plot svg")
    try:
        plot.wait_for(timeout=30_000)
        slid_in(page)
        page.wait_for_function("(svg) => Math.abs(svg.getAttribute('width') - svg.parentElement.getBoundingClientRect().width) <= 1",
                               arg=plot.element_handle(), timeout=10_000)
    except PlaywrightTimeout:
        failures.append(f"{where}: the detail's chart never drew at its container's width")
    if selected() != "Evolución":
        failures.append(f"{where}: a card's title opened the detail on {selected()!r}, not Evolución")
    drawn = plot.evaluate("(svg) => { const probe = document.createElement('span'); probe.style.color = 'var(--color-primary-element)';"
                          " svg.parentElement.append(probe); const primary = getComputedStyle(probe).color; probe.remove();"
                          " return { font: getComputedStyle(svg.querySelector('text')).fontSize, primary,"
                          " newest: getComputedStyle(svg.querySelector('.estadistica-chart__line--emphasis path')).stroke } }")
    if drawn["font"] != "11px" or drawn["newest"] != drawn["primary"]:
        failures.append(f"{where}: the chart's text is {drawn['font']} and its newest year {drawn['newest']}, not 11px in the primary {drawn['primary']}")
    plot.focus()
    page.keyboard.press("ArrowLeft")
    said = sidebar.locator(".estadistica-chart__readout").inner_text().strip()
    if not said.startswith("noviembre:"):
        failures.append(f"{where}: focus and ArrowLeft on the chart say {said!r}, not noviembre")
    page.screenshot(path=str(SHOTS / f"{name}-detalle-evolucion-{scheme}.png"))

    sidebar.get_by_role("tab", name="Origen").click()
    origen = sidebar.locator("[role=tabpanel]:not([aria-hidden=true])").inner_text()
    if selected() != "Origen" or "A0200002" not in origen or "Referencia:" not in origen:
        failures.append(f"{where}: the Origen tab shows {origen[:200]!r}")
    page.screenshot(path=str(SHOTS / f"{name}-detalle-origen-{scheme}.png"))

    figure = title.locator("xpath=ancestor::article").locator(".estadistica-meta__cifra")
    for opener, tab in ((title, "Evolución"), (figure, "Origen")):
        if opener is figure:
            opener.click()
            sidebar.wait_for(timeout=30_000)
            slid_in(page)
            if selected() != tab:
                failures.append(f"{where}: a card's figure opened the detail on {selected()!r}, not {tab}")
        page.keyboard.press("Escape")
        try:
            sidebar.wait_for(state="detached", timeout=10_000)
        except PlaywrightTimeout:
            failures.append(f"{where}: Escape did not close the detail opened on {tab}")
        if not opener.evaluate("(button) => document.activeElement === button"):
            failures.append(f"{where}: closing the detail opened on {tab} did not give the focus back to its button")
    if "estadistica-detalle.js" not in fetched:
        failures.append(f"{where}: the detail opened without fetching its chunk estadistica-detalle.js")
    page.context.close()
    return failures + [f"console error in the {where} detail: {e}" for e in errors]


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

        psql(f"INSERT INTO oc_estadistica_ingest (anio, serie, origin, row_count, fecha_corte) VALUES ({YEAR}, 'A', 'deis', {len(ROWS)}, '{YEAR}-02');"
             f"INSERT INTO oc_estadistica_dictionary (anio, serie, name, sheets, codes, checked_at) VALUES ({YEAR}, 'A', 'DICCIONARIO navegador', '{{\"{HOJA}\": \"REM-{HOJA}\"}}', 1, 0);"
             f"INSERT INTO oc_estadistica_code (anio, serie, codigo, orden, hoja, seccion, prestacion, columnas) VALUES ({YEAR}, 'A', '{CODE}', 1, '{HOJA}', '{SECCION}', '{PRESTACION}', '{{\"1\": \"Total\"}}');")
        made["catalogo"] = True

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
            controls = resumen_controls(resumen_answer)
            reached = keyboard_reaches(page, len(controls))
            if sorted(reached) != controls:
                failures.append(f"a keyboard reaches the buttons of only these Resumen cards: {reached}, not {controls}")
            failures += resumen_filters(page)
            failures += resumen_theme(page, "light")
            failures += resumen_waiting_note(page, resumen_answer)
            failures += metas_rows(page, "chromium")

            rows = open_the_tablero(page, f"Tablero navegador {RUN}")
            shown = card_figures(rows)
            page.screenshot(path=str(SHOTS / "tablero.png"), full_page=True)
            if shown != expected:
                failures.append(f"a15: the card shows {shown}, the API answers {expected}")
            if page.locator(".estadistica-card .estadistica-chart").count() != 1:
                failures.append("the card drew no chart above its table")
            failures += answer_chart(page, ".estadistica-card", "chromium tablero")
            failures += arrange_the_card(page, f"Navegador {RUN}")
            failures += open_the_pregunta(page, f"Navegador {RUN}", "chromium pregunta")
            failures += walk_the_navigation(page, fetched, "chromium")

            # #88: a consulta from the search to the answer, then the layout at the narrowest desktop.
            log_in(page, ADMIN, ADMIN_PASSWORD)
            if (asked := ask_in_consultar(page, "chromium")) != expected:
                failures.append(f"Consultar shows {asked}, the API answers {expected}")
            failures += answer_chart(page, ".estadistica-builder__answer", "chromium consultar barras")
            page.get_by_text("Líneas", exact=True).click()
            failures += answer_chart(page, ".estadistica-builder__answer", "chromium consultar lineas")
            page.set_viewport_size(NARROWEST_DESKTOP)
            failures += steps_beside_the_answer(page, "chromium at 1024 px", beside=True)
            failures += [f"console error for the creator: {e}" for e in errors]

            # a8: a second user who administers nothing reads the same tablero.
            reader = browser.new_context(viewport={"width": 1400, "height": 900}).new_page()
            reader_errors = console_errors(reader)
            log_in(reader, READER, READER_PASSWORD)
            rows = open_the_tablero(reader, f"Tablero navegador {RUN}")
            if card_figures(rows) != expected:
                failures.append(f"a8: the second user's card shows {card_figures(rows)}, the API answers {expected}")
            if reader.get_by_role("button", name=f"Acciones de la tarjeta «Navegador {RUN}»").count() != 0:
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
            phone.locator("button.app-navigation-toggle").click()
            navigation_entry(phone, "Consultar").click()
            phone.locator(".estadistica-builder__steps").wait_for(timeout=30_000)
            failures += steps_beside_the_answer(phone, "a phone", beside=False)
            failures += [f"console error on a phone: {e}" for e in phone_errors]
            failures += dark_resumen(browser, "chromium")
            failures += dark_views(browser, "chromium")
            failures += resumen_detail(browser, "chromium", "light") + resumen_detail(browser, "chromium", "dark")

            # The dark theme: a consulta still runs, with no violation or console error.
            occ("user:setting", ADMIN, "theming", "enabled-themes", '["dark"]')
            made["dark"] = True
            dark = browser.new_context(viewport={"width": 1400, "height": 900}).new_page()
            dark_errors = console_errors(dark)
            log_in(dark, ADMIN, ADMIN_PASSWORD)
            if "dark" not in dark.evaluate("() => document.body.dataset.themes ?? ''"):
                failures.append("the admin's dark theme did not reach the page")
            if (asked := ask_in_consultar(dark, "chromium-oscuro")) != expected:
                failures.append(f"Consultar in the dark theme shows {asked}, the API answers {expected}")
            failures += answer_chart(dark, ".estadistica-builder__answer", "chromium oscuro consultar")
            failures += open_the_pregunta(dark, f"Navegador {RUN}", "chromium oscuro pregunta")
            open_the_tablero(dark, f"Tablero navegador {RUN}")
            failures += answer_chart(dark, ".estadistica-card", "chromium oscuro tablero")
            failures += [f"console error in the dark theme: {e}" for e in dark_errors]
            occ("user:setting", ADMIN, "theming", "enabled-themes", "--delete")
            made.pop("dark")

            browser.close()

            # The shell again in Firefox, whose CSP enforcement and module loading are its own.
            firefox = p.firefox.launch()
            page = firefox.new_page(viewport={"width": 1400, "height": 900})
            firefox_errors = console_errors(page)
            fetched = chunks_fetched(page)
            log_in(page, ADMIN, ADMIN_PASSWORD)
            failures += walk_the_navigation(page, fetched, "firefox")
            log_in(page, ADMIN, ADMIN_PASSWORD)
            if (asked := ask_in_consultar(page, "firefox")) != expected:
                failures.append(f"Consultar in Firefox shows {asked}, the API answers {expected}")
            failures += answer_chart(page, ".estadistica-builder__answer", "firefox consultar")
            failures += open_the_pregunta(page, f"Navegador {RUN}", "firefox pregunta")
            open_the_tablero(page, f"Tablero navegador {RUN}")
            failures += answer_chart(page, ".estadistica-card", "firefox tablero")
            failures += [f"console error in Firefox: {e}" for e in firefox_errors]
            failures += dark_resumen(firefox, "firefox")
            failures += dark_views(firefox, "firefox")
            failures += resumen_detail(firefox, "firefox", "light") + resumen_detail(firefox, "firefox", "dark")
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
        if made.get("catalogo"):
            psql(f"DELETE FROM oc_estadistica_code WHERE anio = {YEAR} AND codigo = '{CODE}';"
                 f"DELETE FROM oc_estadistica_dictionary WHERE anio = {YEAR} AND serie = 'A';"
                 f"DELETE FROM oc_estadistica_ingest WHERE anio = {YEAR} AND serie = 'A';")
        if made.get("dark"):
            occ("user:setting", ADMIN, "theming", "enabled-themes", "--delete", check=False)
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
    print("ok    the Resumen opens first, its cards show GET resumen's figures and status, which are GET indicadores' local months, a keyboard reaches each card's buttons, each tile filters and says so, and it fits a phone")
    print("ok    the Resumen's and Metas Sanitarias' status badges take the light and the dark theme's own colours, in Chromium and Firefox, and a meta's row opens its month table")
    print("ok    a meta's detail opens on Evolución from its title and on Origen from its figure, its chart at its container's width in the theme's primary, read by keyboard, Escape giving the focus back, light and dark, in Chromium and Firefox")
    print(f"ok    Chromium and Firefox open every view from the navigation, each with its chunk, light and dark, and «{SETTINGS}» opens the admin section for an admin only")
    print("ok    Consultar finds a prestación by its código and shows the API's figures in Chromium, light and dark, and Firefox; at 1024 px its controls stay in their steps beside the answer, which follows them on a phone")
    print("ok    Consultar's answer, a pregunta and a tablero card draw their SVG chart at its container's width, ours in the primary and the country muted, named in the legend and read by keyboard, light and dark, in Chromium and Firefox")
    print("ok    the title sits beside the navigation toggle, which folds the navigation away on a phone; no page logged a CSP violation or console error")
    print("\nAll good.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
