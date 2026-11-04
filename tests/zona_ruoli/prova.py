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
verifica(S, 'il cliente non chiude quelle della squadra', chiama(CLI, 'POST', f'/tasks/{t1}/status', {'status': 'open'})['_http'] == 403)
verifica(S, 'il cliente chiude quelle date a lui', puo(chiama(CLI, 'POST', f'/tasks/{t3}/status', {'status': 'complete'})))
verifica(S, "il cliente non porta avanti la sua se l'ha data all'ufficio", chiama(CLI, 'POST', f"/tasks/{tk['id']}/status", {'status': 'in_progress'})['_http'] == 403)
verifica(S, "l'ufficio la porta avanti", puo(chiama(UFF, 'POST', f"/tasks/{tk['id']}/status", {'status': 'in_progress'})))
chiama(CLI, 'POST', f"/tasks/{tk['id']}/update", {'name': 'Spostare scaffale 4', 'status': 'verified'})
verifica(S, '"verificato" non passa dalla modifica', sql(f"SELECT status FROM bb_zone_tasks WHERE id={tk['id']}")[0][0] == 'in_progress')
verifica(S, 'il cliente non cancella', chiama(CLI, 'POST', f'/tasks/{t1}/delete')['_http'] == 403)
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
verifica(S, 'attività per la squadra → avviso al capo, link alla sua pagina', any(l == '/io' for _, l in n_capo), str(n_capo))
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
verifica(S, 'il cliente non assegna moduli', chiama(CLI, 'POST', '/forms/assegnazioni', {'template_id': 2, 'a_ruolo': 'cantiere'})['_http'] == 403)
verifica(S, 'il cliente non cancella modelli', chiama(CLI, 'POST', '/forms/1/delete')['_http'] == 403)
verifica(S, "l'ufficio non tocca il modello di un altro cantiere da qui", not puo(chiama(UFF, 'POST', '/forms/3/delete')))
sk = chiama(CLI, 'GET', '/forms/submissions')['data']
verifica(S, 'la risposta del capo la legge anche il cliente (di base tutti)', len(sk) == 1)
verifica(S, 'chi compila rivede la sua risposta', len(chiama(CAPO, 'GET', '/forms/submissions')['data']) == 1)

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
verifica(S, 'il cliente non la tocca', chiama(CLI, 'POST', f"/tasks/{tp['id']}/status", {'status': 'complete'})['_http'] == 403)
verifica(S, '... e non spunta i passaggi', chiama(CLI, 'POST', f"/tasks/{tp['id']}/checklist/{voci[1]['id']}/complete", {'completed': True})['_http'] == 403)
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

ultima = None
for sez, cosa, c, extra in righe:
    if sez != ultima:
        print('\n' + sez); ultima = sez
    print(('  ✓ ' if c else '  ✗ ') + cosa + ('' if c or not extra else '   → ' + extra[:220]))
print(f'\n{ok} ok, {ko} da sistemare')
