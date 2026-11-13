#!/usr/bin/env python3
"""La Zone provata da chi la usa: ufficio, capo squadra, cliente, e chi non c'entra."""
import json, subprocess, urllib.request, urllib.parse, uuid

BASE = 'http://127.0.0.1:8833/worksites/'
UFF, UFF2, CAPO, CLI, CLI2, ALTRO = 5, 6, 30, 10, 11, 20
NOMI = {5: 'Ufficio', 6: 'Ufficio fuori', 30: 'Capo', 10: 'Cliente', 11: 'Cliente 2', 20: 'Cliente altro'}
ok = ko = 0
righe = []

def chiama(chi, metodo, percorso, corpo=None, form=None, file=None, w=100):
    url = BASE + str(w) + '/zone' + percorso
    dati, tipo = None, None
    if corpo is not None:
        dati, tipo = json.dumps(corpo).encode(), 'application/json'
    elif form is not None:
        dati, tipo = urllib.parse.urlencode(form).encode(), 'application/x-www-form-urlencoded'
    elif file is not None:
        b = uuid.uuid4().hex
        parti = []
        for k, v in (file.get('campi') or {}).items():
            parti.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        parti.append(f'--{b}\r\nContent-Disposition: form-data; name="file"; filename="{file["nome"]}"\r\nContent-Type: application/pdf\r\n\r\n'.encode() + b'%PDF-1.4 prova' + b'\r\n')
        parti.append(f'--{b}--\r\n'.encode())
        dati, tipo = b''.join(parti), f'multipart/form-data; boundary={b}'
    req = urllib.request.Request(url, data=dati, method=metodo, headers={'X-Test-User': str(chi), **({'Content-Type': tipo} if tipo else {})})
    try:
        with urllib.request.urlopen(req) as r:
            testo, codice = r.read().decode(), r.status
    except urllib.error.HTTPError as e:
        testo, codice = e.read().decode(), e.code
    try:
        j = json.loads(testo)
    except Exception:
        j = {'raw': testo}
    j['_http'] = codice
    return j

def sql(q):
    r = subprocess.run(['mysql', '-ualion', '-N', '-B', 'zonatest', '-e', q], capture_output=True, text=True)
    return [l.split('\t') for l in r.stdout.strip().split('\n') if l]

def verifica(sezione, cosa, cond, extra=''):
    global ok, ko
    if cond: ok += 1
    else: ko += 1
    righe.append((sezione, cosa, cond, extra))

def puo(j): return j.get('ok') is True and j['_http'] == 200
def ids(j, chiave=None):
    d = j.get('data', [])
    if chiave: d = d.get(chiave, []) if isinstance(d, dict) else []
    return {int(x['id']) for x in d} if isinstance(d, list) else set()
def notifiche(u): return sql(f"SELECT title, link FROM bb_notifications WHERE user_id={u} ORDER BY id")
def email(a): return sql(f"SELECT oggetto FROM test_email WHERE a LIKE '%{a}%'")
def pulisci_notifiche(): sql("DELETE FROM bb_notifications; DELETE FROM test_email;")

S = '1. Chi entra'
verifica(S, 'cliente di un altro committente non entra', chiama(ALTRO, 'GET', '/tasks')['_http'] == 403)
verifica(S, 'ufficio col modulo ma non nel cantiere: non legge le attività', chiama(UFF2, 'GET', '/tasks')['_http'] == 403)
verifica(S, '... ma vede "Chi accede" per aggiungersi', puo(chiama(UFF2, 'GET', '/accessi')))
r = chiama(UFF, 'POST', '/accessi', form={'user_id': ALTRO})
verifica(S, "l'ufficio non può mettere un cliente sul cantiere di un altro committente", not puo(r), r.get('error', ''))
u = chiama(UFF, 'GET', '/users?qui=1')
verifica(S, '"Assegnato a" propone solo chi sta nel cantiere', {x['id'] for x in u['data']} == {UFF, CAPO, CLI})
verifica(S, 'il cliente non puo\' dare accessi', chiama(CLI, 'POST', '/accessi', form={'user_id': CLI2})['_http'] == 403)

S = '2. Attività'
pulisci_notifiche()
t1 = chiama(UFF, 'POST', '/tasks', {'name': 'Montaggio corsia A', 'assegnata_a': 'squadra', 'due_date': '2026-11-01'})['data']['id']
t2 = chiama(UFF, 'POST', '/tasks', {'name': 'Costi interni gru', 'parti': [], 'persone': []})['data']['id']
t3 = chiama(UFF, 'POST', '/tasks', {'name': 'Approvare campione colore', 'assegnata_a': 'cliente'})['data']['id']
tk = chiama(CLI, 'POST', '/tasks', {'name': 'Spostare scaffale 4'})['data']
tc = chiama(CAPO, 'POST', '/tasks', {'name': 'Manca tirante campata 3'})
verifica(S, 'il capo non apre attività nuove (lo fanno ufficio e cliente)', not puo(tc))
vc, vk = ids(chiama(CAPO, 'GET', '/tasks')), ids(chiama(CLI, 'GET', '/tasks'))
verifica(S, "attività dell'ufficio: la vedono squadra e cliente", t1 in vc and t1 in vk)
verifica(S, '"solo ufficio": né squadra né cliente', t2 not in vc and t2 not in vk)
verifica(S, "quella del cliente va all'ufficio e la vede anche la squadra", tk['assegnata_a'] == 'ufficio' and tk['id'] in vc)
verifica(S, 'il capo non cambia le attività degli altri', chiama(CAPO, 'POST', f'/tasks/{t1}/update', {'name': 'x'})['_http'] == 403)
verifica(S, 'il capo porta avanti quelle della squadra', puo(chiama(CAPO, 'POST', f'/tasks/{t1}/status', {'status': 'complete'})))
verifica(S, 'il capo non verifica', chiama(CAPO, 'POST', f'/tasks/{t1}/status', {'status': 'verified'})['_http'] == 403)
verifica(S, 'il committente porta avanti anche quelle della squadra', puo(chiama(CLI, 'POST', f'/tasks/{t1}/status', {'status': 'open'})))
verifica(S, 'il committente modifica anche quelle di CSM', puo(chiama(CLI, 'POST', f'/tasks/{t1}/update', {'name': 'Montaggio corsia A'})))
verifica(S, 'il committente non verifica', chiama(CLI, 'POST', f'/tasks/{t1}/status', {'status': 'verified'})['_http'] == 403)
verifica(S, 'il cliente chiude quelle date a lui', puo(chiama(CLI, 'POST', f'/tasks/{t3}/status', {'status': 'complete'})))
verifica(S, "il committente porta avanti anche quella data a CSM", puo(chiama(CLI, 'POST', f"/tasks/{tk['id']}/status", {'status': 'open'})))
tu = chiama(UFF, 'POST', '/tasks', {'name': 'Ordine bulloneria', 'assegnata_a': 'persona', 'assignee_user_id': UFF})['data']['id']
verifica(S, "la squadra non porta avanti quella data a un'altra persona", chiama(CAPO, 'POST', f'/tasks/{tu}/status', {'status': 'complete'})['_http'] == 403)
verifica(S, "l'ufficio la porta avanti", puo(chiama(UFF, 'POST', f"/tasks/{tk['id']}/status", {'status': 'in_progress'})))
chiama(CLI, 'POST', f"/tasks/{tk['id']}/update", {'name': 'Spostare scaffale 4', 'status': 'verified'})
verifica(S, '"verificato" non passa dalla modifica', sql(f"SELECT status FROM bb_zone_tasks WHERE id={tk['id']}")[0][0] == 'in_progress')
verifica(S, 'il cliente non cancella', chiama(CLI, 'POST', f'/tasks/{t1}/delete')['_http'] == 403)
# CSM fa tutto quello che fa chi ha l'attivita': anche su una creata dal
# cliente e data a una persona precisa
tp = chiama(CLI, 'POST', '/tasks', {'name': 'Controllo ancoraggi', 'assegnata_a': 'persona', 'assignee_user_id': CAPO})['data']['id']
verifica(S, 'CSM modifica un\'attività creata da un altro', puo(chiama(UFF, 'POST', f'/tasks/{tp}/update', {'name': 'Controllo ancoraggi campata 2'})))
verifica(S, 'CSM completa un\'attività data a un\'altra persona', puo(chiama(UFF, 'POST', f'/tasks/{tp}/status', {'status': 'complete'})))
verifica(S, '... e la verifica', puo(chiama(UFF, 'POST', f'/tasks/{tp}/status', {'status': 'verified'})))
tb = chiama(UFF, 'POST', '/tasks', {'name': 'Attività Bianchi'}, w=200)['data']['id']
verifica(S, "dall'indirizzo di un cantiere non si cancella l'attività di un altro", not puo(chiama(UFF, 'POST', f'/tasks/{tb}/delete')))

