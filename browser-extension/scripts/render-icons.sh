#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

for cmd in rsvg-convert magick; do
	if ! command -v "$cmd" >/dev/null; then
		echo "render-icons: '$cmd' not found; install librsvg and ImageMagick 7" >&2
		exit 1
	fi
done

# The hexagon's bounds in ../img/app-store.svg at 1024 px; fix when the hexagon moves.
icon() { rsvg-convert -w 1024 -h 1024 ../img/app-store.svg | magick - -crop 920x920+52+52 +repage -resize ${1}x$1 -strip PNG32:$2; }
for s in 16 32 48 96 128; do icon $s public/icon/$s.png; done
for s in 16 32; do
	icon $s public/icon/toolbar/$s.png
	rsvg-convert -w $s -h $s assets/toolbar-lock.svg | magick public/icon/toolbar/$s.png - -composite -strip PNG32:public/icon/toolbar-locked/$s.png
	magick public/icon/toolbar/$s.png -colorspace Gray -strip PNG32:public/icon/toolbar-logged-out/$s.png
done
