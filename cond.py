#!/usr/bin/env python3
import requests
import json
import os
import openpyxl
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Optional, List, Dict

# Configurações
BASE_URL = "https://api.condfy.com.br/api/cwa/v1"
LICENSE_ID = "18788"
BLOCK_IDS = ["94701", "94700", "93649", "93367", "94698", "94071", "94860"]
BLOCK_ID = BLOCK_IDS[0]  # padrão nos payloads quando a unidade não traz o bloco
COOKIE_FILE = "condfy_session.json"
API_HOST = "api.condfy.com.br"
PROGRESS_FILE = "nice_done.json"  # ids das tags Nice já configuradas

MANUFACTURER_TERMS = {'niceguarita': 'nice', 'hikvision': 'hikvision'}

# Nice Guarita (LINEAR_GUARITA) - valores copiados da tela de edição do Condfy
# Requisições em paralelo (com muitas, a API pode devolver 403/429)
NICE_WORKERS = 1  # 1 = uma por vez (mais seguro); suba só se a API aceitar
NICE_CONFIGURATION_ID = 3420
NICE_GROUPS = [{"id": "0", "description": "LIVRE  (0)"}]
NICE_READERS = [
    {"id": "0", "description": "GAR S1"},
    {"id": "1", "description": "GAR S2"},
    {"id": "2", "description": "REC TP 3"},
]
EXCEL_FILE = r"C:\Users\VnSystem\Downloads\arqv.xlsx"

