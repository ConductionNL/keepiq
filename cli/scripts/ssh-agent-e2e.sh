#!/usr/bin/env bash
# Runs the README's `keepiq ssh-agent` recipe end to end, the way a person
# would in a shell, against cli/internal/fakevault (a throwaway Keepiq) and a
# throwaway sshd on localhost (keepiq#1042):
#
#   1. eval "$(keepiq ssh-agent)" returns, and the agent keeps serving
#   2. ssh-add -l lists the vault's SSH key, and only that one
#   3. ssh and git clone over SSH log in with it
#   4. ssh-add -x locks the agent: no keys, the login is refused
#   5. ssh-add -X with the master password unlocks it: the login works again
#   6. ssh-add <file> and ssh-add -d are refused
#   7. kill $SSH_AGENT_PID stops the agent and removes its socket
#
# With KEEPIQ_E2E_LAUNCHD=1 on macOS it then installs the README's launchd
# plist in the current user's home and checks the agent it starts. That step
# writes to ~/Library/LaunchAgents and to keepiq's config in the real home, so
# it is meant for a throwaway CI runner only.
#
# Needs go, ssh, ssh-add, ssh-keygen, sshd and git. Runs as any user: sshd is
# started as that user on a high port with its own host key and
# authorized_keys, and never touches the system sshd.
# shellcheck disable=SC2016 # the $(...) and $VAR in step titles are literal on purpose
set -euo pipefail

cli="$(cd "$(dirname "$0")/.." && pwd)"
me="$(id -un)"
real_home="$HOME"
work="$(mktemp -d /tmp/kq-e2e.XXXXXX)"
master='correct horse battery staple'
app_password='e2e-app-password'
key_name='e2e deploy key'
pids=()

step() { printf '\n== %s\n' "$*"; }
fail() {
	printf '::error::%s\n' "$*"
	exit 1
}

cleanup() {
	set +e
	if [ -n "${SSH_AGENT_PID:-}" ]; then kill "$SSH_AGENT_PID" 2>/dev/null; fi
	for p in "${pids[@]+"${pids[@]}"}"; do kill "$p" 2>/dev/null; done
	if [ -n "${plist:-}" ] && [ -f "$plist" ]; then
		launchctl unload "$plist" 2>/dev/null
		launchctl bootout "gui/$(id -u)" "$plist" 2>/dev/null
		rm -f "$plist"
	fi
	# Only what the launchd step created in the real home.
	if [ -n "${launchd_owned:-}" ]; then rm -rf "$real_config" "$real_home/.keepiq"; fi
	cd / && rm -rf "$work"
}
trap cleanup EXIT

# Builds use the real home's Go caches; everything after runs with a throwaway
# home, so `keepiq login` cannot overwrite the user's own keepiq config.
step "build keepiq and the fake vault"
(cd "$cli" && go build -o "$work/keepiq" . && go build -o "$work/fakevault" ./internal/fakevault)
kq="$work/keepiq"
export HOME="$work/home"
mkdir -p "$HOME"
unset SSH_AUTH_SOCK SSH_AGENT_PID XDG_RUNTIME_DIR

step "keys: the vault's SSH key, a host key, and a key that is not in the vault"
ssh-keygen -q -t ed25519 -N '' -C "$key_name" -f "$work/vault_key"
ssh-keygen -q -t ed25519 -N '' -C host -f "$work/host_key"
ssh-keygen -q -t ed25519 -N '' -C other -f "$work/other_key"
cp "$work/vault_key.pub" "$work/authorized_keys"
chmod 600 "$work/authorized_keys"

step "throwaway sshd as $me"
sshd_bin="$(command -v sshd || true)"
[ -n "$sshd_bin" ] || sshd_bin=/usr/sbin/sshd
[ -x "$sshd_bin" ] || fail "no sshd at $sshd_bin"
if [ "$(id -u)" = 0 ]; then mkdir -p /run/sshd; fi
port=$((20000 + RANDOM % 20000))
cat >"$work/sshd_config" <<EOF
Port $port
ListenAddress 127.0.0.1
HostKey $work/host_key
AuthorizedKeysFile $work/authorized_keys
PidFile $work/sshd.pid
StrictModes no
UsePAM no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitRootLogin yes
EOF
"$sshd_bin" -D -e -f "$work/sshd_config" 2>"$work/sshd.log" &
pids+=($!)
for _ in $(seq 1 50); do
	if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then break; fi
	sleep 0.2