S = '3. Il filo di un\'attività'
prima_n = {u: len(notifiche(u)) for u in (UFF, CAPO, CLI)}
chiama(CAPO, 'POST', f'/tasks/{t1}/comments', {'text': 'Fatto, foto domani'})
chiama(CLI, 'POST', f'/tasks/{t1}/comments', {'text': 'Ottimo, grazie'})
chiama(UFF, 'POST', f'/tasks/{t1}/comments', {'text': 'Fatturare a parte', 'interna': 1})
fk = [c['text'] for c in chiama(CLI, 'GET', f'/tasks/{t1}/comments')['data']]
fc = [c['text'] for c in chiama(CAPO, 'GET', f'/tasks/{t1}/comments')['data']]
verifica(S, 'il cliente legge la squadra', 'Fatto, foto domani' in fk)
verifica(S, 'la squadra legge il cliente', 'Ottimo, grazie' in fc)
verifica(S, 'la nota interna non esce dall\'ufficio', 'Fatturare a parte' not in fk + fc)
nuove = lambda u: [t for t, _ in notifiche(u)][prima_n[u]:]
verifica(S, "messaggi di squadra e cliente avvisano l'ufficio (che l'ha creata)", len(nuove(UFF)) == 2, str(nuove(UFF)))
verifica(S, "la nota interna non avvisa nessuno fuori dall'ufficio", len(nuove(CAPO)) == 0 and len(nuove(CLI)) == 0, str(nuove(CAPO)) + str(nuove(CLI)))
chiama(CAPO, 'POST', f"/tasks/{tk['id']}/comments", {'text': 'Lo spostiamo giovedì'})
verifica(S, "risposta della squadra sull'attività del cliente → avvisa il cliente", len(nuove(CLI)) == 1, str(nuove(CLI)))

S = '4. Notifiche'
n_capo, n_cli, n_uff = notifiche(CAPO), notifiche(CLI), notifiche(UFF)
verifica(S, "attività per la squadra → avviso al capo, link all'attività nel cantiere", any(l.startswith('/worksites/100/zone#attivita-') for _, l in n_capo), str(n_capo))
verifica(S, 'attività per il cliente → avviso al cliente, link al cantiere', any(l.startswith('/worksites/100/zone#attivita-') for _, l in n_cli), str(n_cli))
verifica(S, "attività del cliente per l'ufficio → avviso all'ufficio", len(n_uff) >= 1, str(n_uff))
verifica(S, "il cliente riceve l'email", len(email('paolo@rossi.it')) == 1, str(email('paolo@rossi.it')))
chiama(UFF, 'POST', '/tasks', {'name': 'Seconda per il cliente', 'assegnata_a': 'cliente'})
verifica(S, "una sola email finché non legge", len(email('paolo@rossi.it')) == 1)
verifica(S, 'nessun avviso a chi non vede ("solo ufficio")', not any('Costi interni' in t for t, _ in n_capo + n_cli))

S = '5. Moduli'
a = chiama(UFF, 'POST', '/forms/assegnazioni', {'template_id': 2, 'a_ruolo': 'cantiere', 'frequenza': 'giornaliera'})
aid = a['data']['id']
ac, ak = chiama(CAPO, 'GET', '/forms/assegnazioni')['data'], chiama(CLI, 'GET', '/forms/assegnazioni')['data']
verifica(S, 'il capo vede il modulo da compilare', any(int(x['id']) == aid and not x.get('solo_stato') for x in ac))
verifica(S, 'il cliente non vede i moduli della squadra da compilare', not any(int(x['id']) == aid for x in ak))
verifica(S, 'il capo lo compila', puo(chiama(CAPO, 'POST', '/forms/2/submit', {'values': {'f1': 'ok'}, 'assegnazione_id': aid})))
verifica(S, 'un altro modulo non vale per quella assegnazione', chiama(CAPO, 'POST', '/forms/1/submit', {'values': {}, 'assegnazione_id': aid})['_http'] == 403)
verifica(S, 'fuori dall\'ufficio non si compila senza assegnazione', chiama(CLI, 'POST', '/forms/1/submit', {'values': {}})['_http'] == 403)
verifica(S, 'il cliente non assegna i moduli di CSM', chiama(CLI, 'POST', '/forms/assegnazioni', {'template_id': 2, 'a_ruolo': 'cantiere'})['_http'] == 403)
verifica(S, 'il cliente non cancella i modelli di CSM', chiama(CLI, 'POST', '/forms/1/delete')['_http'] == 403)
verifica(S, "l'ufficio non tocca il modello di un altro cantiere da qui", not puo(chiama(UFF, 'POST', '/forms/3/delete')))
sk = chiama(CLI, 'GET', '/forms/submissions')['data']
verifica(S, 'la risposta del capo la legge anche il cliente (di base tutti)', len(sk) == 1)
verifica(S, 'chi compila rivede la sua risposta', len(chiama(CAPO, 'GET', '/forms/submissions')['data']) == 1)

S = '5c. Presenze scritte a mano'
import datetime
oggi = datetime.date.today().isoformat()
p1 = chiama(UFF, 'POST', '/presenze', {'data': oggi, 'nome': 'Mario Bianchi', 'azienda': 'CSM'})
p2 = chiama(UFF, 'POST', '/presenze', {'data': oggi, 'azienda': 'Ponteggi Sud', 'persone': 4, 'mezza': True})
verifica(S, 'CSM registra una persona e un\'azienda', puo(p1) and puo(p2), str(p1)[:150])
verifica(S, 'di base il committente non vede le presenze', chiama(CLI, 'GET', f'/presenze?data={oggi}')['_http'] == 403 and chiama(CLI, 'GET', '/presenze/giorni')['_http'] == 403)
verifica(S, '... e nel suo riepilogo non ci sono', 'giornate di lavoro' not in chiama(CLI, 'GET', '/report?periodo=30').get('raw', ''))
chiama(UFF, 'POST', '/presenze/cliente', {'visibile': True})
gk = chiama(CLI, 'GET', f'/presenze?data={oggi}')['data']
verifica(S, 'il committente le legge', 'Mario Bianchi' in [x['nome'] for x in gk['nostri']] and any(x['azienda'] == 'Ponteggi Sud' and x['persone'] == 4 for x in gk['consorziate']), str(gk)[:300])
verifica(S, "l'azienda a mezza giornata conta 2 giornate su 4 persone", any(x['azienda'] == 'Ponteggi Sud' and x.get('mezza') for x in gk['consorziate']))
verifica(S, 'le presenze vecchie del registro non compaiono, solo quelle scritte a mano', all(x['nome'] != 'Hoxha Arben' for x in gk['nostri']) and all(x.get('manuale') for x in gk['nostri'] + gk['consorziate']), str(gk)[:300])
verifica(S, 'il giorno compare nel calendario', oggi in [x['data'] for x in chiama(CLI, 'GET', '/presenze/giorni')['data']])
verifica(S, 'il committente non registra presenze', chiama(CLI, 'POST', '/presenze', {'data': oggi, 'nome': 'x'})['_http'] == 403)
verifica(S, 'la squadra non registra presenze', chiama(CAPO, 'POST', '/presenze', {'data': oggi, 'nome': 'x'})['_http'] == 403)
domani = (datetime.date.today() + datetime.timedelta(days=1)).isoformat()
verifica(S, 'niente presenze nel futuro', not puo(chiama(UFF, 'POST', '/presenze', {'data': domani, 'nome': 'x'})))
verifica(S, 'il committente non le elimina', chiama(CLI, 'POST', f"/presenze/{p1['data']['id']}/elimina")['_http'] == 403)
verifica(S, 'CSM le elimina', puo(chiama(UFF, 'POST', f"/presenze/{p1['data']['id']}/elimina")))
verifica(S, '... non quelle di un altro cantiere', not puo(chiama(UFF, 'POST', f"/presenze/{p2['data']['id']}/elimina", w=200)))

S = '5d. Notifiche nella Zone'
ta = chiama(CLI, 'POST', '/tasks', {'name': 'Controllo quote solaio'})['data']['id']
av = chiama(UFF, 'GET', '/avvisi')['data']
mia = [x for x in av['avvisi'] if x['dove'] == f'attivita-{ta}']
verifica(S, "l'attività nuova del committente arriva a CSM nel campanello del cantiere", bool(mia) and not mia[0]['letta'], str(av)[:300])
verifica(S, '... e conta sul menu Attività', av['per_sezione']['attivita'] >= 1)
verifica(S, 'gli avvisi di un altro cantiere non compaiono', all(x['dove'] != f'attivita-{ta}' for x in chiama(UFF, 'GET', '/avvisi', w=200)['data']['avvisi']))
tq2 = chiama(UFF, 'POST', '/tasks', {'name': 'Pulizia area carico', 'assegnata_a': 'squadra'})['data']['id']
avc = chiama(CAPO, 'GET', '/avvisi')['data']
verifica(S, 'anche la squadra le trova nel cantiere', any(x['dove'] == f'attivita-{tq2}' for x in avc['avvisi']), str(avc)[:300])
chiama(UFF, 'POST', '/avvisi/letti', {'id': mia[0]['id']} if mia else {})
verifica(S, 'cliccandola diventa letta', all(x['letta'] for x in chiama(UFF, 'GET', '/avvisi')['data']['avvisi'] if x['dove'] == f'attivita-{ta}'))
chiama(UFF, 'POST', '/avvisi/letti', {'sezione': 'attivita'})
verifica(S, 'aprendo Attività si azzera il numero', chiama(UFF, 'GET', '/avvisi')['data']['per_sezione']['attivita'] == 0)
verifica(S, 'non si segnano gli avvisi degli altri', chiama(CLI, 'POST', '/avvisi/letti', {})['_http'] == 200 and not all(x['letta'] for x in chiama(CAPO, 'GET', '/avvisi')['data']['avvisi']))
capo_ids = [x['id'] for x in chiama(CAPO, 'GET', '/avvisi')['data']['avvisi']]
if capo_ids:
    chiama(CLI, 'POST', '/avvisi/elimina', {'id': capo_ids[0]})
