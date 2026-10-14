#!/usr/bin/env python3
"""Test 50 participations simultanées contre une instance QuizLive de test.

Usage :
    QUIZ_BASE_URL=http://127.0.0.1:8080 APP_ADMIN_KEY=... python3 tests/stress_50.py

Attention : crée une session réelle dans la base de l'instance cible.
"""
from __future__ import annotations

import concurrent.futures
import json
import os
from pathlib import Path
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('QUIZ_BASE_URL', 'http://127.0.0.1:8080').rstrip('/')
ADMIN = os.environ.get('APP_ADMIN_KEY', '')
PLAYERS = 50


def api(action: str, body: dict | None = None, token: str = '', params: dict | None = None):
    args = {'action': action, **(params or {})}
    url = f"{BASE}/api.php?{urllib.parse.urlencode(args)}"
    headers = {'Accept': 'application/json'}
    if token:
        headers['Authorization'] = f'Bearer {token}'
    if body is not None:
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(
        url,
        data=json.dumps(body).encode('utf-8') if body is not None else None,
        headers=headers,
        method='POST' if body is not None else 'GET',
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            return json.load(response)
    except urllib.error.HTTPError as err:
        raise RuntimeError(f'HTTP {err.code} {err.read().decode()}') from err


def main():
    if len(ADMIN) < 16:
        raise SystemExit('Définis APP_ADMIN_KEY (au moins 16 caractères).')
    quiz = json.loads((Path(__file__).parent.parent / 'data/questions.json').read_text())
    answer_index = quiz['questions'][0]['correctIndex']
    created = api('create', {}, ADMIN)
    code, host = created['code'], created['hostToken']
    print(f'Session {code} — inscription de {PLAYERS} personnes…')
    players = [api('join', {'code': code, 'name': f'Testeur{i:02}'}) for i in range(1, PLAYERS + 1)]
    api('host', {'code': code, 'command': 'start'}, host)
    print('Envoi simultané des 50 réponses…')
    t0 = time.monotonic()

    def send(player):
        return api('answer', {'code': code, 'questionIndex': 0, 'answerIndex': answer_index}, player['playerToken'])

    with concurrent.futures.ThreadPoolExecutor(max_workers=PLAYERS) as executor:
        responses = list(executor.map(send, players))
    elapsed = time.monotonic() - t0
    assert len(responses) == PLAYERS and all(p['accepted'] for p in responses)
    state = api('state', token=host, params={'code': code})
    assert state['session']['answeredCount'] == PLAYERS, state['session']
    api('host', {'code': code, 'command': 'reveal'}, host)
    state = api('state', token=host, params={'code': code})
    assert len(state['leaderboard']) == PLAYERS
    assert all(p['correctAnswers'] == 1 for p in state['leaderboard'])
    api('host', {'code': code, 'command': 'finish'}, host)
    print(f'PASS — {PLAYERS}/{PLAYERS} réponses validées, aucune perdue, classement cohérent.')
    print(f'Durée totale des appels concurrents : {elapsed:.2f} s.')
    print(f'Note : cette durée dépend de la machine, du réseau et du serveur HTTP.')


if __name__ == '__main__':
    main()