done
(exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null || {
	cat "$work/sshd.log"
	fail "sshd did not start on port $port"
}
echo "sshd listens on 127.0.0.1:$port"

step "fake Keepiq with the vault key filed as an ssh_key secret"
"$work/fakevault" -app-password "$app_password" -master "$master" -ssh-key "$work/vault_key" \
	-name "$key_name" -url-file "$work/url" 2>"$work/fakevault.log" &
pids+=($!)
for _ in $(seq 1 300); do
	[ -s "$work/url" ] && break
	sleep 0.2
done
[ -s "$work/url" ] || {
	cat "$work/fakevault.log"
	fail "the fake vault did not start"
}
url="$(cat "$work/url")"
# The private key now lives only in the vault: a login can only come from the agent.
rm -f "$work/vault_key"
echo "fake vault at $url"

printf '%s\n' "$app_password" | "$kq" login --url "$url" --user alice

ssh_opts=(-F /dev/null -o IdentityFile=none -o StrictHostKeyChecking=no
	-o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o BatchMode=yes -o ConnectTimeout=10 -p "$port")
login() { ssh "${ssh_opts[@]}" "$me@127.0.0.1" echo keepiq-agent-ok 2>&1; }
askpass="$work/askpass"
printf '#!/bin/sh\nprintf "%%s\\n" "$KQ_ASKPASS_ANSWER"\n' >"$askpass"
chmod 700 "$askpass"
with_askpass() { SSH_ASKPASS="$askpass" SSH_ASKPASS_REQUIRE=force DISPLAY=:0 KQ_ASKPASS_ANSWER="$1" "${@:2}"; }

step '1. eval "$(keepiq ssh-agent)" returns to the shell'
started=$(date +%s)
eval "$(printf '%s\n' "$master" | "$kq" ssh-agent)"
took=$(($(date +%s) - started))
echo "returned after ${took}s: SSH_AUTH_SOCK=$SSH_AUTH_SOCK SSH_AGENT_PID=$SSH_AGENT_PID"
[ -n "${SSH_AUTH_SOCK:-}" ] && [ -n "${SSH_AGENT_PID:-}" ] || fail "no SSH_AUTH_SOCK or SSH_AGENT_PID exported"
kill -0 "$SSH_AGENT_PID" || fail "the agent is not running after eval returned"
[ -S "$SSH_AUTH_SOCK" ] || fail "$SSH_AUTH_SOCK is not a socket"
mode() { perl -e 'printf "%o\n", (stat shift)[2] & 07777' "$1"; }
[ "$(mode "$SSH_AUTH_SOCK")" = 600 ] || fail "socket mode $(mode "$SSH_AUTH_SOCK"), want 600"
[ "$(mode "$(dirname "$SSH_AUTH_SOCK")")" = 700 ] || fail "socket folder mode $(mode "$(dirname "$SSH_AUTH_SOCK")"), want 700"
if [ "$(uname -s)" = Darwin ]; then
	want="${TMPDIR%/}/keepiq-$(id -u)/agent.sock"
	[ "$SSH_AUTH_SOCK" = "$want" ] || fail "default socket on macOS is $SSH_AUTH_SOCK, the README says $want"
fi
echo "socket $SSH_AUTH_SOCK is 0600 in a 0700 folder"

step "2. ssh-add -l lists the vault key"
listed="$(ssh-add -l)" || fail "ssh-add -l failed: $listed"
echo "$listed"
[ "$(printf '%s\n' "$listed" | wc -l | tr -d ' ')" = 1 ] || fail "want exactly one key (the login secret must be skipped)"
case "$listed" in *"$key_name"*ED25519*) ;; *) fail "ssh-add -l does not show '$key_name (ED25519)'" ;; esac
[ "$(ssh-keygen -lf "$work/vault_key.pub" | awk '{print $2}')" = "$(printf '%s\n' "$listed" | awk '{print $2}')" ] ||
	fail "the agent offers a different key than the one in the vault"

