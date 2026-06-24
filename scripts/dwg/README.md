# DWG → SVG (BOB Zone)

Pipeline di conversione DWG per il viewer vettoriale di BOB Zone.
`dwg_to_svg.py` converte un DWG (o DXF) in SVG vettoriale + metadati
(estensione reale + unità) per misure esatte nel browser.

## Installazione sul server (collaudo / produzione)

### DXF — funziona subito (nessun convertitore)
```bash
sudo apt install -y python3 python3-pip
pip3 install ezdxf
```
ezdxf legge i DXF direttamente. Carica un .dxf dal tab Disegni e funziona.
(Da AutoCAD: Salva con nome → DXF.)

### DWG — serve un convertitore DWG→DXF
`libredwg-tools` NON è nei repo Ubuntu 22.04. Due strade:

**A) ODA File Converter (consigliata, gratis, qualità top)**
```bash
# scarica il .deb da:
#   https://www.opendesign.com/guestfiles/oda_file_converter
sudo apt install -y ./ODAFileConverter_*.deb xvfb
# trova l'eseguibile (di solito):
#   /usr/bin/ODAFileConverter
# poi esporta la variabile per PHP (es. nel .env o nella conf del web server):
export BOB_ODA_CONVERTER=/usr/bin/ODAFileConverter
```
Lo script lancia ODA headless con `xvfb-run` in automatico.

**B) LibreDWG da sorgente (se preferisci open source puro)**
```bash
sudo apt install -y build-essential libtool autoconf git
git clone https://github.com/LibreDWG/libredwg.git
cd libredwg && sh autogen.sh && ./configure --enable-release && make && sudo make install
# fornisce dwg2dxf nel PATH
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
