#!/usr/bin/env python3
"""
DWG/DXF -> SVG vettoriale + metadati per BOB Zone.

Pipeline:
  1) se input .dwg -> converte in .dxf con `dwg2dxf` (LibreDWG) oppure
     ODAFileConverter se configurato (env BOB_ODA_CONVERTER).
  2) legge il DXF con ezdxf, esplode blocchi e appiattisce curve in polilinee
     (in coordinate WCS reali del disegno).
  3) emette un SVG con viewBox = estensione reale del disegno, cosi' 1 unita'
     SVG = 1 unita' disegno -> le misure nel browser sono esatte.
  4) stampa su stdout un JSON con: svg_path, extents, insunits, meters_per_unit.

Dipendenze server:
  - python3, pip install ezdxf
  - libredwg-tools (fornisce dwg2dxf)   [oppure ODA File Converter]

Uso:
  python3 dwg_to_svg.py <input.(dwg|dxf)> <output_dir>
"""

import sys
import os
import json
import subprocess
import tempfile

# INSUNITS (DXF header) -> metri per unita'
INSUNITS_TO_M = {
    0: None,        # unitless -> sconosciuto (il PHP/JS userà un default)
    1: 0.0254,      # inches
    2: 0.3048,      # feet
    3: 1609.344,    # miles
    4: 0.001,       # millimeters
    5: 0.01,        # centimeters
    6: 1.0,         # meters
    7: 1000.0,      # kilometers
    8: 0.0254e-6,   # microinches
    9: 0.0254e-3,   # mils
    10: 0.9144,     # yards
    14: 0.1,        # decimeters
}


def fail(msg):
    print(json.dumps({"ok": False, "error": msg}))
    sys.exit(1)


def dwg_to_dxf(dwg_path, out_dir):
    """Converte DWG -> DXF. Ritorna il path del DXF prodotto."""
    dxf_path = os.path.join(out_dir, os.path.splitext(os.path.basename(dwg_path))[0] + ".dxf")

    oda = os.environ.get("BOB_ODA_CONVERTER", "").strip()
    if oda and os.path.isfile(oda):
        # ODA File Converter: <in_dir> <out_dir> <out_ver> <out_type> <recurse> <audit>
        # E' una GUI Qt: la lanciamo headless con xvfb-run se disponibile.
        in_dir = os.path.dirname(dwg_path) or "."
        oda_cmd = [oda, in_dir, out_dir, "ACAD2018", "DXF", "0", "1", os.path.basename(dwg_path)]
        from shutil import which
        if which("xvfb-run"):
            oda_cmd = ["xvfb-run", "-a"] + oda_cmd
        subprocess.run(oda_cmd, check=True, capture_output=True, timeout=180)
        if os.path.isfile(dxf_path):
            return dxf_path
        # ODA a volte nomina diversamente: prendi il primo .dxf nella out_dir
        for f in os.listdir(out_dir):
            if f.lower().endswith(".dxf"):
                return os.path.join(out_dir, f)
        fail("ODA: nessun DXF prodotto")

    # LibreDWG dwg2dxf. Risolvi il path in modo robusto: l'ambiente del web
    # server (PHP-FPM) spesso NON ha /usr/local/bin nel PATH ne' /usr/local/lib
    # nelle librerie. Cerchiamo il binario in posizioni note e forziamo
    # LD_LIBRARY_PATH per caricare libredwg.so.
    from shutil import which
    dwg2dxf = (os.environ.get("BOB_DWG2DXF", "").strip()
               or which("dwg2dxf")
               or next((p for p in ("/usr/local/bin/dwg2dxf", "/usr/bin/dwg2dxf") if os.path.isfile(p)), None))
    if not dwg2dxf:
        fail("dwg2dxf non trovato (cerca in PATH, /usr/local/bin, /usr/bin; "
             "oppure imposta BOB_DWG2DXF / BOB_ODA_CONVERTER).")

    env = dict(os.environ)
    ld = env.get("LD_LIBRARY_PATH", "")
    for libdir in ("/usr/local/lib", "/usr/local/lib64"):
        if libdir not in ld.split(":"):
            ld = (ld + ":" + libdir) if ld else libdir
    env["LD_LIBRARY_PATH"] = ld

    try:
        subprocess.run([dwg2dxf, "-o", dxf_path, dwg_path],
                       check=True, capture_output=True, timeout=120, env=env)
    except subprocess.CalledProcessError as e:
        fail("dwg2dxf fallito: " + (e.stderr.decode("utf-8", "ignore")[:300] if e.stderr else "errore"))
    if not os.path.isfile(dxf_path):
        fail("Conversione DWG->DXF non ha prodotto output")
    return dxf_path