verifica(S, 'non si eliminano gli avvisi degli altri', bool(capo_ids) and capo_ids[0] in [x['id'] for x in chiama(CAPO, 'GET', '/avvisi')['data']['avvisi']])
if mia:
    chiama(UFF, 'POST', '/avvisi/elimina', {'id': mia[0]['id']})
verifica(S, 'una notifica si elimina', bool(mia) and all(x['id'] != mia[0]['id'] for x in chiama(UFF, 'GET', '/avvisi')['data']['avvisi']))
chiama(UFF, 'POST', '/avvisi/letti', {})
chiama(UFF, 'POST', '/avvisi/elimina', {'lette': True})
verifica(S, 'le notifiche lette si eliminano tutte insieme', chiama(UFF, 'GET', '/avvisi')['data']['avvisi'] == [])

S = '5e. Notifiche di file'
rf = chiama(CLI, 'POST', '/files', file={'nome': 'computo_metrico.pdf'})
fid = int(rf['data']['id']) if puo(rf) else 0
verifica(S, 'il file caricato dal committente arriva a CSM', any(x['dove'] == f'file-{fid}' for x in chiama(UFF, 'GET', '/avvisi')['data']['avvisi']))
verifica(S, '... e conta sul menu File', chiama(UFF, 'GET', '/avvisi')['data']['per_sezione']['files'] >= 1)
verifica(S, 'chi lo carica non avvisa se stesso', all(x['dove'] != f'file-{fid}' for x in chiama(CLI, 'GET', '/avvisi')['data']['avvisi']))
rq = chiama(CAPO, 'POST', '/files', file={'nome': 'foto_lavori.pdf'})
qid = int(rq['data']['id']) if puo(rq) else 0
vede_cli = qid in [int(x['id']) for x in chiama(CLI, 'GET', '/files')['data']['files']]
avvisato = any(x['dove'] == f'file-{qid}' for x in chiama(CLI, 'GET', '/avvisi')['data']['avvisi'])
verifica(S, 'il committente è avvisato di un file della squadra solo se lo vede', vede_cli == avvisato, f'vede={vede_cli} avvisato={avvisato}')
fp = chiama(UFF, 'POST', '/files/folder', {'name': 'Riservata', 'parti': [], 'persone': []})['data']['id']
rp = chiama(UFF, 'POST', '/files', file={'nome': 'offerta_interna.pdf', 'campi': {'folder_id': fp}})
pid = int(rp['data']['id']) if puo(rp) else -1
verifica(S, 'un file in una cartella riservata a CSM non avvisa né committente né squadra',
         puo(rp) and all(x['dove'] != f'file-{pid}' for u in (CLI, CAPO) for x in chiama(u, 'GET', '/avvisi')['data']['avvisi']))
verifica(S, 'CSM è avvisato del file della squadra', any(x['dove'] == f'file-{qid}' for x in chiama(UFF, 'GET', '/avvisi')['data']['avvisi']))

S = '5f. Disegni con DWG non convertito'
sql("INSERT INTO bb_worksite_documents(id,worksite_id,file_name,file_path,file_type,subcategory,created_by,current_version,category) VALUES (50,100,'Pianta.dwg','C-118/Disegni/piante/Pianta.dwg','dwg','piante',5,1,'disegni')")
sql("INSERT INTO bb_zone_condivisioni_ruoli(worksite_id,tipo,oggetto_id,ruolo) VALUES (100,'disegno',50,'cliente'),(100,'disegno',50,'cantiere')")
sql("INSERT INTO bb_zone_dwg_render(document_id,status,error) VALUES (50,'error','dwg2dxf non trovato')")
du = chiama(UFF, 'GET', '/disegni')
dk = chiama(CLI, 'GET', '/disegni')
verifica(S, "l'elenco dei disegni si apre anche con un DWG non convertito", puo(du) and puo(dk), str(du)[:200])
x = [d for d in (du.get('data') or {}).get('bob', du.get('data') or []) if isinstance(d, dict) and d.get('id') == 50] if puo(du) else []
verifica(S, 'CSM legge il motivo', bool(x) and x[0].get('dwg_error') == 'dwg2dxf non trovato', str(x)[:200])
y = [d for d in (dk.get('data') or {}).get('bob', dk.get('data') or []) if isinstance(d, dict) and d.get('id') == 50] if puo(dk) else []
verifica(S, 'il committente no', bool(y) and not y[0].get('dwg_error'), str(y)[:200])

S = '5g. Cronologia, presenze corrette, segnalazioni eliminate'
tc = chiama(UFF, 'POST', '/tasks', {'name': 'Fissare parapetti', 'assegnata_a': 'squadra'})['data']['id']
chiama(CAPO, 'POST', f'/tasks/{tc}/status', {'status': 'in_progress'})
chiama(CLI, 'POST', f'/tasks/{tc}/status', {'status': 'complete'})
tt = [t for t in chiama(UFF, 'GET', '/tasks')['data'] if int(t['id']) == tc][0]
verifica(S, "l'attività dice chi l'ha creata", tt.get('creata_da_nome') == 'Giulia Ufficio', str(tt.get('creata_da_nome')))
cr = [(e['chi'], e['da'], e['a']) for e in tt.get('cronologia', [])]
verifica(S, 'e chi l\'ha portata avanti e chiusa', cr == [('Arben Hoxha', 'open', 'in_progress'), ('Paolo Rossi', 'in_progress', 'complete')], str(cr))
chiama(CLI, 'POST', f'/tasks/{tc}/status', {'status': 'complete'})
verifica(S, 'rimettere lo stesso stato non scrive niente', len([t for t in chiama(UFF, 'GET', '/tasks')['data'] if int(t['id']) == tc][0]['cronologia']) == 2)

pe = chiama(UFF, 'POST', '/presenze', {'data': oggi, 'nome': 'Marco Neri', 'azienda': 'CSM'})['data']['id']
verifica(S, 'CSM corregge una presenza', puo(chiama(UFF, 'POST', f'/presenze/{pe}', {'data': oggi, 'nome': 'Marco Neri', 'azienda': 'CSM', 'mezza': True})))
gp = chiama(UFF, 'GET', f'/presenze?data={oggi}')['data']
verifica(S, '... e la correzione si legge', any(x.get('id') == pe and x['mezza'] for x in gp['nostri']), str(gp['nostri'])[:300])
verifica(S, 'il committente non corregge presenze', chiama(CLI, 'POST', f'/presenze/{pe}', {'data': oggi, 'nome': 'x'})['_http'] == 403)
verifica(S, 'non si corregge la presenza di un altro cantiere', not puo(chiama(UFF, 'POST', f'/presenze/{pe}', {'data': oggi, 'nome': 'x'}, w=200)))

sg = chiama(CLI, 'POST', '/segnalazioni', {'testo': 'Cartello mancante', 'tipo': 'sicurezza', 'gravita': 'bassa'})['data']
sid = sg['id'] if isinstance(sg, dict) and 'id' in sg else sg.get('segnalazione', {}).get('id')
verifica(S, 'la squadra non elimina la segnalazione del committente', chiama(CAPO, 'POST', f'/segnalazioni/{sid}/elimina', {})['_http'] == 403)
verifica(S, 'chi l\'ha scritta la elimina', puo(chiama(CLI, 'POST', f'/segnalazioni/{sid}/elimina', {})))
verifica(S, '... e non c\'è più, né i suoi avvisi', chiama(UFF, 'GET', f'/segnalazioni/{sid}')['_http'] == 404 and all(x['dove'] != f'segnalazione-{sid}' for x in chiama(UFF, 'GET', '/avvisi')['data']['avvisi']))
s2 = chiama(CAPO, 'POST', '/segnalazioni', {'testo': 'Manca tirante', 'tipo': 'materiale', 'gravita': 'bassa'})['data']
sid2 = s2['id'] if 'id' in s2 else s2.get('segnalazione', {}).get('id')
verifica(S, 'CSM elimina qualsiasi segnalazione', puo(chiama(UFF, 'POST', f'/segnalazioni/{sid2}/elimina', {})))

S = '5h. Presenze al committente sì o no'
verifica(S, 'acceso da CSM, il committente vede le presenze', puo(chiama(CLI, 'GET', '/presenze/giorni')))
verifica(S, 'il committente non spegne la condivisione', chiama(CLI, 'POST', '/presenze/cliente', {'visibile': False})['_http'] == 403)
verifica(S, 'CSM la spegne', puo(chiama(UFF, 'POST', '/presenze/cliente', {'visibile': False})))
verifica(S, '... e il committente non le legge più', chiama(CLI, 'GET', '/presenze/giorni')['_http'] == 403 and chiama(CLI, 'GET', '/presenze')['_http'] == 403)
verifica(S, '... la squadra sì', puo(chiama(CAPO, 'GET', '/presenze/giorni')))
verifica(S, '... e nel riepilogo del committente non ci sono', 'giornate di lavoro' not in chiama(CLI, 'GET', '/report?periodo=30').get('raw', ''))
verifica(S, 'CSM legge lo stato dell\'interruttore', chiama(UFF, 'GET', '/presenze')['data'].get('al_cliente') is False)
chiama(UFF, 'POST', '/presenze/cliente', {'visibile': True})
verifica(S, 'riaccesa, il committente le rivede', puo(chiama(CLI, 'GET', '/presenze/giorni')))

