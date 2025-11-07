#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE=(docker compose -f "$ROOT_DIR/docker-compose.yml")

usage() {
  cat <<USAGE
Usage: bin/sea.sh <command>

Commands:
  start             Start all docker services (detached)
  stop              Stop and remove containers
  restart           Restart the stack (stop + start)
  reset             Equivalent to restart but also clears dangling containers
  status            Show docker compose status
  logs [service]    Tail logs (optionally scope to service)
  erase             Flush all Redis data (requires redis service running)
  test              Run PHP + frontend unit + e2e tests (starts stack if needed)
USAGE
}

compose() {
  "${COMPOSE[@]}" "$@"
}

ensure_stack() {
  compose up -d >/dev/null
}

run_tests() {
  (cd "$ROOT_DIR/php-app" && composer install --ignore-platform-req=ext-redis --ignore-platform-req=ext-rdkafka >/dev/null && composer test)
  (cd "$ROOT_DIR/client" && npm install >/dev/null && npm run test)
}

cmd=${1:-help}
shift || true

case "$cmd" in
  start)
    compose up -d
    ;;
  stop)
    compose down
    ;;
  restart|reset)
    compose down --remove-orphans
    compose up -d
    ;;
  status)
    compose ps
    ;;
  logs)
    compose logs -f "$@"
    ;;
  erase)
    compose up -d redis >/dev/null
    compose exec redis redis-cli FLUSHALL
    ;;
  test)
    ensure_stack
    run_tests
    ;;
  help|*)
    usage
    ;;
 esac