def emit_svg(dxf_path, svg_path):
    import ezdxf
    from ezdxf import disassemble, bbox

    doc = ezdxf.readfile(dxf_path)
    msp = doc.modelspace()

    insunits = int(doc.header.get("$INSUNITS", 0) or 0)
    m_per_unit = INSUNITS_TO_M.get(insunits, None)

    # estensione reale del disegno
    ext = bbox.extents(msp, fast=True)
    if not ext.has_data:
        fail("Disegno vuoto o estensione non calcolabile")
    minx, miny = float(ext.extmin.x), float(ext.extmin.y)
    maxx, maxy = float(ext.extmax.x), float(ext.extmax.y)
    w = maxx - minx
    h = maxy - miny
    if w <= 0 or h <= 0:
        fail("Estensione disegno non valida")

    diag = (w * w + h * h) ** 0.5
    # spessore linea proporzionale alla diagonale
    stroke = max(1.0, diag * 0.0006)
    # tolleranza di appiattimento curve: meno punti = SVG piu' leggero
    mfd = diag * 0.0008

    yflip = miny + maxy  # svg_y = yflip - p.y

    # UN SOLO path con tutti i sottotracciati (M..L..). Riduce drasticamente
    # il numero di elementi DOM rispetto a migliaia di <polyline> separate.
    segs = []
    n = 0
    for prim in disassemble.to_primitives(
        disassemble.recursive_decompose(msp), max_flattening_distance=mfd
    ):
        try:
            pts = list(prim.vertices())
        except Exception:
            continue
        if len(pts) < 2:
            continue
        d = "M%d %d" % (round(pts[0].x), round(yflip - pts[0].y))
        for p in pts[1:]:
            d += "L%d %d" % (round(p.x), round(yflip - p.y))
        segs.append(d)
        n += 1

    if not segs:
        fail("Nessuna geometria estraibile dal DXF")

    body = (
        f'<path d="{"".join(segs)}" fill="none" stroke="#0f172a" '
        f'stroke-width="{stroke:.2f}" stroke-linejoin="round" stroke-linecap="round"/>'
    )
    svg = (
        f'<svg xmlns="http://www.w3.org/2000/svg" '
        f'viewBox="{minx:.2f} {miny:.2f} {w:.2f} {h:.2f}">'
        f'<rect x="{minx:.2f}" y="{miny:.2f}" width="{w:.2f}" height="{h:.2f}" fill="#ffffff"/>'
        f'{body}</svg>'
    )
    with open(svg_path, "w", encoding="utf-8") as fh:
        fh.write(svg)

    return {
        "minx": minx, "miny": miny, "maxx": maxx, "maxy": maxy,
        "insunits": insunits,
        "meters_per_unit": m_per_unit,
        "entities": n,
    }


def main():
    if len(sys.argv) < 3:
        fail("uso: dwg_to_svg.py <input> <output_dir>")
    src = sys.argv[1]
    out_dir = sys.argv[2]
    if not os.path.isfile(src):
        fail("input non trovato: " + src)
    os.makedirs(out_dir, exist_ok=True)

    ext = os.path.splitext(src)[1].lower()
    with tempfile.TemporaryDirectory() as tmp:
        dxf = src if ext == ".dxf" else dwg_to_dxf(src, tmp)
        svg_path = os.path.join(out_dir, os.path.splitext(os.path.basename(src))[0] + ".svg")
        meta = emit_svg(dxf, svg_path)

    meta.update({"ok": True, "svg_path": svg_path})
    print(json.dumps(meta))


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except Exception as e:  # noqa
        fail(type(e).__name__ + ": " + str(e)[:300])