S = '5b. Moduli del committente'
campo = [{'id': 'f1', 'type': 'text', 'label': 'Note'}]
mc = chiama(CLI, 'POST', '/forms', {'name': 'Verbale committente', 'fields': campo})
verifica(S, 'il committente crea un modulo per il cantiere', puo(mc), str(mc)[:200])
mc = mc['data']['id'] if puo(mc) else 0
mu = chiama(CLI, 'POST', '/forms', {'name': 'Sopralluogo committente', 'fields': campo, 'universal': True})
mu = mu['data']['id'] if puo(mu) else 0
verifica(S, '... e uno per tutti i suoi cantieri', mu > 0 and sql(f"SELECT worksite_id, client_id FROM bb_zone_form_templates WHERE id={mu}") == [['NULL', '1']])
nomi = lambda chi, w=100: [t['name'] for t in chiama(chi, 'GET', '/forms', w=w)['data']]
verifica(S, 'il committente vede i moduli della sua azienda, non quelli interni di CSM', set(nomi(CLI)) == {'Verbale committente', 'Sopralluogo committente'}, str(nomi(CLI)))
verifica(S, 'CSM li vede nel cantiere', {'Verbale committente', 'Sopralluogo committente'} <= set(nomi(UFF)))
verifica(S, 'quello "per tutti i suoi cantieri" non compare nei cantieri di un altro committente', 'Sopralluogo committente' not in nomi(UFF, 200))
verifica(S, 'il committente modifica il suo modulo', puo(chiama(CLI, 'POST', '/forms', {'id': mc, 'name': 'Verbale committente', 'fields': campo + [{'id': 'f2', 'type': 'date', 'label': 'Data'}]})))
verifica(S, 'non modifica quelli di CSM', chiama(CLI, 'POST', '/forms', {'id': 2, 'name': 'x', 'fields': campo})['_http'] == 403)
verifica(S, 'compila il suo modulo senza assegnazione', puo(chiama(CLI, 'POST', f'/forms/{mc}/submit', {'values': {'f1': 'ok'}})))
ac = chiama(CLI, 'POST', '/forms/assegnazioni', {'template_id': mc, 'a_ruolo': 'cantiere', 'frequenza': 'settimanale'})
verifica(S, 'lo assegna alla squadra', puo(ac), str(ac)[:200])
verifica(S, '... e il capo lo trova da compilare', mc in [int(x['template_id']) for x in chiama(CAPO, 'GET', '/forms/assegnazioni')['data']])
verifica(S, 'non assegna i moduli di CSM', chiama(CLI, 'POST', '/forms/assegnazioni', {'template_id': 2, 'a_ruolo': 'cantiere'})['_http'] == 403)
verifica(S, "non toglie l'assegnazione di CSM", chiama(CLI, 'POST', f'/forms/assegnazioni/{aid}/disattiva')['_http'] == 403)
mia = [x for x in chiama(CLI, 'GET', '/forms/assegnazioni')['data'] if int(x['template_id']) == mc]
verifica(S, 'toglie la sua', mia and puo(chiama(CLI, 'POST', f"/forms/assegnazioni/{mia[0]['id']}/disattiva")))
verifica(S, 'la squadra non crea moduli', chiama(CAPO, 'POST', '/forms', {'name': 'x', 'fields': campo})['_http'] == 403)
verifica(S, 'il committente cancella il suo modulo', puo(chiama(CLI, 'POST', f'/forms/{mu}/delete')))

S = '6. File'
f1 = chiama(UFF, 'POST', '/files/folder', {'name': 'Verbali'})['data']['id']
f2 = chiama(UFF, 'POST', '/files/folder', {'name': 'Contratti', 'parti': [], 'persone': []})['data']['id']
cart_k = {int(c['id']) for c in chiama(CLI, 'GET', '/files')['data']['folders']}
verifica(S, 'cartella normale: la vede il cliente', f1 in cart_k)
verifica(S, 'cartella "solo ufficio": il cliente no', f2 not in cart_k)
r = chiama(CLI, 'POST', '/files', file={'nome': 'foto_scaffale.pdf'})
verifica(S, 'il cliente carica un file', puo(r), r.get('error', ''))
fc = [x['file_name'] for x in chiama(CAPO, 'GET', '/files')['data']['files']]
verifica(S, 'la squadra vede il file del cliente', 'foto_scaffale.pdf' in fc)
verifica(S, 'il cliente non carica in "Contratti"', not puo(chiama(CLI, 'POST', '/files', file={'nome': 'x.pdf', 'campi': {'folder_id': f2}})))

S = '6a. Documenti come un cloud'
sub1 = chiama(UFF, 'POST', '/files/folder', {'name': 'Verbali di collaudo', 'parent_id': f1})['data']['id']
sub2 = chiama(UFF, 'POST', '/files/folder', {'name': 'Riservati', 'parent_id': f2})['data']['id']
cart_k = {int(c['id']): c for c in chiama(CLI, 'GET', '/files')['data']['folders']}
verifica(S, 'una cartella dentro "Verbali" la vede chi vede "Verbali"', sub1 in cart_k and int(cart_k[sub1]['parent_id']) == f1, str(list(cart_k)))
verifica(S, 'una cartella dentro "Contratti" (solo CSM) il committente non la vede', sub2 not in cart_k)
verifica(S, 'il committente non crea cartelle dentro una cartella che non vede', not puo(chiama(CLI, 'POST', '/files/folder', {'name': 'x', 'parent_id': f2})))
sub3 = chiama(UFF, 'POST', '/files/folder', {'name': 'Foto consegna', 'parent_id': sub1})['data']['id']
sql(f"DELETE FROM bb_zone_condivisioni_ruoli WHERE tipo='cartella' AND oggetto_id={sub1}; DELETE FROM bb_zone_condivisioni WHERE tipo='cartella' AND oggetto_id={sub1}")
cart_k = {int(c['id']) for c in chiama(CLI, 'GET', '/files')['data']['folders']}
verifica(S, 'chiudendo una cartella si chiude anche quello che contiene', sub1 not in cart_k and sub3 not in cart_k, str(cart_k))
sub4 = chiama(CLI, 'POST', '/files/folder', {'name': 'Moduli impresa', 'parent_id': f1})['data']['id']
mod = chiama(CLI, 'POST', '/forms', {'name': 'Verbale di consegna', 'fields': campo, 'folder_id': sub4})
verifica(S, 'il committente crea un modulo dentro una sua cartella', puo(mod), mod.get('error', ''))
in_sub = [t['name'] for t in chiama(CLI, 'GET', f'/files?folder={sub4}')['data']['moduli']]
in_cima = [t['name'] for t in chiama(CLI, 'GET', '/files')['data']['moduli']]
verifica(S, '... il modulo sta in quella cartella, non in cima', 'Verbale di consegna' in in_sub and 'Verbale di consegna' not in in_cima, str(in_sub) + str(in_cima))
verifica(S, 'non si mette un modulo in una cartella che non si vede', not puo(chiama(CLI, 'POST', '/forms', {'name': 'x', 'fields': campo, 'folder_id': f2})))
verifica(S, 'la squadra non vede i modelli nelle cartelle (compila quelli assegnati)', chiama(CAPO, 'GET', f'/files?folder={f1}')['data']['moduli'] == [])
chiama(UFF, 'POST', f'/files/folder/{sub4}/delete', {})
in_f1 = [t['name'] for t in chiama(CLI, 'GET', f'/files?folder={f1}')['data']['moduli']]
verifica(S, 'eliminando la cartella, il modulo sale nella cartella che la conteneva', 'Verbale di consegna' in in_f1, str(in_f1))

S = '6b. Richieste nelle cartelle'
pulisci_notifiche()
rq = chiama(CLI, 'POST', '/richieste', file={'nome': 'modulo_collaudo.pdf', 'campi': {'folder_id': f1, 'titolo': 'Modulo di collaudo', 'testo': 'Vi mandiamo il modulo da compilare entro venerdì.'}})
verifica(S, 'il committente crea una richiesta in una cartella, con un documento', puo(rq), rq.get('error', ''))
rid = rq['data']['id'] if puo(rq) else 0
nr = [n for n in notifiche(UFF) if n[0] == 'Nuova richiesta']
verifica(S, 'CSM riceve "Nuova richiesta", che porta alla richiesta', len(nr) == 1 and nr[0][1].endswith(f'#richiesta-{rid}'), str(notifiche(UFF)))
verifica(S, '... la squadra no (la richiesta del committente va a CSM)', not [n for n in notifiche(CAPO) if n[0] == 'Nuova richiesta'])
fu = chiama(UFF, 'GET', f'/files?folder={f1}')['data']
verifica(S, "l'allegato è un documento della cartella", 'modulo_collaudo.pdf' in [x['file_name'] for x in fu['files']])
verifica(S, 'la cartella elenca la richiesta e conta quelle aperte', [x['id'] for x in fu['richieste']] == [rid] and fu['richieste_aperte'].get(str(f1), fu['richieste_aperte'].get(f1)) == 1, str(fu['richieste_aperte']))
verifica(S, 'non si crea una richiesta in una cartella che non si vede', not puo(chiama(CLI, 'POST', '/richieste', form={'folder_id': f2, 'titolo': 'x', 'testo': 'y'})))
rc = chiama(CLI, 'POST', '/richieste', form={'titolo': 'Programma lavori', 'testo': 'Ci inviate il programma della prossima settimana?'})
verifica(S, 'si crea una richiesta anche fuori dalle cartelle, in cima ai Documenti', puo(rc), rc.get('error', ''))
rcid = rc['data']['id'] if puo(rc) else 0
verifica(S, '... la vede CSM, non la squadra', rcid in [x['id'] for x in chiama(UFF, 'GET', '/files')['data']['richieste']]
         and rcid not in [x['id'] for x in chiama(CAPO, 'GET', '/files')['data']['richieste']] and chiama(CAPO, 'GET', f'/richieste/{rcid}')['_http'] == 404)
