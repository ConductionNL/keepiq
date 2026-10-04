#!/bin/bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Entrypoint wrapper for the two-instance federation test
# (sharing-federated-recipients task 5.1).
#
# Each instance is reached as http://localhost:<its port> from the host, so
# that is its OCM identity and the host part of its users' cloud ids. For the
# two servers to reach each other under those same names, each container
# serves itself on SELF_PORT and proxies PEER_PORT on its own localhost to the
# other container, keeping the Host header. Then "localhost:8102" means
# instance B in the browser, in instance A and in instance B alike, and OCM
# signatures name the same origin everywhere.
set -euo pipefail

: "${SELF_PORT:?}" "${PEER_PORT:?}" "${PEER_HOST:?}"

if grep -q '^Listen 80$' /etc/apache2/ports.conf; then
	sed -i "s/^Listen 80$/Listen ${SELF_PORT}\nListen ${PEER_PORT}/" /etc/apache2/ports.conf
	sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${SELF_PORT}>/" /etc/apache2/sites-available/000-default.conf
	cat > /etc/apache2/sites-available/peer.conf <<CONF
<VirtualHost *:${PEER_PORT}>
	ProxyPreserveHost On
	ProxyPass / http://${PEER_HOST}:${PEER_PORT}/
	ProxyPassReverse / http://${PEER_HOST}:${PEER_PORT}/
</VirtualHost>
CONF
	a2enmod -q proxy proxy_http
	a2ensite -q peer
fi

exec /entrypoint.sh "$@"