class CondyMassRegister:
    def __init__(self):
        self.csl_cookie = None
        self.xsrf_token = None
        self.session = requests.Session()
        self.headers = {
            "Accept": "application/json, text/plain, */*",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36"
        }
        self.excel_data = {}
        self._lock = threading.RLock()
        self.forbidden_count = 0
        self.stats = {"success": 0, "skip": 0, "fail": 0, "manual": 0}

        self.load_cookie()
        self.load_excel()

    def load_excel(self):
        """Carrega dados do Excel"""
        print(f"\n📂 Carregando Excel...")
        try:
            wb = openpyxl.load_workbook(EXCEL_FILE)
            ws = wb.active

            for row_idx, row in enumerate(ws.iter_rows(min_row=2, values_only=True)):
                bloco, unidade, nome = row[0], row[1], row[2]
                if not nome or not unidade:
                    continue

                unit_key = str(int(unidade)) if isinstance(unidade, (int, float)) else str(unidade)
                if unit_key not in self.excel_data:
                    self.excel_data[unit_key] = []

                self.excel_data[unit_key].append({
                    'nome': str(nome).strip(),
                    'rg': row[3],
                    'cpf': row[4],
                    'telefone': row[8],
                    'email': row[6]
                })

            print(f"✅ Excel carregado: {len(self.excel_data)} unidades, {sum(len(v) for v in self.excel_data.values())} moradores")
            return True
        except Exception as e:
            print(f"❌ Erro ao carregar Excel: {e}")
            return False

    def _set_cookie(self, name: str, value: str):
        """Define UM cookie com esse nome (apaga duplicatas de qualquer domínio antes).
        Cookie duplicado (um sem domínio + outro do servidor) faz o CSRF dar 403."""
        for cookie in list(self.session.cookies):
            if cookie.name == name:
                self.session.cookies.clear(cookie.domain, cookie.path, cookie.name)
        self.session.cookies.set(name, value, domain=API_HOST, path='/')

    def _absorb_cookies(self, response):
        """O servidor troca XSRF-TOKEN (e às vezes csl) a cada resposta: adota os novos
        valores, mantendo um cookie só por nome e o header igual ao cookie"""
        try:
            for cookie in response.cookies:
                if cookie.name == 'XSRF-TOKEN' and cookie.value:
                    self.xsrf_token = cookie.value
                    self._set_cookie('XSRF-TOKEN', cookie.value)
                elif cookie.name == 'csl' and cookie.value:
                    self.csl_cookie = cookie.value
                    self._set_cookie('csl', cookie.value)
        except Exception:
            pass

    def load_cookie(self):
        """Carrega o cookie do arquivo se existir"""
        if os.path.exists(COOKIE_FILE):
            try:
                with open(COOKIE_FILE, 'r') as f:
                    data = json.load(f)
                    self.csl_cookie = data.get('csl_cookie')
                    self.xsrf_token = data.get('xsrf_token')

                    if self.csl_cookie:
                        print(f"📂 Cookie carregado")
                        self._set_cookie('csl', self.csl_cookie)
                        if self.xsrf_token:
                            self._set_cookie('XSRF-TOKEN', self.xsrf_token)
                        return True
            except:
                pass
        return False

    def save_cookie(self):
        """Salva o cookie em arquivo"""
        with open(COOKIE_FILE, 'w') as f:
            json.dump({
                'csl_cookie': self.csl_cookie,
                'xsrf_token': self.xsrf_token
            }, f)

    def test_cookie(self) -> bool:
        """Testa se o cookie é válido"""
        if not self.csl_cookie:
            return False

        try:
            url = f"{BASE_URL}/licenses/{LICENSE_ID}"
            response = self.session.get(url, headers=self.headers, timeout=5)
            return response.status_code == 200
        except:
            return False

    def get_new_cookie(self):
        """Pede novo cookie do usuário"""
        print("\n🔐 Novo Cookie Necessário")

        csl_cookie = input("\n🔑 Cole aqui seu cookie 'csl': ").strip()

        if not csl_cookie:
            print("❌ Cookie não fornecido")
            return False

        xsrf_token = input("\n🔑 Cole aqui seu XSRF-TOKEN (opcional): ").strip()

        self.csl_cookie = csl_cookie
        self.xsrf_token = xsrf_token if xsrf_token else None
        self._set_cookie('csl', csl_cookie)
        if self.xsrf_token:
            self._set_cookie('XSRF-TOKEN', self.xsrf_token)

        if self.test_cookie():
            print("✅ Cookie válido!")
            self.save_cookie()
            self.get_new_xsrf_token()
            return True
        else:
            print("❌ Cookie inválido")
            return False

    def ensure_valid_cookie(self) -> bool:
        """Garante que temos um cookie válido"""
        if self.test_cookie():
            print("✅ Cookie válido")
            self.get_new_xsrf_token()
            return True

        print("⚠️  Cookie expirado ou inválido")
        return self.get_new_cookie()

    def get_new_xsrf_token(self) -> bool:
        """Obtém um XSRF token novo e deixa token (header) e cookie com o mesmo valor, sem duplicata"""
        url = f"{BASE_URL}/public/csrf"

        try:
            response = self.session.get(url, headers=self.headers, timeout=15)
            response.raise_for_status()
            self._absorb_cookies(response)
            token = response.json().get('token')
            # Se o servidor também mandou o cookie, ele manda: header tem que ser igual a ele
            for cookie in self.session.cookies:
                if cookie.name == 'XSRF-TOKEN' and cookie.value:
                    token = cookie.value
            if not token:
                return False
            self.xsrf_token = token
            self._set_cookie('XSRF-TOKEN', token)
            self.save_cookie()
            return True
        except (requests.RequestException, ValueError):
            return False

    def _search_units_in_block(self, block_id: str, name: str, page: int = 0, size: int = 15):
        """Uma página de units/options de um bloco. Retorna (content, last) ou None em erro."""
        url = f"{BASE_URL}/licenses/{LICENSE_ID}/units/options"
        params = {"page": page, "blockId": block_id, "name": name, "size": size}
        try:
            response = self.session.get(url, params=params, headers=self.headers, timeout=15)
            if response.status_code != 200:
                print(f"⚠️  units/options bloco {block_id}: HTTP {response.status_code}")
                return None
            data = response.json()
            return data.get('content') or [], data.get('last', True)
        except requests.RequestException as e:
            print(f"⚠️  Erro de rede (bloco {block_id}): {e}")
            return None

    def find_units(self, unit_number: str, block_id: Optional[str] = None) -> List[dict]:
        """Unidades com nome exato, em um bloco ou em todos. Cada uma ganha '_block_id'."""
        found = []
        for bid in ([block_id] if block_id else BLOCK_IDS):
            result = self._search_units_in_block(bid, str(unit_number))
            if not result:
                continue
            # A busca é parcial ("1" acha "10", "101"): exigir nome exato
            for unit in result[0]:
                if str(unit.get('name', '')).strip() == str(unit_number).strip():
                    found.append({**unit, '_block_id': bid})
        return found

    def get_unit_id(self, unit_number: str, block_id: Optional[str] = None) -> Optional[dict]:
        """Procura a unidade pelo número (em um bloco, ou no primeiro bloco que a tiver)"""
        found = self.find_units(unit_number, block_id)
        return found[0] if found else None

    def get_residents_api(self, unit_id: int) -> Optional[list]:
        """Procura os moradores de uma unidade na API"""
        url = f"{BASE_URL}/units/{unit_id}/residents/options"
        params = {
            "unitId": unit_id,
            "name": "",
            "page": 0
        }

        try:
            response = self.session.get(url, params=params, headers=self.headers)
            if response.status_code == 200:
                data = response.json()
                if data.get('content'):
                    return data['content']
        except:
            pass

        return None

    def search_credential(self, search_name: str, manufacturer_filter: str = 'hikvision', credential_type: Optional[str] = None, all_pages: bool = False, only_free: bool = True) -> List[dict]:
        """Procura credentials pelo nome, filtrando por fabricante (padrão: Hikvision)"""
        url = f"{BASE_URL}/licenses/{LICENSE_ID}/credentials"
        params = {
            "block": "",
            "unit": "",
            "description": search_name,
            "identification": "",
            "manufacturer": "",
            "page": 0
        }

        credentials = []

        # Termo usado pela API/filtro para cada fabricante (ex: niceguarita -> "nice")
        manufacturer_term = MANUFACTURER_TERMS.get(manufacturer_filter, manufacturer_filter)
        params["manufacturer"] = manufacturer_term

        seen = {"total": 0, "manufacturers": {}, "types": {}, "com_unidade": 0}

        try:
            while True:
                response = self.session.get(url, params=params, headers=self.headers, timeout=15)
                if response.status_code != 200:
                    print(f"\n      ⚠️  Busca de credenciais: HTTP {response.status_code}")
                    break
                data = response.json()
                content = data.get('content') or []
                for credential in content:
                    manufacturer = credential.get('manufacturer') or ''
                    seen["total"] += 1
                    seen["manufacturers"][manufacturer] = seen["manufacturers"].get(manufacturer, 0) + 1
                    ctype = credential.get('credentialTypeDescription')
                    seen["types"][ctype] = seen["types"].get(ctype, 0) + 1
                    if credential.get('unitId') is not None:
                        seen["com_unidade"] += 1

                    # Aceitar apenas o fabricante desejado
                    if manufacturer_term not in manufacturer.lower():
                        continue

                    # Filtrar pelo tipo de credencial (ex: tag)
                    if credential_type and (credential.get('credentialTypeDescription') or '').strip().lower() != credential_type.lower():
                        continue

                    # Só as sem unidade (ou todas, com only_free=False)
                    if not only_free or credential.get('unitId') is None:
                        credentials.append(credential)

                if not all_pages or not content or data.get('last', True) or params["page"] >= 200:
                    break
                params["page"] += 1
        except requests.RequestException as e:
            print(f"\n      ⚠️  Erro de rede na busca: {e}")

        if all_pages and not credentials:
            print(f"   🔎 Diagnóstico: {seen['total']} credenciais retornadas pela API")
            print(f"      fabricantes: {seen['manufacturers']}")
            print(f"      tipos: {seen['types']}")
            print(f"      já vinculadas a unidade: {seen['com_unidade']}")

        return credentials

    def link_credential(self, credential: dict, unit: dict, resident: dict) -> bool:
        """Vincula o credential ao residente"""
        payload = {
            "credentialId": credential['id'],
            "blockId": unit.get('_block_id', BLOCK_ID),
            "blockName": "0",
            "unitId": unit['id'],
            "unitNumber": unit['name'],
            "linkId": resident['id'],
            "linkTypeName": "MORADOR",
            "newLink": {
                "name": "",
                "serviceType": "",
                "brand": "",
                "model": "",
                "color": "",
                "plate": ""
            }
        }

        headers = self.headers.copy()
        headers["content-type"] = "application/json"
        headers["x-xsrf-token"] = self.xsrf_token
        headers["origin"] = "https://web.condfy.com.br"
        headers["referer"] = "https://web.condfy.com.br/"

        url = f"{BASE_URL}/credentials/{credential['id']}/link"

        try:
            response = self.session.post(url, json=payload, headers=headers)
            return response.status_code in [200, 201, 204]
        except:
            return False

    def update_credential_nice(self, credential: dict) -> bool:
        """PUT /credentials/{id} com a configuração da tag Nice, mantendo o vínculo atual dela"""
        # Trava: o payload é de tag Nice; nunca aplicar a facial, controle remoto ou outro fabricante
        if ('nice' not in (credential.get('manufacturer') or '').lower()
                or credential.get('equipmentType') != 'LINEAR_GUARITA'
                or (credential.get('credentialTypeDescription') or '').strip().lower() != 'tag'):
            print(f"\n      🚫 Recusado: credencial {credential.get('id')} não é tag Nice "
                  f"({credential.get('manufacturer')}/{credential.get('credentialTypeDescription')})")
            return False

        payload = {
            "linkTypeName": credential.get('linkTypeDescription') or "MORADOR",
            "linkId": credential['linkId'],
            "equipmentTypeName": "LINEAR_GUARITA",
            "configurationId": credential.get('configurationId') or NICE_CONFIGURATION_ID,
            "name": credential.get('description') or credential.get('identification', ''),
            "unlimitedPeriod": True,
            "groups": NICE_GROUPS,
            "timeOptions": [],
            "sentToSemParar": False,
            "replicationEquipmentsIds": [],
            "readers": NICE_READERS,
            "valueRead": {
                "cardId": "",
                "complementaryCode": "",
                "credentialCode": "",
                "finger": {"fingerId": 0, "fingerData": "", "readerType": ""},
                "face": {"photoUrl": ""}
            },
            "enabled": False,
            "types": []
        }

        headers = self.headers.copy()
        headers["content-type"] = "application/json"
        headers["x-xsrf-token"] = self.xsrf_token
        headers["origin"] = "https://web.condfy.com.br"
        headers["referer"] = "https://web.condfy.com.br/"

        url = f"{BASE_URL}/credentials/{credential['id']}"

        for attempt in range(3):
            try:
                headers["x-xsrf-token"] = self.xsrf_token
                response = self.session.put(url, json=payload, headers=headers, timeout=20)
                self._absorb_cookies(response)
                self.last_put = (response.status_code, response.text[:300])
                if response.status_code in [200, 201, 204]:
                    return True
                if response.status_code == 403:
                    with self._lock:
                        self.forbidden_count += 1
                if response.status_code not in (429, 500, 502, 503, 504):
                    if response.status_code not in (401, 403):  # 401/403: quem chamou trata
                        print(f"\n      ⚠️  id {credential['id']}: HTTP {response.status_code}: {response.text[:200]}")
                    return False
            except requests.RequestException as e:
                print(f"\n      ⚠️  id {credential['id']}: erro de rede: {e}")
            time.sleep(1 + attempt)
        return False

    def link_credential_veiculo(self, credential: dict, unit: dict, vehicle: dict) -> bool:
        """Vincula o credential Nice Guarita criando um veículo novo"""
        payload = {
            "credentialId": credential['id'],
            "blockId": unit.get('_block_id', BLOCK_ID),
            "blockName": "0",
            "unitId": unit['id'],
            "unitNumber": unit['name'],
            "linkId": 0,
            "linkTypeName": "VEICULO",
            "newLink": {
                "name": vehicle['name'],
                "serviceType": "",
                "brand": vehicle.get('brand', 'Outras_marcas'),
                "model": vehicle.get('model', 'DEFINIR'),
                "color": vehicle.get('color', 'Branco'),
                "plate": vehicle['plate']
            }
        }

        headers = self.headers.copy()
        headers["content-type"] = "application/json"
        headers["x-xsrf-token"] = self.xsrf_token
        headers["origin"] = "https://web.condfy.com.br"
        headers["referer"] = "https://web.condfy.com.br/"

        url = f"{BASE_URL}/credentials/{credential['id']}/link"

        try:
            response = self.session.post(url, json=payload, headers=headers)
            return response.status_code in [200, 201, 204]
        except:
            return False

    def ask_user(self, resident_name: str, credentials: List[dict]) -> Optional[dict]:
        """Pergunta ao usuário qual credential usar"""
        print(f"\n❓ Múltiplas opções para '{resident_name}':")
        print(f"   Encontrados {len(credentials)} credentials:")

        for i, cred in enumerate(credentials, 1):
            print(f"   {i}. {cred['description']} (ID: {cred['id']}, Ident: {cred['identification']})")

        print(f"   0. Pular")

        while True:
            try:
                opcao = input(f"\n   Qual usar? (0-{len(credentials)}): ").strip()
                opcao_int = int(opcao)

                if opcao_int == 0:
                    return None
                elif 1 <= opcao_int <= len(credentials):
                    return credentials[opcao_int - 1]
                else:
                    print(f"   ❌ Opção inválida")
            except:
                print(f"   ❌ Digite um número válido")

    def do_link(self, credential: dict, unit: dict, resident: dict, manufacturer: str) -> bool:
        """Vincula de acordo com o fabricante (morador ou veículo)"""
        return self.link_credential(credential, unit, resident)

    def mass_register(self, unit_number: str = None, manufacturer: str = 'hikvision'):
        """Faz registro em massa de unidades"""
        print("\n" + "=" * 60)
        print("🚀 CADASTRO EM MASSA (HIKVISION)")
        print("=" * 60)

        # Determinar quais unidades processar: lista de (block_id ou None, número)
        if unit_number:
            units_to_process = [(None, unit_number)]
        else:
            units_to_process = [(None, u) for u in self.excel_data]

        if not units_to_process:
            print("❌ Nenhuma unidade para processar")
            return

        total_units = len(units_to_process)
        processed_units = 0

        for block_id, unit_num in units_to_process:
            processed_units += 1
            print(f"\n{'=' * 60}")
            print(f"📍 [{processed_units}/{total_units}] Unidade {unit_num}" + (f" (bloco {block_id})" if block_id else ""))
            print(f"{'=' * 60}")

            # Buscar unidade na API
            unit_api = self.get_unit_id(unit_num, block_id)
            if not unit_api:
                print(f"❌ Unidade não encontrada na API")
                continue

            # Buscar moradores na API
            residents_api = self.get_residents_api(unit_api['id'])
            if not residents_api:
                print(f"⚠️  Nenhum morador encontrado na API")
                continue

            # Processar cada morador
            print(f"👥 Processando {len(residents_api)} moradores...")

            for resident in residents_api:
                resident_name = resident['name']
                print(f"\n   👤 {resident_name}...", end=" ")

                search_name = resident_name

                # Procurar credential
                credentials = self.search_credential(search_name, manufacturer_filter=manufacturer)

                if not credentials:
                    print("⏭️  Sem credential")
                    self.stats["skip"] += 1
                    continue

                # Se houver exatamente 1, vincular automaticamente
                if len(credentials) == 1:
                    credential = credentials[0]
                    if self.do_link(credential, unit_api, resident, manufacturer):
                        print("✅")
                        self.stats["success"] += 1
                    else:
                        print("❌")
                        self.stats["fail"] += 1

                # Se houver múltiplos, perguntar ao usuário
                else:
                    selected_cred = self.ask_user(resident_name, credentials)
                    if selected_cred:
                        if self.do_link(selected_cred, unit_api, resident, manufacturer):
                            print("   ✅ Vinculado")
                            self.stats["success"] += 1
                            self.stats["manual"] += 1
                        else:
                            print("   ❌ Erro ao vincular")
                            self.stats["fail"] += 1
                    else:
                        print("   ⏭️  Pulado")
                        self.stats["skip"] += 1

    def _fetch_nice_page(self, page: int) -> Optional[dict]:
        """Uma página de credenciais Nice (com 3 tentativas). None em erro."""
        url = f"{BASE_URL}/licenses/{LICENSE_ID}/credentials"
        params = {"block": "", "unit": "", "description": "", "identification": "",
                  "manufacturer": MANUFACTURER_TERMS['niceguarita'], "page": page}
        for attempt in range(3):
            try:
                response = self.session.get(url, params=params, headers=self.headers, timeout=20)
                if response.status_code == 200:
                    return response.json()
                if response.status_code not in (429, 500, 502, 503, 504):
                    print(f"\n⚠️  Página {page}: HTTP {response.status_code}")
                    return None
            except requests.RequestException:
                pass
            time.sleep(1 + attempt)
        print(f"\n⚠️  Página {page}: falhou após 3 tentativas")
        return None

    def load_progress(self) -> set:
        try:
            with open(PROGRESS_FILE, 'r') as f:
                return set(json.load(f).get('done', []))
        except (OSError, ValueError):
            return set()

    def mark_done(self, done: set, credential_id: int):
        """Grava na hora cada tag configurada, para não repetir se reiniciar"""
        with self._lock:
            done.add(credential_id)
            tmp = PROGRESS_FILE + ".tmp"
            with open(tmp, 'w') as f:
                json.dump({'done': sorted(done)}, f)
            os.replace(tmp, PROGRESS_FILE)

    def fetch_nice_credentials(self) -> List[dict]:
        """Todas as credenciais Nice, páginas em paralelo (lotes de NICE_WORKERS) até acabar"""
        result = {}
        page = 0
        done = False
        with ThreadPoolExecutor(max_workers=NICE_WORKERS) as pool:
            while not done and page < 500:
                batch = list(range(page, page + NICE_WORKERS))
                pages = dict(zip(batch, pool.map(self._fetch_nice_page, batch)))
                for pg in batch:
                    data = pages[pg]
                    content = (data or {}).get('content') or []
                    for c in content:
                        result[c['id']] = c
                    if not content or not ((data or {}).get('links') or {}).get('next'):
                        done = True
                        break
                page += NICE_WORKERS
                print(f"   {len(result)} credenciais lidas...")
        return list(result.values())

    def configure_nice_tags(self, only_unit: str = None):
        """Nice: aplica a configuração do PUT (grupo + leitores) em cada tag, uma por uma,
        usando o id da própria credencial e mantendo o vínculo (linkId/nome) que ela já tem."""
        print("\n" + "=" * 60)
        print("🚀 NICE GUARITA: CONFIGURAR TAGS")
        print("=" * 60)

        dry_run = input("\n🧪 Modo teste (só mostra, não altera nada)? (s/n): ").strip().lower() == 's'

        print("\n🏷️  Lendo credenciais Nice, página por página...")
        credentials = self.fetch_nice_credentials()
        tags = [c for c in credentials
                if 'nice' in (c.get('manufacturer') or '').lower()
                and c.get('equipmentType') == 'LINEAR_GUARITA'
                and (c.get('credentialTypeDescription') or '').strip().lower() == 'tag'
                and c.get('linkId')]
        if only_unit:
            tags = [c for c in tags if str(c.get('unitNumber')).strip() == only_unit]
        print(f"\n   {len(credentials)} credenciais lidas, {len(tags)} tags Nice a configurar")
        if not tags:
            return

        done = self.load_progress()
        if done and not dry_run:
            pulados = [t for t in tags if t['id'] in done]
            if pulados:
                resp = input(f"\n♻️  {len(pulados)} tags já configuradas antes. Pular essas? (S/n): ").strip().lower()
                if resp != 'n':
                    tags = [t for t in tags if t['id'] not in done]
                    print(f"   {len(tags)} restantes")
        if not tags:
            print("✅ Nada a fazer: todas as tags já foram configuradas")
            return

        if dry_run:
            for n, tag in enumerate(tags, 1):
                print(f"🧪 [{n}/{len(tags)}] id {tag['id']}  {tag.get('description')}  "
                      f"(unid {tag.get('unitNumber')}, ident {tag.get('identification')})")
            return

        self.get_new_xsrf_token()
        total = len(tags)
        counter = {"n": 0}
        stop = threading.Event()

        def send(tag) -> bool:
            """PUT; se a sessão cair (401/403), pede cookie novo e tenta de novo, sem reiniciar"""
            ok = self.update_credential_nice(tag)
            if ok:
                return True
            status = getattr(self, 'last_put', (0,))[0]
            if status not in (401, 403):
                return False
            if status in (401, 403):
                with self._lock:   # um de cada vez renova a sessão
                    if not stop.is_set():
                        self.get_new_xsrf_token()
                        ok = self.update_credential_nice(tag)
                        if not ok and getattr(self, 'last_put', (0,))[0] in (401, 403):
                            print("\n🔐 Sessão caiu. Cole um cookie novo para continuar (Enter vazio = parar).")
                            if self.get_new_cookie():
                                self.get_new_xsrf_token()
                                ok = self.update_credential_nice(tag)
                            else:
                                stop.set()
                if ok:
                    return True
            print(f"\n      ⚠️  id {tag['id']}: HTTP {getattr(self, 'last_put', ('?', ''))[0]}: "
                  f"{getattr(self, 'last_put', ('', ''))[1][:150]}")
            return False

        def work(tag):
            if stop.is_set():
                return
            ok = send(tag)
            with self._lock:
                counter["n"] += 1
                label = (f"[{counter['n']}/{total}] id {tag['id']}  {tag.get('description')}  "
                         f"(unid {tag.get('unitNumber')}, ident {tag.get('identification')})")
                if ok:
                    print(f"✅ {label}")
                    self.stats["success"] += 1
                else:
                    print(f"❌ {label}")
                    self.stats["fail"] += 1
            if ok:
                self.mark_done(done, tag['id'])
            elif self.forbidden_count >= 5 and NICE_WORKERS > 1:
                stop.set()

        self.forbidden_count = 0
        with ThreadPoolExecutor(max_workers=NICE_WORKERS) as pool:
            for f in as_completed([pool.submit(work, t) for t in tags]):
                f.result()

        if stop.is_set():
            print(f"\n🛑 Parado. {len(done)} tags já salvas em {PROGRESS_FILE}; rode de novo para continuar.")

    def show_stats(self):
        """Mostra estatísticas finais"""
        print(f"\n" + "=" * 60)
        print(f"📊 RESULTADO FINAL")
        print(f"=" * 60)
        print(f"✅ Sucessos: {self.stats['success']}")
        print(f"❌ Falhas: {self.stats['fail']}")
        print(f"⏭️  Pulados: {self.stats['skip']}")
        print(f"❓ Com intervenção: {self.stats['manual']}")
        print(f"{'=' * 60}")

    def run(self):
        """Menu principal"""
        print("=" * 60)
        print("🏢 Condfy Mass Register")
        print("=" * 60)

        if not self.ensure_valid_cookie():
            print("\n❌ Não foi possível obter cookie válido")
            return

        while True:
            print("\n" + "=" * 60)
            print("📋 MENU")
            print("=" * 60)
            print("1. Cadastrar TODAS as unidades")
            print("2. Cadastrar UMA unidade específica")
            print("3. Nice Guarita: configurar tags")
            print("4. Renovar cookie")
            print("5. Sair")

            opcao = input("\nEscolha uma opção (1-5): ").strip()

            if opcao == '1':
                confirm = input("\n⚠️  Isso vai processar TODAS as unidades do Excel. Confirma? (s/n): ").strip().lower()
                if confirm == 's':
                    self.mass_register()
                    self.show_stats()

            elif opcao == '2':
                unit_number = input("\n📍 Digite o número da unidade: ").strip()
                if unit_number in self.excel_data:
                    self.mass_register(unit_number)
                    self.show_stats()
                else:
                    print(f"❌ Unidade {unit_number} não encontrada no Excel")

            elif opcao == '3':
                unit_number = input("\n📍 Unidade (Enter para TODAS as tags Nice): ").strip()
                if not unit_number:
                    confirm = input("\n⚠️  Isso vai aplicar a configuração em TODAS as tags Nice. Confirma? (s/n): ").strip().lower()
                    if confirm != 's':
                        continue
                self.configure_nice_tags(unit_number or None)
                self.show_stats()

            elif opcao == '4':
                if self.get_new_cookie():
                    print("✅ Cookie renovado")
                else:
                    print("❌ Falha ao renovar")

            elif opcao == '5':
                print("\n✅ Até logo!")
                break

            else:
                print("❌ Opção inválida")


def main():
    finder = CondyMassRegister()
    finder.run()


if __name__ == "__main__":
    main()