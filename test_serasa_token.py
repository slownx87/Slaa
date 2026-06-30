#!/usr/bin/env python3
"""Teste isolado: pega o bearer (accessToken) do Serasa Empresas."""

import base64
import ssl

import requests
import urllib3
from requests.adapters import HTTPAdapter
from urllib3.util.ssl_ import create_urllib3_context

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


class _LegacyTLSAdapter(HTTPAdapter):
    """OpenSSL 3.x usa SECLEVEL=2 por padrão e recusa handshake com
    servidores que oferecem cifras mais antigas. Baixar pra SECLEVEL=1
    resolve o SSLV3_ALERT_HANDSHAKE_FAILURE sem desabilitar verificação."""

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


def get_serasa_token(user, pwd, proxy=None):
    px = {"http": f"http://{proxy}", "https": f"http://{proxy}"} if proxy else None
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
        proxies=px, timeout=15, verify=False,
    )

    print(f"Status: {r.status_code}")
    print(f"Resposta bruta:\n{r.text}\n")

    try:
        data = r.json()
    except Exception:
        print("⚠️ Resposta não é JSON válido.")
        return None

    token = data.get("accessToken")
    if token:
        print(f"✅ accessToken:\n{token}")
    else:
        print("⚠️ Campo 'accessToken' não encontrado no JSON.")
        print(f"Chaves disponíveis: {list(data.keys())}")
    return token


if __name__ == "__main__":
    import sys
    if len(sys.argv) < 3:
        print("Uso: python3 test_serasa_token.py <login> <senha>")
        sys.exit(1)
    get_serasa_token(sys.argv[1], sys.argv[2])
