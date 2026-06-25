# Lab de Autenticação

Ambiente local para aprender como credential checkers funcionam.

## Estrutura

```
lab/
├── server.py   # Servidor Flask que simula o endpoint /auth/rok
├── checker.py  # Script que testa credenciais contra o servidor
├── db.txt      # Lista de credenciais username:password
└── README.md
```

## Como rodar

### 1. Instalar dependências
```bash
pip install flask requests
```

### 2. Iniciar o servidor (terminal 1)
```bash
python server.py
```

### 3. Rodar o checker (terminal 2)
```bash
python checker.py
# ou com arquivo/delimitador customizado:
python checker.py db.txt :
```

## Como funciona

- `server.py` expõe `POST /auth/rok` e valida username/password
- `checker.py` lê `db.txt` linha a linha, extrai user:pass e faz POST
- Resposta 200 → `[LIVE]`, qualquer outra → `[DIE]`

## Credenciais válidas no lab

| Username | Password  |
|----------|-----------|
| admin    | senha123  |
| joao     | abc456    |
| maria    | pass789   |