verifica(S, 'una risposta vuota non si invia', not puo(chiama(UFF, 'POST', f'/richieste/{rid}/messaggi', form={'testo': ' '})))
pulisci_notifiche()
rr = chiama(UFF, 'POST', f'/richieste/{rid}/messaggi', file={'nome': 'modulo_collaudo (compilato).pdf', 'campi': {'testo': 'Ecco il modulo compilato.', 'completa': '1'}})
verifica(S, 'CSM risponde con il documento compilato e la completa', puo(rr), rr.get('error', ''))
tk = [n[0] for n in notifiche(CLI)]
verifica(S, 'il committente riceve la risposta e "Richiesta completata"', 'Nuova risposta a una richiesta' in tk and 'Richiesta completata' in tk, str(tk))
dk = chiama(CLI, 'GET', f'/richieste/{rid}')['data']
verifica(S, 'nella discussione: stato completata, due messaggi con i documenti, e la riga di chiusura',
         dk['richiesta']['stato'] == 'completata' and [len(m['allegati']) for m in dk['messaggi'] if not m['sistema']] == [1, 1]
         and dk['messaggi'][-1]['sistema'] == 'completata', str(dk)[:400])
verifica(S, 'una richiesta completata sparisce dalla cartella (i documenti restano)', rid not in [x['id'] for x in chiama(UFF, 'GET', f'/files?folder={f1}')['data']['richieste']]
         and 'modulo_collaudo.pdf' in [x['file_name'] for x in chiama(UFF, 'GET', f'/files?folder={f1}')['data']['files']])
verifica(S, 'gli allegati si scaricano dalla cartella', dk['messaggi'][0]['allegati'][0]['download_url'].endswith('/download'))
verifica(S, 'la squadra che vede la cartella legge la richiesta', puo(chiama(CAPO, 'GET', f'/richieste/{rid}')))
verifica(S, '... ma non la elimina (non è sua)', not puo(chiama(CAPO, 'POST', f'/richieste/{rid}/elimina', {})))
verifica(S, 'si riapre', puo(chiama(CLI, 'POST', f'/richieste/{rid}/stato', {'stato': 'aperta'})) and chiama(CLI, 'GET', f'/richieste/{rid}')['data']['richiesta']['stato'] == 'aperta')
orig_id = [x for x in chiama(UFF, 'GET', f'/files?folder={f1}')['data']['files'] if x['file_name'] == 'modulo_collaudo.pdf'][0]['id']
comp = [x for x in chiama(UFF, 'GET', f'/files?folder={f1}')['data']['files'] if x['file_name'] == 'modulo_collaudo (compilato).pdf']
verifica(S, "i documenti arrivati con una richiesta ne portano l'oggetto", comp and comp[0]['richiesta_titolo'] == 'Modulo di collaudo', str(comp)[:200])
vr = chiama(UFF, 'POST', f'/richieste/{rid}/messaggi', file={'nome': 'modulo_collaudo (compilato).pdf', 'campi': {'testo': 'Versione corretta', 'da_file_id': orig_id}})
cv = sql(f"SELECT origine_file_id, richiesta_id FROM bb_zone_files WHERE richiesta_id={rid} ORDER BY id DESC LIMIT 1")
verifica(S, "la copia compilata da una richiesta è una versione dell'originale", puo(vr) and cv == [[str(orig_id), str(rid)]], str(cv))
sub_v = chiama(UFF, 'POST', '/files', file={'nome': 'collaudo (compilato).pdf', 'campi': {'da_file_id': orig_id}})
sv = sql(f"SELECT origine_file_id, folder_id FROM bb_zone_files WHERE id={sub_v['data']['id']}") if puo(sub_v) else []
verifica(S, "compilando un PDF della cartella la copia va nella stessa cartella, come versione", sv == [[str(orig_id), str(f1)]], str(sv))
verifica(S, 'non si compila da un documento che non si vede', not puo(chiama(CLI, 'POST', '/files', file={'nome': 'x.pdf', 'campi': {'da_file_id': 999999}})))
f3 = chiama(UFF, 'POST', '/files/folder', {'name': 'Documenti impresa'})['data']['id']
r3 = chiama(CLI, 'POST', '/richieste', form={'folder_id': f3, 'titolo': 'DURC aggiornato', 'testo': 'Ci serve il DURC aggiornato.'})['data']['id']
sql(f"DELETE FROM bb_zone_condivisioni_ruoli WHERE tipo='cartella' AND oggetto_id={f3}; DELETE FROM bb_zone_condivisioni WHERE tipo='cartella' AND oggetto_id={f3}")
verifica(S, 'chiusa la cartella, il committente non vede più la richiesta', chiama(CLI, 'GET', f'/richieste/{r3}')['_http'] == 404)
verifica(S, 'CSM elimina una richiesta; i documenti restano nella cartella', puo(chiama(UFF, 'POST', f'/richieste/{rid}/elimina', {}))
         and 'modulo_collaudo.pdf' in [x['file_name'] for x in chiama(UFF, 'GET', f'/files?folder={f1}')['data']['files']])

S = '6c. Link condiviso collegato al cantiere'
verifica(S, 'solo CSM sceglie il link', chiama(CLI, 'GET', '/link-condivisi')['_http'] == 403 and puo(chiama(UFF, 'GET', '/link-condivisi')))
verifica(S, 'il committente non collega link', not puo(chiama(CLI, 'POST', '/link-condivisi', {'link_id': 1})))
verifica(S, 'CSM collega il link', puo(chiama(UFF, 'POST', '/link-condivisi', {'link_id': 1})))
cu = {c['name']: c for c in chiama(UFF, 'GET', '/files')['data']['folders']}
verifica(S, 'compaiono "Documenti azienda" con una cartella per azienda', 'Documenti azienda' in cu and 'Edilnord Srl' in cu and 'Montaggi Sud Srl' in cu
         and int(cu['Edilnord Srl']['parent_id']) == int(cu['Documenti azienda']['id']), str(list(cu)))
verifica(S, '... e "Documenti operai" con nome e azienda di ogni operaio', 'Arben Hoxha · CSM' in cu and 'Ion Popescu · Edilnord' in cu, str(list(cu)))
ck = {c['name'] for c in chiama(CLI, 'GET', '/files')['data']['folders']}
verifica(S, 'il committente le vede', 'Documenti operai' in ck and 'Ion Popescu · Edilnord' in ck, str(ck))
cq = {c['name'] for c in chiama(CAPO, 'GET', '/files')['data']['folders']}
verifica(S, 'la squadra no (documenti personali)', 'Documenti operai' not in cq and 'Arben Hoxha · CSM' not in cq, str(cq))
ed = chiama(CLI, 'GET', f"/files?folder={cu['Edilnord Srl']['id']}")['data']['documenti_link']
verifica(S, "nella cartella dell'azienda i suoi documenti, con lo stato della scadenza", {d['nome']: d['stato'] for d in ed if d['tipo'] == 'azienda'} == {'DURC': 'scaduto', 'Visura camerale': 'valido'}, str(ed))
verifica(S, 'non si carica nelle cartelle del link', not puo(chiama(UFF, 'POST', '/files', file={'nome': 'x.pdf', 'campi': {'folder_id': cu['Edilnord Srl']['id']}})))
verifica(S, 'non si eliminano dalla Zone', not puo(chiama(UFF, 'POST', f"/files/folder/{cu['Documenti operai']['id']}/delete", {})))
verifica(S, "i documenti caricati a mano nel link: quelli dell'azienda nella sua cartella", 'Certificato iscrizione' in [d['nome'] for d in ed], str(ed))
verifica(S, '... quelli senza azienda in "Altri documenti"', 'Altri documenti' in cu
         and [d['nome'] for d in chiama(CLI, 'GET', f"/files?folder={cu['Altri documenti']['id']}")['data']['documenti_link']] == ['POS di cantiere'])
def scarica(chi, url):
    req = urllib.request.Request('http://127.0.0.1:8833' + url, headers={'X-Test-User': str(chi)})
    try:
        with urllib.request.urlopen(req) as r: return r.status, r.read()
    except urllib.error.HTTPError as e: return e.code, b''
