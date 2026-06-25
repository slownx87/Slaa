import requests
import sys
import json

try:
    db = sys.argv[1]
    dl = sys.argv[2]
except Exception:
    db = "db.txt"
    dl = ":"

f = open(db, 'r').readlines()

url = "http://127.0.0.1:5000/auth/rok"

headers = {
    "accept": "application/json, text/plain, */*",
    "content-type": "application/json",
    "origin": "http://127.0.0.1:5000",
    "referer": "http://127.0.0.1:5000/",
    "user-agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36"
}

for line in f:
    line = line.strip()
    if not line:
        continue

    parts = line.split(dl)
    if len(parts) < 2:
        continue

    username = parts[0]
    password = parts[1]

    data = json.dumps({"username": username, "password": password})

    try:
        r = requests.post(url, headers=headers, data=data, allow_redirects=False, timeout=5)

        if r.status_code == 200:
            print(f"[LIVE] {username}:{password}")
        else:
            print(f"[DIE]  {username}:{password}")
    except requests.exceptions.ConnectionError:
        print("[ERRO] Servidor não está rodando. Execute: python server.py")
        break
