#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
server_pid=""
cleanup() {
  if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; fi
  rm -rf "$tmp"
}
trap cleanup EXIT

mkdir -p "$tmp/config"
cp "$root/get_encomiendas_dni.php" "$tmp/get_encomiendas_dni.php"
cp "$root/tests/fixtures/conexion.php" "$tmp/config/conexion.php"

port="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')"
php -S "127.0.0.1:$port" -t "$tmp" > "$tmp/server.log" 2>&1 &
server_pid="$!"

ready=false
for _ in $(seq 1 40); do
  if curl -s -o /dev/null -X POST "http://127.0.0.1:$port/get_encomiendas_dni.php"; then
    ready=true
    break
  fi
  sleep 0.1
done
if [[ "$ready" != true ]]; then
  cat "$tmp/server.log"
  echo "Servidor PHP local no disponible" >&2
  exit 1
fi

export API_TEST_URL="http://127.0.0.1:$port"
if ! python3 "$root/tests/validate_search.py"; then
  echo "--- Logs de PHP local ---" >&2
  cat "$tmp/server.log" >&2
  exit 1
fi