durc = [d for d in ed if d['nome'] == 'DURC'][0]
st, corpo = scarica(CLI, durc['url'])
verifica(S, 'il committente apre davvero il documento del link (il file vero)', st == 200 and corpo.startswith(b'%PDF') and b'az/durc.pdf' in corpo, str(st))
verifica(S, '... la squadra no', scarica(CAPO, durc['url'])[0] == 404)
verifica(S, 'un documento di un altro link non si apre cambiando il numero', scarica(CLI, durc['url'].rsplit('/', 1)[0] + '/99999')[0] == 404)
rl = chiama(CLI, 'POST', '/richieste', form={'folder_id': cu['Edilnord Srl']['id'], 'titolo': 'DURC scaduto', 'testo': 'Il DURC è scaduto: ci inviate quello aggiornato?'})
verifica(S, 'dentro si crea una richiesta', puo(rl), rl.get('error', ''))
verifica(S, 'un documento del link non si apre senza vedere la sua cartella', chiama(CAPO, 'GET', f"/link-documento/operaio/{ed[0]['id']}")['_http'] == 404)
rl_id = rl['data']['id'] if puo(rl) else 0
dr = [n for n in notifiche(7) if n[0] == 'Documento richiesto']
verifica(S, "una richiesta nella cartella di un'azienda arriva a chi gestisce i Doc condivisi, e porta al link", len(dr) == 1 and dr[0][1] == '/share/1/edit#richieste', str(notifiche(7)))
verifica(S, '... anche per email', len(email('sara@test.it')) >= 1, str(email('sara@test.it')))
sql("INSERT INTO bb_company_documents(company_id,tipo_documento,scadenza,file_path) VALUES (900,'DURC','2027-03-31','az/durc.pdf')")
pulisci_notifiche()
urllib.request.urlopen('http://127.0.0.1:8833/__prova/documento-caricato?chi=azienda&id=900&da=7').read()
rdl = chiama(CLI, 'GET', f'/richieste/{rl_id}')['data']
verifica(S, "caricato in BOB il DURC nuovo dell'azienda, la richiesta si completa da sola", rdl['richiesta']['stato'] == 'completata'
         and any('DURC' in (m.get('testo') or '') and '31/03/2027' in (m.get('testo') or '') for m in rdl['messaggi']), str(rdl)[:400])
verifica(S, '... e chi l\'aveva fatta riceve "Richiesta completata"', any(n[0] == 'Richiesta completata' for n in notifiche(CLI)), str(notifiche(CLI)))
sql("DELETE FROM bb_shared_link_companies WHERE company_id=901")
cu2 = {c['name'] for c in chiama(UFF, 'GET', '/files')['data']['folders']}
verifica(S, "togliendo un'azienda dal link sparisce la sua cartella (vuota)", 'Montaggi Sud Srl' not in cu2 and 'Edilnord Srl' in cu2, str(cu2))
chiama(UFF, 'POST', '/link-condivisi', {'link_id': 0})
cu3 = {c['name'] for c in chiama(UFF, 'GET', '/files')['data']['folders']}
verifica(S, 'scollegando il link restano solo le cartelle con dentro una richiesta', 'Edilnord Srl' in cu3 and 'Ion Popescu · Edilnord' not in cu3, str(cu3))

S = '6d. Spostare documenti e menzioni nei Documenti'
fm = chiama(CLI, 'POST', '/files', file={'nome': 'da_spostare.pdf', 'campi': {'folder_id': f1}})['data']['id']
fdest = chiama(UFF, 'POST', '/files/folder', {'name': 'Archivio'})['data']['id']
verifica(S, 'chi ha caricato un documento lo sposta in un\'altra cartella', puo(chiama(CLI, 'POST', f'/files/{fm}/sposta', {'folder_id': fdest}))
         and sql(f"SELECT folder_id FROM bb_zone_files WHERE id={fm}") == [[str(fdest)]])
verifica(S, 'la squadra non sposta i documenti degli altri', not puo(chiama(CAPO, 'POST', f'/files/{fm}/sposta', {'folder_id': f1})))
verifica(S, 'non si sposta in una cartella che non si vede', not puo(chiama(CLI, 'POST', f'/files/{fm}/sposta', {'folder_id': f2})))
mzf = {x['nome'] for x in chiama(CLI, 'GET', f'/menzionabili?contesto=file&id={fm}')['data']}
verifica(S, 'nei commenti a un documento si menziona chi lo vede', 'Giulia Ufficio' in mzf, str(mzf))
pulisci_notifiche()
chiama(CLI, 'POST', f'/files/{fm}/comments', {'text': '@Giulia Ufficio lo potete controllare?', 'menzioni': [UFF]})
verifica(S, '... e la persona menzionata riceve la notifica', any('menzionato' in n[0] and n[1].endswith(f'#file-{fm}.commenti') for n in notifiche(UFF)), str(notifiche(UFF)))
mzc = {x['nome'] for x in chiama(UFF, 'GET', f'/menzionabili?contesto=cartella&id={f2}')['data']}
verifica(S, 'in una cartella di sola CSM il committente non si menziona', 'Paolo Rossi' not in mzc, str(mzc))
verifica(S, 'il committente non chiede chi legge una cartella che non vede', chiama(CLI, 'GET', f'/menzionabili?contesto=cartella&id={f2}')['_http'] == 403)
mzs = {x['nome'] for x in chiama(CAPO, 'GET', '/menzionabili?contesto=segnalazione_nuova')['data']}
verifica(S, 'scrivendo una segnalazione si menziona chi la leggerà', 'Giulia Ufficio' in mzs and 'Paolo Rossi' in mzs, str(mzs))
pulisci_notifiche()
chiama(CAPO, 'POST', '/segnalazioni', {'testo': '@Paolo Rossi serve il vostro via libera per la gru', 'tipo': 'altro', 'menzioni': [CLI]})
verifica(S, 'menzione in una segnalazione nuova: notifica al committente', any('menzionato' in n[0] for n in notifiche(CLI)), str(notifiche(CLI)))

S = '6e. Notifiche precise'
pulisci_notifiche()
cm = chiama(CLI, 'POST', '/chat/cliente', {'testo': 'Consegna confermata'})
mid = cm['data']['id'] if puo(cm) else 0
verifica(S, 'la notifica di un messaggio porta a quel messaggio', any(n[1].endswith(f'#chat-cliente.m{mid}') for n in notifiche(UFF)), str(notifiche(UFF)))
chiama(UFF, 'GET', '/chat/cliente')   # aperto: il prossimo messaggio avvisa di nuovo
pulisci_notifiche()
ck = chiama(UFF, 'POST', f'/tasks/{ta}/comments', {'text': 'Quote verificate'})
verifica(S, "la notifica di un commento porta a quel commento dell'attività", any(f'#attivita-{ta}.c{ck["data"]["id"]}' in n[1] for n in notifiche(CLI)), str(notifiche(CLI)))
inf = chiama(UFF, 'GET', f'/files/{fm}/info')['data']
verifica(S, 'si sa in che cartella sta un documento, per aprirlo dalla notifica', inf['folder_id'] == fdest, str(inf))
verifica(S, '... ma solo se lo si vede', chiama(ALTRO, 'GET', f'/files/{fm}/info')['_http'] in (403, 404))

S = '6f. Foto da tutto il cantiere e attività da segnalazione'
sql("INSERT INTO bb_zone_messaggi(worksite_id,canale,user_id,testo,foto) VALUES (100,'cliente',10,NULL,'BOBZone/100/photos/a.jpg'),(100,'squadra',30,NULL,'BOBZone/100/photos/b.jpg')")
sfo = chiama(CAPO, 'POST', '/segnalazioni', {'testo': 'Vetro rotto al piano terra', 'tipo': 'danno'})['data']['id']
sql(f"INSERT INTO bb_zone_messaggi(worksite_id,segnalazione_id,user_id,foto) VALUES (100,{sfo},30,'BOBZone/100/photos/c.jpg')")
chiama(CLI, 'POST', '/files', file={'nome': 'foto_facciata.jpg', 'campi': {'folder_id': f1}})
fonti = lambda u: sorted({x['fonte'] for x in chiama(u, 'GET', '/foto')['data']})
verifica(S, 'il committente vede le foto di chat, segnalazioni e documenti', set(fonti(CLI)) >= {'chat', 'segnalazione', 'documento'}, str(fonti(CLI)))
fc = chiama(CLI, 'GET', '/foto')['data']
verifica(S, '... ma non quelle della chat della squadra', not any(x['dove'].startswith('chat-squadra') for x in fc))
verifica(S, 'ogni foto dice dove porta (il messaggio preciso)', all('.m' in x['dove'] for x in fc if x['fonte'] in ('chat', 'segnalazione')), str([x['dove'] for x in fc]))
sp = chiama(CAPO, 'POST', '/segnalazioni', {'testo': 'Manca il parapetto lato nord', 'tipo': 'sicurezza'})['data']['id']
geom_pin = '{"x":0.3,"y":0.2}'
sql(f"INSERT INTO bb_zone_annotations(worksite_id,document_id,page,segnalazione_id,type,geom,created_by) VALUES (100,1,1,{sp},'pin','{geom_pin}',30)")
ta2 = chiama(UFF, 'POST', '/tasks', {'name': 'Montare il parapetto', 'priority': 2, 'da_segnalazione': sp})['data']['id']
ss = sql(f"SELECT task_id, stato FROM bb_zone_segnalazioni WHERE id={sp}")
verifica(S, "attività creata dal modulo completo partendo da una segnalazione: collegata, e la segnalazione è presa in carico", ss == [[str(ta2), 'presa']], str(ss))
verifica(S, "... e l'attività prende il punto della segnalazione sul disegno", sql(f"SELECT document_id FROM bb_zone_annotations WHERE task_id={ta2}") == [['1']])