step "3. ssh and git clone over SSH with the vault key"
out="$(login)" || fail "ssh login failed: $out"
case "$out" in *keepiq-agent-ok*) echo "ssh: $out" ;; *) fail "unexpected ssh output: $out" ;; esac
git init -q --bare "$work/repo.git"
git -C "$work" init -q seed
git -C "$work/seed" -c user.name=e2e -c user.email=e2e@example.invalid commit -q --allow-empty -m "seed"
git -C "$work/seed" push -q "$work/repo.git" HEAD:refs/heads/main
GIT_SSH_COMMAND="ssh ${ssh_opts[*]}" git clone -q -b main --upload-pack "$(command -v git) upload-pack" \
	"ssh://$me@127.0.0.1:$port$work/repo.git" "$work/clone" || fail "git clone over SSH failed"
[ "$(git -C "$work/clone" log -1 --format=%s)" = seed ] || fail "the clone is not the repository"
echo "git clone over SSH works"

step "4. ssh-add -x locks the agent"
with_askpass lock-pw ssh-add -x || fail "ssh-add -x failed"
if listed="$(ssh-add -l 2>&1)"; then fail "a locked agent still lists keys: $listed"; fi
echo "ssh-add -l: $listed"
if out="$(login)"; then fail "a locked agent still logged in: $out"; fi
case "$out" in *"Permission denied"*) echo "ssh: $out" ;; *) fail "the locked login failed for another reason: $out" ;; esac
if with_askpass not-the-master ssh-add -X 2>/dev/null; then fail "ssh-add -X unlocked with a wrong password"; fi
echo "a wrong password does not unlock"

step "5. ssh-add -X with the master password unlocks it"
with_askpass "$master" ssh-add -X || fail "ssh-add -X with the master password failed"
listed="$(ssh-add -l)" || fail "no keys after unlock"
echo "$listed"
out="$(login)" || fail "ssh login after unlock failed: $out"
echo "ssh: $out"

step "6. keys from anywhere but the vault are refused"
if out="$(ssh-add "$work/other_key" 2>&1)"; then fail "ssh-add <file> was accepted: $out"; fi
echo "ssh-add <file>: $out"
if out="$(ssh-add -d "$work/vault_key.pub" 2>&1)"; then fail "ssh-add -d was accepted: $out"; fi
echo "ssh-add -d: $out"
if out="$(ssh-add -D 2>&1)"; then fail "ssh-add -D was accepted: $out"; fi
echo "ssh-add -D: $out"
[ "$(ssh-add -l | wc -l | tr -d ' ')" = 1 ] || fail "the agent's keys changed"
echo "still exactly the vault key"

step '7. kill $SSH_AGENT_PID stops the agent'
sock="$SSH_AUTH_SOCK"
kill "$SSH_AGENT_PID"
for _ in $(seq 1 50); do
	[ -e "$sock" ] || break
	sleep 0.1
done
[ ! -e "$sock" ] || fail "the socket is still there after kill"
unset SSH_AGENT_PID
echo "agent stopped, socket removed"

if [ "${KEEPIQ_E2E_LAUNCHD:-}" != 1 ] || [ "$(uname -s)" != Darwin ]; then
	echo
	echo "ssh-agent e2e: all checks passed"
	exit 0
fi

# --- launchd (macOS, KEEPIQ_E2E_LAUNCHD=1) ---------------------------------
step "8. the README's launchd plist"
awk '/^```xml/{f=1; next} /^```/{if (f) exit} f' "$cli/README.md" >"$work/readme.plist"
grep -q '<key>Label</key><string>nl.conduction.keepiq-agent</string>' "$work/readme.plist" ||
	fail "could not find the launchd plist in cli/README.md"
