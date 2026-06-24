# DWG → SVG (BOB Zone)

Pipeline di conversione DWG per il viewer vettoriale di BOB Zone.
`dwg_to_svg.py` converte un DWG (o DXF) in SVG vettoriale + metadati
(estensione reale + unità) per misure esatte nel browser.

## Installazione sul server (collaudo / produzione)

```bash
# 1) Python + ezdxf (lettura DXF + estrazione geometria)
sudo apt install -y python3 python3-pip
pip3 install ezdxf

# 2) Convertitore DWG → DXF. Opzione A (open source, consigliata per iniziare):
sudo apt install -y libredwg-tools     # fornisce 'dwg2dxf'

#    Opzione B (qualità migliore, DWG recenti): ODA File Converter
#    Scarica da https://www.opendesign.com/guestfiles/oda_file_converter
#    poi esporta la variabile col path dell'eseguibile:
#    export BOB_ODA_CONVERTER=/opt/ODAFileConverter/ODAFileConverter
#    (ODA è GUI: serve xvfb-run per girare headless)
```

## Variabili d'ambiente (opzionali)

- `BOB_PYTHON`         → path dell'interprete python3 (default: `python3`)
- `BOB_ODA_CONVERTER`  → path ODA File Converter; se assente si usa `dwg2dxf`

## Test manuale

```bash
python3 scripts/dwg/dwg_to_svg.py /percorso/disegno.dwg /tmp/out
# stampa un JSON: {"ok":true,"svg_path":"...","minx":..,"meters_per_unit":..}
```

## Note

- Le misure sono esatte se il DXF ha `$INSUNITS` impostato (mm/cm/m/...).
  Se è "unitless" (`meters_per_unit: null`), il viewer mostra le distanze
  in unità disegno e si può comunque calibrare a mano.
- Testi/hatch complessi possono non comparire: il render privilegia la
  geometria (linee/polilinee/cerchi/archi) che è ciò che serve per misurare
  e annotare.
