#!/usr/bin/env bash
#
# build-zip.sh — package inklura-seo into a WordPress-installable ZIP whose
# top-level folder is `inklura-seo/` (so WP installs/updates it into
# wp-content/plugins/inklura-seo/). Used to attach the asset to a release (the
# updater's download_url points at the published inklura-seo.zip).
#
#   bin/build-zip.sh [output.zip]     # default: ./inklura-seo.zip
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$REPO/inklura-seo.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/inklura-seo"

# Runtime files + the docs users want; no dev tooling.
rsync -a \
	--exclude '.git' \
	--exclude '.github' \
	--exclude 'tests' \
	--exclude 'bin' \
	--exclude 'docs' \
	--exclude 'dist' \
	--exclude 'node_modules' \
	--exclude 'vendor' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpcs.xml' \
	--exclude '.gitignore' \
	--exclude '*.zip' \
	"$REPO"/ "$STAGE/inklura-seo/"

rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OUT" inklura-seo )

VER="$(grep -m1 "Version:" "$REPO/inklura-seo.php" | tr -dc '0-9.')"
echo "Built $OUT (inklura-seo v$VER)"
unzip -l "$OUT" | tail -n +2 | head -12