plutil -lint "$work/readme.plist"
args="$(plutil -extract ProgramArguments json -o - "$work/readme.plist")"
echo "ProgramArguments: $args"
[ "$args" = '["\/usr\/local\/bin\/keepiq","ssh-agent","--locked","--socket","\/Users\/YOU\/.keepiq\/agent.sock"]' ] ||
	[ "$args" = '["/usr/local/bin/keepiq","ssh-agent","--locked","--socket","/Users/YOU/.keepiq/agent.sock"]' ] ||
	fail "the README plist's ProgramArguments changed; update this check with it"

# The README's own steps, with the binary path and YOU filled in. They run in
# the real home, as launchd starts the agent there.
export HOME="$real_home"
label=nl.conduction.keepiq-agent
plist="$HOME/Library/LaunchAgents/$label.plist"
sock="$HOME/.keepiq/agent.sock"
real_config="$HOME/Library/Application Support/keepiq/config.json"
for f in "$plist" "$real_config" "$HOME/.keepiq"; do
	[ ! -e "$f" ] || fail "$f already exists; not overwriting it"
done
launchd_owned=1
mkdir -p "$HOME/Library/LaunchAgents"
sed -e "s#/usr/local/bin/keepiq#$kq#" -e "s#/Users/YOU#$HOME#" "$work/readme.plist" >"$plist"
plutil -lint "$plist"
printf '%s\n' "$app_password" | "$kq" login --url "$url" --user alice
mkdir -m 700 "$HOME/.keepiq"

loaded=""
if out="$(launchctl load "$plist" 2>&1)" && [ -z "$out" ]; then
	loaded="launchctl load"
else
	echo "launchctl load said: ${out:-(nothing, exit non-zero)}"
	if out="$(launchctl bootstrap "gui/$(id -u)" "$plist" 2>&1)"; then
		loaded="launchctl bootstrap gui/$(id -u)"
	else
		echo "launchctl bootstrap gui/$(id -u) said: $out"
	fi
fi
if [ -z "$loaded" ]; then
	echo "::warning::this runner cannot load a LaunchAgent; only plutil and ProgramArguments were checked"
	exit 0
fi
echo "loaded with: $loaded"
for _ in $(seq 1 100); do
	[ -S "$sock" ] && break
	sleep 0.1
done
launchctl print "gui/$(id -u)/$label" 2>&1 | grep -E '^\s*(state|pid|last exit code)' || true
[ -S "$sock" ] || fail "launchd started no agent on $sock"
export SSH_AUTH_SOCK="$sock"
if listed="$(ssh-add -l 2>&1)"; then fail "the --locked agent listed keys before unlock: $listed"; fi
echo "locked at load: $listed"
with_askpass "$master" ssh-add -X || fail "ssh-add -X on the launchd agent failed"
ssh-add -l
out="$(login)" || fail "ssh login through the launchd agent failed: $out"
echo "ssh: $out"

# KeepAlive: launchd starts the agent again when it stops.
first="$(launchctl list | awk -v l="$label" '$3 == l {print $1}')"
echo "launchd agent pid $first"
[ -n "$first" ] && [ "$first" != - ] || fail "launchctl list shows no pid for $label"
kill "$first"
second=""
for _ in $(seq 1 100); do
	second="$(launchctl list | awk -v l="$label" '$3 == l {print $1}')"
	if [ -n "$second" ] && [ "$second" != - ] && [ "$second" != "$first" ] && [ -S "$sock" ]; then break; fi
	sleep 0.2
done
[ -n "$second" ] && [ "$second" != - ] && [ "$second" != "$first" ] || fail "launchd did not restart the agent (KeepAlive)"
listed="$(ssh-add -l 2>&1)" && fail "a restarted agent must start locked: $listed"
echo "restarted as pid $second, locked again: $listed"

launchctl unload "$plist" 2>/dev/null || launchctl bootout "gui/$(id -u)" "$plist"
rm -f "$plist"
echo
echo "ssh-agent e2e and launchd: all checks passed"