S = '7. Chat e segnalazioni'
canali = lambda u: [c['canale'] for c in chiama(u, 'GET', '/chat')['data']['canali']]
verifica(S, 'cliente: Ufficio e Tutto il cantiere', canali(CLI) == ['cliente', 'tutti'], str(canali(CLI)))
verifica(S, 'capo: Squadra e Tutti', canali(CAPO) == ['squadra', 'tutti'])
verifica(S, 'ufficio: tutti e tre', canali(UFF) == ['squadra', 'cliente', 'tutti'])
verifica(S, 'il cliente non scrive nella chat della squadra', chiama(CLI, 'POST', '/chat/squadra', {'testo': 'ciao'})['_http'] == 403)
pulisci_notifiche()
chiama(CLI, 'POST', '/chat/cliente', {'testo': 'Domani passiamo alle 9'})
verifica(S, "messaggio del cliente all'ufficio → avvisa l'ufficio", len(notifiche(UFF)) == 1)
verifica(S, '... e non la squadra', len(notifiche(CAPO)) == 0)
s = chiama(CAPO, 'POST', '/segnalazioni', {'testo': 'Manca materiale per la campata 3', 'tipo': 'materiale', 'gravita': 'alta'})['data']
vede_k = lambda: {int(x['id']) for x in chiama(CLI, 'GET', '/segnalazioni')['data']['segnalazioni']}
verifica(S, 'segnalazione del capo: la vede il cliente (di base tutti)', int(s['id']) in vede_k())
chiama(UFF, 'POST', f"/segnalazioni/{s['id']}/visibilita", {'visibilita': 'cantiere'})
verifica(S, "l'ufficio la stringe a ufficio e cantiere: il cliente non la vede più", int(s['id']) not in vede_k())
sk = chiama(CLI, 'POST', '/segnalazioni', {'testo': 'Il cancello resta aperto la sera', 'tipo': 'sicurezza', 'visibilita': 'ufficio'})['data']
sql("UPDATE bb_worksites SET zone_cliente=0 WHERE id=100"); pulisci_notifiche()
chiama(UFF, 'POST', f"/segnalazioni/{sk['id']}/messaggi", {'testo': 'Ok, lo chiudiamo noi'})
verifica(S, 'condivisione spenta: il cliente che aveva aperto la segnalazione non riceve più le risposte', len(notifiche(CLI)) == 0 and len(email('paolo@rossi.it')) == 0, str(notifiche(CLI)))
sql("UPDATE bb_worksites SET zone_cliente=1 WHERE id=100")
chiama(UFF, 'POST', f"/segnalazioni/{sk['id']}/messaggi", {'testo': 'Chiuso'})
verifica(S, '... riaccesa, le riceve di nuovo', len(notifiche(CLI)) == 1, str(notifiche(CLI)))

S = '7b. Menzioni con @'
mz = lambda u, q: chiama(u, 'GET', '/menzionabili?' + q)
nomi_mz = lambda j: {x['nome'] for x in j.get('data', [])} if puo(j) else set()
m1 = nomi_mz(mz(CLI, 'contesto=chat&canale=cliente'))
verifica(S, 'chat CSM e committente: il cliente può menzionare CSM', 'Giulia Ufficio' in m1, str(m1))
verifica(S, '... ma non la squadra', 'Arben Hoxha' not in m1)
verifica(S, '... e non sé stesso', 'Paolo Rossi' not in m1)
verifica(S, 'il cliente non chiede i lettori della chat della squadra', mz(CLI, 'contesto=chat&canale=squadra')['_http'] == 403)
m2 = nomi_mz(mz(UFF, 'contesto=chat&canale=squadra'))
verifica(S, 'chat della squadra: CSM menziona il capo, non il cliente', 'Arben Hoxha' in m2 and 'Paolo Rossi' not in m2, str(m2))
pulisci_notifiche()
chiama(CLI, 'POST', '/chat/cliente', {'testo': '@Giulia Ufficio può confermare la consegna?', 'menzioni': [UFF]})
menz = lambda u: [n for n in notifiche(u) if 'menzionato' in n[0]]
verifica(S, 'la persona menzionata riceve la notifica', len(menz(UFF)) == 1 and '#chat-cliente.m' in menz(UFF)[0][1], str(notifiche(UFF)))
pulisci_notifiche()
chiama(CLI, 'POST', '/chat/cliente', {'testo': '@Arben Hoxha domani ci sei?', 'menzioni': [CAPO]})
verifica(S, 'menzionare chi non legge la chat non avvisa nessuno', len(menz(CAPO)) == 0)
chiama(CLI, 'POST', '/chat/cliente', {'testo': 'Grazie', 'menzioni': [UFF]})
verifica(S, 'senza il nome nel testo la menzione non vale', len(menz(UFF)) == 0)
m3 = nomi_mz(mz(UFF, f'contesto=attivita&id={t1}&interna=1'))
verifica(S, 'nota interna: si menziona solo CSM', 'Paolo Rossi' not in m3 and 'Arben Hoxha' not in m3, str(m3))
m4 = nomi_mz(mz(UFF, f'contesto=attivita&id={t1}'))
verifica(S, "messaggio sull'attività: si menziona chi la vede", 'Paolo Rossi' in m4, str(m4))
pulisci_notifiche()
chiama(UFF, 'POST', f'/tasks/{t1}/comments', {'text': '@Paolo Rossi serve la vostra conferma', 'menzioni': [CLI]})
verifica(S, "menzione in un'attività: notifica al committente", len(menz(CLI)) == 1 and f'#attivita-{t1}.c' in menz(CLI)[0][1], str(notifiche(CLI)))
pulisci_notifiche()
chiama(UFF, 'POST', f'/tasks/{t1}/comments', {'text': '@Paolo Rossi solo interno', 'menzioni': [CLI], 'interna': True})
verifica(S, 'menzione in una nota interna: il committente non riceve nulla', len(menz(CLI)) == 0)
sm = chiama(UFF, 'POST', '/segnalazioni', {'testo': 'Verifica ancoraggi', 'tipo': 'qualita', 'visibilita': 'cantiere'})['data']
m5 = nomi_mz(mz(UFF, f"contesto=segnalazione&id={sm['id']}"))
verifica(S, 'segnalazione CSM e cantiere: il committente non si menziona', 'Paolo Rossi' not in m5 and 'Arben Hoxha' in m5, str(m5))

S = '7c. Disegni: posizione, evidenza, annotazioni'
tp = chiama(UFF, 'POST', '/tasks', {'name': 'Sostituzione pannello', 'punto': {'document_id': 1, 'page': 1, 'x': 0.4, 'y': 0.3, 'segni': [
    {'type': 'rectangle', 'geom': {'x': 0.35, 'y': 0.25, 'w': 0.1, 'h': 0.08}, 'color': '#3b82f6'},
    {'type': 'text', 'geom': {'x': 0.36, 'y': 0.24}, 'text': 'Zona da smontare', 'color': '#ef4444'},
    {'type': 'script', 'geom': {'x': 0}},
]}})['data']['id']
segni_t = sql(f"SELECT type FROM bb_zone_annotations WHERE task_id={tp} ORDER BY id")
verifica(S, "nuova attività: il punto e i segni disegnati restano collegati all'attività", [x[0] for x in segni_t] == ['pin', 'rectangle', 'text'], str(segni_t))
dz = {d['id']: d for d in chiama(UFF, 'GET', '/disegni')['data']['bob']}
verifica(S, 'il disegno dice quante annotazioni ha', dz.get(1, {}).get('n_segni', 0) >= 3, str(dz.get(1, {}).get('n_segni')))
sql("INSERT INTO bb_zone_annotations(worksite_id,document_id,page,segnalazione_id,type,geom,created_by) VALUES (100,1,2,%d,'pin','{\"x\":0.5,\"y\":0.5}',5)" % int(s['id']))
dd = chiama(UFF, 'GET', f"/segnalazioni/{s['id']}")['data'].get('disegno')
verifica(S, 'la segnalazione appuntata dice su quale disegno e pagina', bool(dd) and dd['document_id'] == 1 and dd['page'] == 2, str(dd))

S = '8. Chi entra dopo'
pulisci_notifiche()
r = chiama(UFF, 'POST', '/accessi', form={'user_id': CLI2})
vk2 = ids(chiama(CLI2, 'GET', '/tasks'))
verifica(S, 'il secondo cliente, aggiunto oggi, vede le attività condivise prima', t1 in vk2 and t3 in vk2 and t2 not in vk2, str(vk2))
verifica(S, '... e le cartelle', f1 in {int(c['id']) for c in chiama(CLI2, 'GET', '/files')['data']['folders']})
verifica(S, '... ed è avvisato, anche per email', len(notifiche(CLI2)) == 1 and len(email('anna@rossi.it')) == 1, str(notifiche(CLI2)))

S = '9. Rapporto'
rk = chiama(CLI, 'GET', '/report?periodo=30').get('raw', '')
rc = chiama(CAPO, 'GET', '/report?periodo=30').get('raw', '')
verifica(S, 'il rapporto del cliente ha le attività condivise', 'Montaggio corsia A' in rk and 'Approvare campione colore' in rk)
verifica(S, '... non quelle "solo ufficio"', 'Costi interni gru' not in rk)
verifica(S, '... ha le presenze e i moduli compilati', 'giornate di lavoro' in rk and 'Verbale giornaliero' in rk)
verifica(S, 'la segnalazione stretta alla squadra è nel rapporto del capo, non del cliente', 'campata 3' in rc and 'Manca materiale' not in rk)

