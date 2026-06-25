from flask import Flask, request, jsonify

app = Flask(__name__)

# Credenciais válidas simuladas
VALID_USERS = {
    "admin": "senha123",
    "joao": "abc456",
    "maria": "pass789",
}

@app.route("/auth/rok", methods=["POST"])
def auth():
    body = request.get_json()
    if not body:
        return jsonify({"error": "invalid body"}), 400

    username = body.get("username", "")
    password = body.get("password", "")

    if VALID_USERS.get(username) == password:
        return jsonify({"token": "fake-jwt-token-xyz", "status": "ok"}), 200
    else:
        return jsonify({"error": "invalid credentials"}), 401

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5000, debug=True)
