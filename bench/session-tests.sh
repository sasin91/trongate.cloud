#!/bin/bash
# Functional session checks; run against a server on :8080. Prints PASS/FAIL per check.
B=http://localhost:8080; J=$(mktemp); C=${CTR:-tgb}
ok(){ if [ "$1" = "$2" ]; then echo "PASS $3"; else echo "FAIL $3 (got '$1', want '$2')"; fi; }
docker exec "$C" sh -c 'find /tmp -maxdepth 1 -name "sess_*" -delete'
h=$(curl -s -m 5 -D - -o /dev/null $B/bench | grep -ci '^set-cookie: PHPSESSID')
ok "$h" "${EXPECT_COOKIE_ON_HELLO:-0}" "hello world without cookie sets no session cookie"
ok "$(docker exec "$C" sh -c 'ls /tmp | grep -c sess_')" "${EXPECT_COOKIE_ON_HELLO:-0}" "hello world without cookie writes no session file"
ok "$(curl -s -m 5 -c "$J" -b "$J" $B/bench/set)" "n=1" "first write"
ok "$(curl -s -m 5 -c "$J" -b "$J" $B/bench/set)" "n=2" "write persists across requests"
ok "$(curl -s -m 5 -c "$J" -b "$J" $B/bench/get)" "n=2" "read with cookie"
ok "$(curl -s -m 5 $B/bench/get)" "n=none" "read without cookie is empty"
J2=$(mktemp); curl -s -m 5 -c "$J2" -b "$J2" $B/bench/late >/dev/null
ok "$(docker exec "$C" sh -c 'grep -l late /tmp/sess_* 2>/dev/null | wc -l')" "1" "write after 100 KB of output persists"
J3=$(mktemp); curl -s -m 5 -c "$J3" -b "$J3" -o /dev/null $B/bench/flash
ok "$(curl -s -m 5 -c "$J3" -b "$J3" $B/bench/show)" "flash=<p style=\"color: green;\">saved</p>" "flashdata survives redirect for new visitor"
J4=$(mktemp); tok=$(curl -s -m 5 -c "$J4" -b "$J4" $B/bench/form | grep -o 'name="csrf_token" value="[a-f0-9]*"' | grep -o '[a-f0-9]\{64\}')
ok "$(curl -s -m 5 -c "$J4" -b "$J4" -d "csrf_token=$tok&x=1" $B/bench/submit)" "csrf ok" "CSRF token from form validates on submit"
ok "$(curl -s -m 5 -c "$J4" -b "$J4" -d "csrf_token=bad&x=1" -o /dev/null -w '%{http_code}' $B/bench/submit | grep -c 200)" "0" "bad CSRF token is rejected"
ok "$(curl -s -m 5 -c "$J2" -b "$J2" $B/bench/get)" "n=none" "session started late is unlocked for the next request"
ok "$(curl -s -m 5 -c "$J3" -b "$J3" $B/bench/get)" "n=none" "session started on redirect is unlocked for the next request"
