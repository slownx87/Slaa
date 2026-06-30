#!/usr/bin/env python3
"""Teste isolado: pega o bearer (accessToken) do Serasa Empresas."""

import requests


def get_serasa_token(proxy=None):
    px = {"http": f"http://{proxy}", "https": f"http://{proxy}"} if proxy else None

    r = requests.post(
        "https://sitenet.serasa.com.br/security/iam/v1/user-identities/login?clientId=5ecebf45aae366236fd0b584",
        headers={
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": "pt-BR,pt;q=0.9",
            "Authorization": "Basic NDA5NDEzNDQ6TmVxQDE3NTA=",
            "Content-Type": "application/json",
            "Origin": "https://empresas.serasaexperian.com.br",
            "Referer": "https://empresas.serasaexperian.com.br/",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36",
        },
        json={"deviceId": "6a4442efb2b2e725cb1a4551", "deviceVersion": "V2"},
        proxies=px, timeout=15,
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
    get_serasa_token()
