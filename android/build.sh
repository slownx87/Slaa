#!/usr/bin/env bash
# =========================================================================
# VN System — gera o APK do aplicativo Android (sem Gradle).
#   aapt2 (recursos) + javac (código) + dx (dex) + zipalign + apksigner (assinatura)
#
# Ferramentas (Ubuntu/Debian):  sudo apt install aapt apksigner zipalign dalvik-exchange android-framework-res openjdk-17-jdk-headless
# Classes do Android 14 (só para compilar; baixa sozinho na primeira vez):
#   https://repo.maven.apache.org/maven2/org/robolectric/android-all/14-robolectric-10818077/android-all-14-robolectric-10818077.jar
#
# Uso:   ./build.sh            -> dist/VNSystem-<versão>.apk (assinado)
# =========================================================================
set -euo pipefail
AQUI="$(cd "$(dirname "$0")" && pwd)"
cd "$AQUI"

VERSION_NAME="2.0.1"
VERSION_CODE=21
MIN_SDK=21
TARGET_SDK=34

ANDROID_JAR="${ANDROID_JAR:-$HOME/.cache/vn-android/android-all-14.jar}"
FRAMEWORK_RES="${FRAMEWORK_RES:-/usr/share/android-framework-res/framework-res.apk}"
DX="${DX:-/usr/lib/android-sdk/build-tools/debian/dx}"
KS="${KS:-$AQUI/keystore/vnsystem.jks}"
KS_PASS="${KS_PASS:-vnsystem2026}"
KEY_ALIAS="${KEY_ALIAS:-vnsystem}"

BUILD="$AQUI/build"
OUT="$AQUI/dist"
APK="$OUT/VNSystem-$VERSION_NAME.apk"

for f in aapt2 aapt zipalign apksigner javac keytool zip; do
  command -v "$f" >/dev/null 2>&1 || { echo "Falta a ferramenta: $f"; exit 1; }
done
[ -f "$FRAMEWORK_RES" ] || { echo "Falta $FRAMEWORK_RES (pacote android-framework-res)"; exit 1; }
[ -x "$DX" ] || { echo "Falta o dx em $DX (pacote dalvik-exchange)"; exit 1; }

if [ ! -f "$ANDROID_JAR" ]; then
  echo "Baixando as classes do Android 14 (uma vez só)..."
  mkdir -p "$(dirname "$ANDROID_JAR")"
  curl -fL --retry 5 -o "$ANDROID_JAR" \
    "https://repo.maven.apache.org/maven2/org/robolectric/android-all/14-robolectric-10818077/android-all-14-robolectric-10818077.jar"
fi

# chave de assinatura (criada na primeira vez; guarde o arquivo: é ele que permite atualizar o app depois)
if [ ! -f "$KS" ]; then
  echo "Criando a chave de assinatura..."
  mkdir -p "$(dirname "$KS")"
  keytool -genkeypair -noprompt -alias "$KEY_ALIAS" -keyalg RSA -keysize 2048 -validity 36500 \
    -keystore "$KS" -storepass "$KS_PASS" -keypass "$KS_PASS" \
    -dname "CN=VN System, OU=App, O=VN System, L=Sao Paulo, ST=SP, C=BR" 2>/dev/null
fi

rm -rf "$BUILD" && mkdir -p "$BUILD/gen" "$BUILD/classes" "$OUT"

echo "1/6 recursos"
aapt2 compile --dir app/res -o "$BUILD/res.zip"
aapt2 link -o "$BUILD/base.apk" -I "$FRAMEWORK_RES" \
  --manifest app/AndroidManifest.xml -A app/assets \
  --min-sdk-version "$MIN_SDK" --target-sdk-version "$TARGET_SDK" \
  --version-code "$VERSION_CODE" --version-name "$VERSION_NAME" \
  --java "$BUILD/gen" --auto-add-overlay "$BUILD/res.zip"

echo "2/6 código Java"
find app/java "$BUILD/gen" -name '*.java' > "$BUILD/fontes.txt"
javac -encoding UTF-8 --release 8 -Xlint:-options -Xlint:deprecation -Xlint:unchecked \
  -cp "$ANDROID_JAR" -d "$BUILD/classes" @"$BUILD/fontes.txt" 2>&1 | grep -v "^Picked up" || true
[ -f "$BUILD/classes/com/vnsystem/app/MainActivity.class" ] || { echo "ERRO: o código não compilou"; exit 1; }

echo "3/6 dex"
"$DX" --dex --min-sdk-version="$MIN_SDK" --output="$BUILD/classes.dex" "$BUILD/classes" 2>&1 | grep -v "^Picked up" || true
[ -f "$BUILD/classes.dex" ] || { echo "ERRO: o dex não foi gerado"; exit 1; }
(cd "$BUILD" && zip -q -X base.apk classes.dex)

echo "4/6 alinhamento"
zipalign -f -p 4 "$BUILD/base.apk" "$BUILD/alinhado.apk"

echo "5/6 assinatura"
rm -f "$APK" "$APK.idsig"
apksigner sign --ks "$KS" --ks-pass "pass:$KS_PASS" --key-pass "pass:$KS_PASS" --ks-key-alias "$KEY_ALIAS" \
  --v1-signing-enabled true --v2-signing-enabled true --v3-signing-enabled true --v4-signing-enabled false \
  --out "$APK" "$BUILD/alinhado.apk"

echo "6/6 conferência"
apksigner verify --verbose --print-certs "$APK" 2>&1 | grep -v "^Picked up" | head -12
zipalign -c -p 4 "$APK" && echo "alinhamento ok"
aapt dump badging "$APK" 2>&1 | grep -E "^(package|sdkVersion|targetSdkVersion|launchable-activity|uses-permission)" | cut -c1-140
ls -la "$APK"