S = '10. Assegnata a una persona'
tp = chiama(UFF, 'POST', '/tasks', {'name': 'Controllare ancoraggi', 'assignee_user_id': CAPO, 'assegnata_a': 'persona',
                                    'luogo': 'Campata 3, livello 2', 'priority': 2, 'checklist': ['Ancoraggi lato A', 'Ancoraggi lato B']})['data']
verifica(S, 'il luogo resta scritto', sql(f"SELECT luogo FROM bb_zone_tasks WHERE id={tp['id']}")[0][0] == 'Campata 3, livello 2')
voci = chiama(CAPO, 'GET', f"/tasks/{tp['id']}/checklist")['data']
verifica(S, 'i passaggi nascono con l\'attività', [v['name'] for v in voci] == ['Ancoraggi lato A', 'Ancoraggi lato B'], str(voci))
verifica(S, 'la persona a cui tocca la porta avanti', puo(chiama(CAPO, 'POST', f"/tasks/{tp['id']}/status", {'status': 'in_progress'})))
verifica(S, '... e spunta i passaggi', puo(chiama(CAPO, 'POST', f"/tasks/{tp['id']}/checklist/{voci[0]['id']}/complete", {'completed': True})))
verifica(S, 'il committente la porta avanti come chi ce l\'ha', puo(chiama(CLI, 'POST', f"/tasks/{tp['id']}/status", {'status': 'complete'})))
verifica(S, '... e spunta i passaggi', puo(chiama(CLI, 'POST', f"/tasks/{tp['id']}/checklist/{voci[1]['id']}/complete", {'completed': True})))
verifica(S, 'una nuova attività nasce "da fare" anche se si manda altro', chiama(UFF, 'POST', '/tasks', {'name': 'x', 'status': 'complete'})['data']['status'] == 'open')

S = '11. Cancellare le proprie cose'
mio = sql("SELECT id FROM bb_zone_files WHERE file_name='foto_scaffale.pdf'")[0][0]
chiama(CAPO, 'POST', '/files', file={'nome': 'del_capo.pdf'})
suo = sql("SELECT id FROM bb_zone_files WHERE file_name='del_capo.pdf'")[0][0]
verifica(S, 'il cliente non cancella il file del capo', chiama(CLI, 'POST', f'/files/{suo}/delete')['_http'] == 403)
verifica(S, 'il cliente cancella il suo file', puo(chiama(CLI, 'POST', f'/files/{mio}/delete')))
tmia = chiama(CLI, 'POST', '/tasks', {'name': 'Da cancellare'})['data']['id']
verifica(S, 'il cliente cancella la sua attività', puo(chiama(CLI, 'POST', f'/tasks/{tmia}/delete')))
verifica(S, '... non quella dell\'ufficio', chiama(CLI, 'POST', f'/tasks/{t1}/delete')['_http'] == 403)
fm = chiama(CLI, 'POST', '/files/folder', {'name': 'Mie foto'})['data']['id']
verifica(S, 'il cliente cancella la sua cartella vuota', puo(chiama(CLI, 'POST', f'/files/folder/{fm}/delete')))
verifica(S, '... non quella dell\'ufficio', chiama(CLI, 'POST', f'/files/folder/{f1}/delete')['_http'] == 403)
sql("INSERT INTO bb_zone_annotations (id, worksite_id, document_id, page, created_by) VALUES (900, 100, 1, 1, 30), (901, 200, 1, 1, 10)")
verifica(S, 'il cliente non cancella i segni sul disegno del capo', chiama(CLI, 'POST', '/disegni/1/annotations/900/delete')['_http'] == 403)
verifica(S, '... né quelli di un altro cantiere', not puo(chiama(CLI, 'POST', '/disegni/1/annotations/901/delete')))

S = '12. Il riferimento del cliente'
verifica(S, 'il cliente scrive il suo nome e codice', puo(chiama(CLI, 'POST', '/riferimento', {'nome': 'Hub Nord fase 2', 'codice': 'CO-2026-114'})))
verifica(S, 'vale per tutta la sua azienda', sql("SELECT nome, codice FROM bb_zone_riferimenti_cliente WHERE worksite_id=100 AND client_id=1") == [['Hub Nord fase 2', 'CO-2026-114']])
verifica(S, 'il capo non lo scrive', chiama(CAPO, 'POST', '/riferimento', {'nome': 'x'})['_http'] == 403)
verifica(S, 'il rapporto lo riporta', 'CO-2026-114' in chiama(UFF, 'GET', '/report?periodo=30').get('raw', ''))

S = '13. Passaggi'
tq = chiama(UFF, 'POST', '/tasks', {'name': 'Verifica parapetti', 'assegnata_a': 'squadra', 'checklist': ['Lato nord']})['data']['id']
vq = chiama(CAPO, 'GET', f'/tasks/{tq}/checklist')['data']
verifica(S, 'il committente aggiunge passaggi anche a un\'attività della squadra', puo(chiama(CLI, 'POST', f'/tasks/{tq}/checklist', {'name': 'Lato sud'})))
verifica(S, 'il cliente non toglie passaggi', chiama(CLI, 'POST', f"/tasks/{tq}/checklist/{vq[0]['id']}/delete")['_http'] == 403)
verifica(S, 'il committente spunta i passaggi', puo(chiama(CLI, 'POST', f"/tasks/{tq}/checklist/{vq[0]['id']}/complete", {'completed': True})))
verifica(S, 'la squadra aggiunge un passaggio', puo(chiama(CAPO, 'POST', f'/tasks/{tq}/checklist', {'name': 'Lato sud'})))
verifica(S, '... ma non lo toglie (è di chi l\'ha creata)', chiama(CAPO, 'POST', f"/tasks/{tq}/checklist/{vq[0]['id']}/delete")['_http'] == 403)
verifica(S, "l'ufficio lo toglie", puo(chiama(UFF, 'POST', f"/tasks/{tq}/checklist/{vq[0]['id']}/delete")))

S = '14. Disegni e attività'
tz = chiama(CLI, 'POST', '/tasks', {'name': 'Spostare rack', 'punto': {'document_id': 1, 'page': 1, 'x': 0.4, 'y': 0.3}})['data']['id']
pin = sql(f"SELECT document_id, type FROM bb_zone_annotations WHERE task_id={tz}")
verifica(S, 'il punto scelto sul disegno diventa lo spillo dell\'attività', pin == [['1', 'pin']], str(pin))
d = [t for t in chiama(CLI, 'GET', '/tasks')['data'] if int(t['id']) == tz][0].get('disegno')
verifica(S, "l'attività sa su che disegno sta", d and d['document_id'] == 1, str(d))
r = chiama(CAPO, 'POST', '/disegni/1/annotations', {'type': 'pin', 'geom': {'x': .1, 'y': .1}, 'create_task': True, 'task_name': 'Dal capo'})
verifica(S, 'dal disegno il capo non apre attività (come nel modulo)', r['_http'] == 403)
r = chiama(CLI, 'POST', '/disegni/1/annotations', {'type': 'pin', 'geom': {'x': .2, 'y': .2}, 'create_task': True, 'task_name': 'Dal cliente sul disegno'})
nuova = int(r['data']['task_id']) if puo(r) else 0
verifica(S, "dal disegno il cliente apre un'attività", nuova > 0, str(r)[:200])
verifica(S, '... che va all\'ufficio e la vede la squadra', nuova in ids(chiama(CAPO, 'GET', '/tasks')) and sql(f"SELECT assegnata_a FROM bb_zone_tasks WHERE id={nuova}") == [['ufficio']])
te = chiama(UFF, 'POST', '/tasks', {'name': 'Montaggio staffe'})['data']['id']
r = chiama(UFF, 'POST', '/disegni/1/annotations', {'type': 'pin', 'geom': {'x': .3, 'y': .4}, 'task_id': te})
d = [t for t in chiama(UFF, 'GET', '/tasks')['data'] if int(t['id']) == te][0].get('disegno')
verifica(S, "un'attività esistente collegata dal disegno lo mostra", puo(r) and d and d['document_id'] == 1, str(d))
verifica(S, "non si collega un'attività di un altro cantiere", not puo(chiama(UFF, 'POST', '/disegni/1/annotations', {'type': 'pin', 'geom': {'x': .3, 'y': .3}, 'task_id': tb})))
verifica(S, 'non si cambia il segno di un altro', chiama(CLI, 'POST', '/disegni/1/annotations', {'id': 900, 'type': 'pin', 'geom': {'x': .5, 'y': .5}})['_http'] == 403)

S = '15. Moduli ogni mese'
r = chiama(UFF, 'POST', '/forms/assegnazioni', {'template_id': 2, 'a_user_id': CAPO, 'frequenza': 'mensile'})
verifica(S, 'si assegna a una persona, ogni mese', puo(r) and sql(f"SELECT frequenza, a_user_id FROM bb_zone_form_assegnazioni WHERE id={r['data']['id']}") == [['mensile', '30']])

ultima = None
for sez, cosa, c, extra in righe:
    if sez != ultima:
        print('\n' + sez); ultima = sez
    print(('  ✓ ' if c else '  ✗ ') + cosa + ('' if c or not extra else '   → ' + extra[:220]))
print(f'\n{ok} ok, {ko} da sistemare')
